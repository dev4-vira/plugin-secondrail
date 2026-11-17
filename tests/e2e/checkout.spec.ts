/**
 * R11 · Real sandbox: classic and block checkout, decline and retry, cancel and return, webhook
 * only, refund, and a forged return. Serial: R11.6 and R11.7 use the order of R11.1.
 * Logs ids and statuses only.
 */
import { expect, test } from '@playwright/test'
import { CARDS, addToCart, api, fillCardForm, latestOrderId, order, payAndReturn, placeBlock, placeClassic, waitForOrder, wpEval } from './support'

interface Payment {
  id: string
  status: string
  external_id: string | null
}

const run = Date.now().toString(36)
let first = { order: 0, payment: '' }

function expectPaid(id: number) {
  const state = order(id)
  expect(state.status).toBe('processing')
  expect(state.transaction_id).toMatch(/^pay_/)
  const payment = api<Payment>(`/payments/${state.transaction_id}`)
  expect(payment.status).toBe('succeeded')
  expect(payment.external_id).toBe(state.number)
  return { state, payment }
}

/**
 * R11.8: one status change, Pending payment → Processing. WooCommerce's payment_complete() writes
 * it as "Payment via …" and no "Order status changed" note; every other change writes one.
 */
function expectStraightToProcessing(label: string, id: number) {
  const state = order(id)
  const changes = state.notes.filter((n) => n.startsWith('Order status changed'))
  const paidNotes = state.notes.filter((n) => n.startsWith('Payment via '))
  const waiting = state.notes.filter((n) => n.startsWith('Waiting for SecondRail'))
  console.log(`R11.8 ${label} order=${id} status=${state.status} payment_via=${paidNotes.length} status_changes=${JSON.stringify(changes)} waiting_notes=${waiting.length}`)
  expect(state.status).toBe('processing')
  expect(changes).toEqual([])
  expect(paidNotes).toHaveLength(1)
  expect(waiting).toEqual([])
}

