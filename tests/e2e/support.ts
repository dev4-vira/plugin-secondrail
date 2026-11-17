/**
 * Helpers for the real-sandbox run. Order state and SecondRail reads go through bin/wp, using the
 * plugin's own API client: the key never reaches this process.
 */
import { execFileSync } from 'node:child_process'
import { resolve } from 'node:path'
import { expect, type Locator, type Page } from '@playwright/test'

const ROOT = resolve(import.meta.dirname, '..', '..')

export const CARDS = { ok: '4242424242424242', decline: '4000000000000002' } as const

export const sleep = (ms: number) => new Promise((done) => setTimeout(done, ms))

/** Runs PHP in WordPress (bin/wp eval) and returns the last line printed. */
export function wpEval(code: string): string {
  const out = execFileSync(resolve(ROOT, 'bin', 'wp'), ['eval', code], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
  return out.trim().split('\n').pop() ?? ''
}

/** GET on the SecondRail API with the plugin's test-mode client. */
export function api<T>(path: string): T {
  return JSON.parse(wpEval(`echo wp_json_encode( \\SecondRail\\WooCommerce\\Gateway::client( 'test' )->get( ${JSON.stringify(path)} ) );`)) as T
}

export interface OrderState {
  id: number
  status: string
  transaction_id: string
  number: string
  key: string
  refunded: number
  link: string
  payment: string
  notes: string[]
}

export function order(id: number): OrderState {
  return JSON.parse(
    wpEval(`
      $o = wc_get_order( ${Number(id)} );
      echo wp_json_encode( array(
        'id' => $o->get_id(),
        'status' => $o->get_status(),
        'transaction_id' => $o->get_transaction_id(),
        'number' => (string) $o->get_order_number(),
        'key' => $o->get_order_key(),
        'refunded' => (float) $o->get_total_refunded(),
        'link' => (string) $o->get_meta( '_secondrail_link_id' ),
        'payment' => (string) $o->get_meta( '_secondrail_payment_id' ),
        'notes' => array_map( function ( $n ) { return $n->content; }, wc_get_order_notes( array( 'order_id' => $o->get_id(), 'order' => 'ASC' ) ) ),
      ) );
    `),
  ) as OrderState
}

export function productId(): number {
  return Number(wpEval(`echo get_page_by_path( 'secondrail-test-product', OBJECT, 'product' )->ID;`))
}

/** Waits until a condition on the order holds (webhook and scheduled paths are asynchronous). */
export async function waitForOrder(id: number, check: (state: OrderState) => boolean, timeoutMs = 90_000): Promise<OrderState> {
  const started = Date.now()
  for (;;) {
    const state = order(id)
    if (check(state)) return state
    if (Date.now() - started > timeoutMs) return state
    await sleep(3000)
  }
}

const BILLING = { first: 'Ada', last: 'Tester', address: '1 Market St', city: 'San Francisco', postcode: '94105', phone: '4155550100' }

export async function addToCart(page: Page) {
  await page.goto(`/?add-to-cart=${productId()}`)
}

/** Places the order on the classic checkout page and waits for the hosted page. */
export async function placeClassic(page: Page, email: string) {
  await page.goto('/classic-checkout/')
  await page.locator('#billing_first_name').fill(BILLING.first)
  await page.locator('#billing_last_name').fill(BILLING.last)
  await page.evaluate(() => {
    const $ = (window as unknown as { jQuery: (s: string) => { val: (v: string) => { trigger: (e: string) => void } } }).jQuery
    $('#billing_country').val('US').trigger('change')
    $('#billing_state').val('CA').trigger('change')
  })
  await page.locator('#billing_address_1').fill(BILLING.address)
  await page.locator('#billing_city').fill(BILLING.city)
  await page.locator('#billing_postcode').fill(BILLING.postcode)
  await page.locator('#billing_phone').fill(BILLING.phone)
  await page.locator('#billing_email').fill(email)
  await expect(page.locator('#payment_method_secondrail')).toBeChecked()
  await page.locator('#place_order').click()
  await page.waitForURL(/\/pay\/p\//, { timeout: 60_000 })
}

/** Places the order on the block checkout page and waits for the hosted page. */
export async function placeBlock(page: Page, email: string) {
  await page.goto('/checkout/')
  await page.locator('#email').fill(email)
  await page.locator('#billing-first_name').fill(BILLING.first)
  await page.locator('#billing-last_name').fill(BILLING.last)
  await page.locator('#billing-address_1').fill(BILLING.address)
  await page.locator('#billing-city').fill(BILLING.city)
  const state = page.locator('#billing-state')
  if ((await state.evaluate((el) => el.tagName)) === 'SELECT') await state.selectOption('CA')
  else await state.fill('California')
  await page.locator('#billing-postcode').fill(BILLING.postcode)
  const phone = page.locator('#billing-phone')
  if (await phone.count()) await phone.fill(BILLING.phone)
  await page.locator('.wc-block-components-checkout-place-order-button').click()
  await page.waitForURL(/\/pay\/p\//, { timeout: 60_000 })
}

/** Fills the hosted card form (the platform's fillCardForm: three Basis Theory iframes). */
export async function fillCardForm(scope: Page | Locator, card: string): Promise<Locator> {
  const form = scope.getByTestId('card-form')
  await expect(form).toBeVisible()
  await form.locator('iframe').nth(2).waitFor({ timeout: 45_000 })
  const values = [card, '12/34', '123']
  for (let i = 0; i < 3; i++) {
    const input = form.frameLocator('iframe').nth(i).locator('input')
    await input.waitFor({ timeout: 30_000 })
    await input.pressSequentially(values[i]!, { delay: 20 })
  }
  const submit = form.getByTestId('card-submit')
  await expect(submit).toBeEnabled({ timeout: 20_000 })
  return submit
}

/** Pays on the hosted page and waits for the store's order-received page. */
export async function payAndReturn(page: Page, card: string = CARDS.ok): Promise<number> {
  await (await fillCardForm(page, card)).click()
  await page.waitForURL(/order-received/, { timeout: 120_000 })
  return orderIdFrom(page.url())
}

export function orderIdFrom(url: string): number {
  const match = /order-received\/(\d+)/.exec(url) ?? /[?&]order-received=(\d+)/.exec(url) ?? /[?&]order_id=(\d+)/.exec(url)
  if (!match) throw new Error('No order id in the URL')
  return Number(match[1])
}

/** The latest order's id. */
export function latestOrderId(): number {
  return Number(wpEval(`$ids = wc_get_orders( array( 'limit' => 1, 'orderby' => 'id', 'order' => 'DESC', 'return' => 'ids' ) ); echo $ids[0];`))
}