test.describe.serial('R11 real sandbox', () => {
  test('R11.1 classic checkout pays and returns to order-received', async ({ page }) => {
    await addToCart(page)
    await placeClassic(page, `r11-1-${run}@example.com`)
    const id = await payAndReturn(page)
    await expect(page.locator('.woocommerce-order, .wc-block-order-confirmation-status').first()).toBeVisible()
    const { state, payment } = expectPaid(id)
    first = { order: id, payment: payment.id }
    console.log(`R11.1 order=${id} status=${state.status} payment=${payment.id} payment_status=${payment.status} link=${state.link}`)
    expectStraightToProcessing('R11.1', id)
  })

  test('R11.2 block checkout pays and returns to order-received', async ({ page }) => {
    await addToCart(page)
    await placeBlock(page, `r11-2-${run}@example.com`)
    const id = await payAndReturn(page)
    const { state, payment } = expectPaid(id)
    console.log(`R11.2 order=${id} status=${state.status} payment=${payment.id} payment_status=${payment.status} link=${state.link}`)
    expectStraightToProcessing('R11.2', id)
  })

  test('R11.3 decline, then another card on the same page', async ({ page }) => {
    await addToCart(page)
    await placeClassic(page, `r11-3-${run}@example.com`)
    await (await fillCardForm(page, CARDS.decline)).click()
    const retry = page.getByTestId('hosted-try-again')
    await expect(retry).toBeVisible({ timeout: 90_000 })
    await retry.click()
    const id = await payAndReturn(page, CARDS.ok)
    const paid = expectPaid(id)
    const state = await waitForOrder(id, (s) => s.notes.some((n) => /^Payment pay_\S+ failed:/.test(n)), 60_000)
    const failures = state.notes.filter((n) => /^Payment pay_\S+ failed:/.test(n))
    expect(failures).toHaveLength(1)
    expect(state.notes.filter((n) => n.startsWith('SecondRail payment pay_'))).toHaveLength(1)
    const payments = api<Payment[]>(`/payments?external_id=${state.number}&limit=100`)
    console.log(
      `R11.3 order=${id} status=${state.status} paid_with=${paid.payment.id} payments=${payments.map((p) => `${p.id}:${p.status}`).join(',')} failure_notes=${failures.length}`,
    )
    expectStraightToProcessing('R11.3', id)
  })

  test('R11.4 cancel and return keeps the cart; paying again revokes the first link', async ({ page }) => {
    await addToCart(page)
    await placeBlock(page, `r11-4-${run}@example.com`)
    const id = latestOrderId()
    const firstLink = order(id).link
    await page.getByTestId('hosted-cancel').click()
    await page.waitForURL(/\/checkout\//)
    await expect(page.locator('.wc-block-components-order-summary-item').first()).toBeVisible()
    expect(order(id).status).toBe('pending')
    await page.locator('.wc-block-components-checkout-place-order-button').click()
    await page.waitForURL(/\/pay\/p\//, { timeout: 60_000 })
    const again = await payAndReturn(page)
    expect(again).toBe(id)
    const { state } = expectPaid(id)
    expect(state.link).not.toBe(firstLink)
    const link = api<{ id: string; status: string }>(`/action_links/${firstLink}`)
    expect(link.status).toBe('revoked')
    console.log(`R11.4 order=${id} status=${state.status} first_link=${firstLink}:${link.status} second_link=${state.link}`)
  })

  test('R11.5 the webhook alone pays the order when the return never arrives', async ({ page }) => {
    let returnUrl = ''
    await page.route(
      (url) => url.href.includes('secondrail_return'),
      (route) => {
        returnUrl = route.request().url()
        return route.abort()
      },
    )
    await addToCart(page)
    await placeClassic(page, `r11-5-${run}@example.com`)
    const id = latestOrderId()
    await (await fillCardForm(page, CARDS.ok)).click()
    await expect.poll(() => returnUrl, { timeout: 90_000 }).not.toBe('')
    expect(new URL(returnUrl).searchParams.get('order_id')).toBe(String(id))
    const started = Date.now()
    const state = await waitForOrder(id, (s) => s.status === 'processing', 90_000)
    expect(state.status).toBe('processing')
    console.log(`R11.5 order=${id} status=${state.status} payment=${state.transaction_id} seconds=${Math.round((Date.now() - started) / 1000)}`)
    expectStraightToProcessing('R11.5', id)
  })

  test('R11.6 a refund from WooCommerce reaches SecondRail', async () => {
    expect(first.order).toBeGreaterThan(0)
    const result = JSON.parse(
      wpEval(`
        $refund = wc_create_refund( array( 'order_id' => ${first.order}, 'amount' => '5.00', 'reason' => 'R11.6', 'refund_payment' => true ) );
        echo wp_json_encode( is_wp_error( $refund ) ? array( 'error' => $refund->get_error_message() ) : array( 'id' => $refund->get_id() ) );
      `),
    ) as { id?: number; error?: string }
    expect(result.error).toBeUndefined()
    let refunds: { id: string; amount: number; status: string }[] = []
    const started = Date.now()
    do {
      refunds = api<typeof refunds>(`/refunds?payment=${first.payment}`)
      if (refunds.some((r) => r.status === 'succeeded')) break
      await new Promise((done) => setTimeout(done, 3000))
    } while (Date.now() - started < 90_000)
    expect(refunds).toHaveLength(1)
    expect(refunds[0]).toMatchObject({ amount: 500, status: 'succeeded' })
    const state = order(first.order)
    expect(state.refunded).toBe(5)
    console.log(`R11.6 order=${first.order} refund=${refunds[0]!.id} amount=${refunds[0]!.amount} status=${refunds[0]!.status} wc_refunded=${state.refunded}`)
  })

  test('R11.7 a forged return pays nothing', async ({ page }) => {
    expect(first.payment).toMatch(/^pay_/)
    await addToCart(page)
    await placeClassic(page, `r11-7-${run}@example.com`)
    const id = latestOrderId()
    const victim = order(id)
    const forged = `/?wc-api=secondrail_return&order_id=${id}&key=${victim.key}&payment_id=${first.payment}&status=succeeded`
    await page.goto(forged)
    await page.waitForLoadState('load')
    const state = order(id)
    expect(state.status).toBe('pending')
    expect(state.transaction_id).toBe('')
    console.log(`R11.7 order=${id} status=${state.status} forged_payment=${first.payment}`)
  })
})
