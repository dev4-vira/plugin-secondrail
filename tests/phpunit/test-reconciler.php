<?php
/**
 * R4: order state from the API, and the scheduled check.
 *
 * @package SecondRail\WooCommerce\Tests
 */

namespace SecondRail\WooCommerce\Tests;

use SecondRail\WooCommerce\Reconciler;

/**
 * R4.1–R4.10.
 */
class Test_Reconciler extends Test_Case {

	/**
	 * Fresh copy of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return \WC_Order
	 */
	private function reload( $order ) {
		return wc_get_order( $order->get_id() );
	}

	/**
	 * R4.1: a matching succeeded payment pays the order once.
	 */
	public function test_r4_1_succeeded_pays_the_order_once() {
		$order   = $this->order();
		$payment = $this->payment( $order );
		$this->list_payments( array( $payment ) );
		$this->assertSame( 'paid', Reconciler::reconcile( $order ) );
		$order = $this->reload( $order );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( $payment['id'], $order->get_transaction_id() );
		$this->assertSame( $payment['id'], $order->get_meta( '_secondrail_payment_id' ) );
		$this->assertCount( 1, $this->notes( $order, 'SecondRail payment ' . $payment['id'] ) );
		$this->assertStringContainsString( '4242', $this->notes( $order, 'SecondRail payment' )[0] );
		$request = $this->http->requests[0];
		$this->assertSame( array( 'external_id' => (string) $order->get_order_number(), 'limit' => '100' ), $request['query'] );
		$before = count( $this->notes( $order ) );
		$this->list_payments( array( $payment ) );
		$this->assertSame( 'paid', Reconciler::reconcile( $order ) );
		$this->assertCount( $before, $this->notes( $this->reload( $order ) ) );
	}

	/**
	 * R4.2: another amount puts the order on hold for review.
	 */
	public function test_r4_2_amount_mismatch_is_review() {
		$order = $this->order();
		$this->list_payments( array( $this->payment( $order, 'succeeded', array( 'amount' => 999 ) ) ) );
		$this->assertSame( 'review', Reconciler::reconcile( $order ) );
		$order = $this->reload( $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
		$this->assertCount( 1, $this->notes( $order, 'Paid amount differs from the order total. Review before fulfilling.' ) );
	}

	/**
	 * R4.3: two succeeded payments: the earliest pays, the other gets one note.
	 */
	public function test_r4_3_two_succeeded_payments() {
		$order  = $this->order();
		$first  = $this->payment( $order );
		$second = $this->payment( $order );
		$this->list_payments( array( $second, $first ) );
		$this->assertSame( 'paid', Reconciler::reconcile( $order ) );
		$order = $this->reload( $order );
		$this->assertSame( $first['id'], $order->get_transaction_id() );
		$text = 'Second successful payment ' . $second['id'] . ' for this order. Refund it in SecondRail.';
		$this->assertCount( 1, $this->notes( $order, $text ) );
		$this->list_payments( array( $second, $first ) );
		Reconciler::reconcile( $order );
		$this->assertCount( 1, $this->notes( $this->reload( $order ), $text ) );
	}

	/**
	 * R4.4: unknown, then failed: on hold, then failed, with one failure note.
	 */
	public function test_r4_4_unknown_then_failed() {
		$order   = $this->order();
		$payment = $this->payment( $order, 'unknown' );
		$this->list_payments( array( $payment ) );
		$this->assertSame( 'waiting', Reconciler::reconcile( $order ) );
		$order = $this->reload( $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertCount( 1, $this->notes( $order, 'Waiting for SecondRail to confirm the payment.' ) );
		$failed = array_merge( $payment, array( 'status' => 'failed', 'error' => array( 'message' => 'Card declined' ) ) );
		$this->list_payments( array( $failed ) );
		$this->assertSame( 'unpaid', Reconciler::reconcile( $order ) );
		$order = $this->reload( $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_secondrail_waiting' ) );
		$this->assertCount( 1, $this->notes( $order, 'Payment ' . $payment['id'] . ' failed: Card declined' ) );
	}

	/**
	 * R4.5: a failed payment on a pending order: still pending, one note.
	 */
	public function test_r4_5_failed_on_pending_order() {
		$order   = $this->order();
		$payment = $this->payment( $order, 'failed' );
		$this->list_payments( array( $payment ) );
		$this->assertSame( 'unpaid', Reconciler::reconcile( $order ) );
		$this->list_payments( array( $payment ) );
		Reconciler::reconcile( $order );
		$order = $this->reload( $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertCount( 1, $this->notes( $order, 'Payment ' . $payment['id'] . ' failed: Card declined' ) );
	}

	/**
	 * R4.6: payments of another site or another order are ignored.
	 */
	public function test_r4_6_foreign_payments_are_ignored() {
		$order = $this->order();
		$this->list_payments(
			array(
				$this->payment( $order, 'succeeded', array( 'metadata' => array( 'wc_site' => 'other-site' ) ) ),
				$this->payment( $order, 'succeeded', array( 'metadata' => array( 'wc_order_id' => '999999' ) ) ),
			)
		);
		$this->assertSame( 'unpaid', Reconciler::reconcile( $order ) );
		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	/**
	 * R4.7: an order of another gateway is not read nor touched.
	 */
	public function test_r4_7_other_gateway_is_untouched() {
		$order = $this->order( '19.99', 'bacs' );
		$this->assertSame( 'ignored', Reconciler::reconcile( $order ) );
		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	/**
	 * R4.8: an authorized payment puts the order on hold with its note.
	 */
	public function test_r4_8_authorized() {
		$order = $this->order();
		$this->list_payments( array( $this->payment( $order, 'authorized' ) ) );
		$this->assertSame( 'waiting', Reconciler::reconcile( $order ) );
		$order = $this->reload( $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'yes', $order->get_meta( '_secondrail_waiting' ) );
		$this->assertCount( 1, $this->notes( $order, 'Authorized in SecondRail; capture it there.' ) );
	}

	/**
	 * R4.9: a succeeded payment pays an order WooCommerce already cancelled.
	 */
	public function test_r4_9_cancelled_order_is_paid() {
		$order = $this->order();
		$order->update_status( 'cancelled' );
		$this->list_payments( array( $this->payment( $order ) ) );
		$this->assertSame( 'paid', Reconciler::reconcile( $this->reload( $order ) ) );
		$this->assertTrue( $this->reload( $order )->is_paid() );
	}

	/**
	 * R4: the reconciler uses the key of the order's mode, not the current one.
	 */
	public function test_r4_uses_the_order_mode_key() {
		update_option( 'woocommerce_secondrail_settings', $this->settings( array( 'testmode' => 'no' ) ) );
		$order = $this->order();
		$this->list_payments( array() );
		Reconciler::reconcile( $order );
		$this->assertSame( 'Bearer sk_test_unit_key_0001', $this->http->requests[0]['headers']['Authorization'] );
	}

	/**
	 * Pending checks for an order.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	private function pending_checks( $order_id ) {
		$found = array();
		foreach ( as_get_scheduled_actions(
			array(
				'hook'     => Reconciler::ACTION,
				'group'    => Reconciler::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 50,
			)
		) as $action ) {
			$args = $action->get_args();
			if ( (int) $args['order_id'] === (int) $order_id ) {
				$found[] = $args;
			}
		}
		return $found;
	}

	/**
	 * Marks the order's pending check complete, as the Action Scheduler runner does before running it.
	 *
	 * @param int $order_id Order id.
	 */
	private function claim_pending( $order_id ) {
		$ids = as_get_scheduled_actions(
			array(
				'hook'   => Reconciler::ACTION,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		foreach ( $ids as $id ) {
			$args = \ActionScheduler::store()->fetch_action( $id )->get_args();
			if ( (int) $args['order_id'] === (int) $order_id ) {
				\ActionScheduler::store()->mark_complete( $id );
			}
		}
	}

	/**
	 * R4.10: one pending check per order; with `waiting` it runs four times in all.
	 */
	public function test_r4_10_scheduled_check_backs_off_and_stops() {
		$order = $this->order();
		Reconciler::schedule( $order->get_id() );
		Reconciler::schedule( $order->get_id() );
		$this->assertSame( array( array( 'order_id' => $order->get_id(), 'run' => 0 ) ), $this->pending_checks( $order->get_id() ) );
		$payment = $this->payment( $order, 'processing' );
		for ( $run = 0; $run < 4; $run++ ) {
			$this->list_payments( array( $payment ) );
			$this->claim_pending( $order->get_id() );
			Reconciler::run_check( $order->get_id(), $run );
			$next = $this->pending_checks( $order->get_id() );
			if ( $run < 3 ) {
				$this->assertSame( array( array( 'order_id' => $order->get_id(), 'run' => $run + 1 ) ), $next, "after run $run" );
			} else {
				$this->assertSame( array(), $next, 'nothing after the fourth run' );
			}
		}
		$this->assertCount( 4, $this->http->seen( 'GET', '/payments' ) );
	}

	/**
	 * R4.10: a paid result schedules nothing more; an API failure retries.
	 */
	public function test_r4_10_paid_stops_and_api_error_retries() {
		$order = $this->order();
		$this->list_payments( array( $this->payment( $order ) ) );
		Reconciler::run_check( $order->get_id(), 0 );
		$this->assertSame( array(), $this->pending_checks( $order->get_id() ) );
		$other = $this->order();
		$this->http->error( 'GET', '/payments', 503, 'provider_unavailable' );
		Reconciler::run_check( $other->get_id(), 1 );
		$this->assertSame( array( array( 'order_id' => $other->get_id(), 'run' => 2 ) ), $this->pending_checks( $other->get_id() ) );
	}

	/**
	 * Rows 1 and 2 clear the waiting flag; calling twice changes nothing.
	 */
	public function test_r4_waiting_flag_cleared_when_paid() {
		$order   = $this->order();
		$payment = $this->payment( $order, 'processing' );
		$this->list_payments( array( $payment ) );
		Reconciler::reconcile( $order );
		$this->list_payments( array( array_merge( $payment, array( 'status' => 'succeeded' ) ) ) );
		$this->assertSame( 'paid', Reconciler::reconcile( $this->reload( $order ) ) );
		$order = $this->reload( $order );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( '', $order->get_meta( '_secondrail_waiting' ) );
	}

	/**
	 * R4.11: with the order's lock held, `busy`, no request, the order untouched.
	 */
	public function test_r4_11_lock_held_is_busy() {
		$order = $this->order();
		set_transient( 'secondrail_wc_lock_' . $order->get_id(), 1, 60 );
		try {
			Reconciler::reconcile( $order );
			$this->fail( 'Api_Error busy expected' );
		} catch ( \SecondRail\WooCommerce\Api_Error $error ) {
			$this->assertSame( 'busy', $error->error_code );
		}
		$this->assertSame( array(), $this->http->requests );
		$order = $this->reload( $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( array(), $this->notes( $order ) );
		delete_transient( 'secondrail_wc_lock_' . $order->get_id() );
	}

	/**
	 * R4.11: the lock is free after a normal run and after a run in which the API failed.
	 */
	public function test_r4_11_lock_released() {
		$order = $this->order();
		$lock  = 'secondrail_wc_lock_' . $order->get_id();
		$this->list_payments( array( $this->payment( $order, 'failed' ) ) );
		Reconciler::reconcile( $order );
		$this->assertFalse( get_transient( $lock ) );
		$this->http->error( 'GET', '/payments', 503, 'provider_unavailable' );
		try {
			Reconciler::reconcile( $order );
			$this->fail( 'Api_Error expected' );
		} catch ( \SecondRail\WooCommerce\Api_Error $error ) {
			$this->assertSame( 'provider_unavailable', $error->error_code );
		}
		$this->assertFalse( get_transient( $lock ) );
	}

	/**
	 * R4.11: a copy loaded before another run paid the order does not complete it again.
	 */
	public function test_r4_11_stale_copy_does_not_complete_twice() {
		$order   = $this->order();
		$stale   = $this->reload( $order );
		$payment = $this->payment( $order );
		$this->list_payments( array( $payment ) );
		Reconciler::reconcile( $order );
		$this->list_payments( array( $payment ) );
		$this->assertSame( 'paid', Reconciler::reconcile( $stale ) );
		$order = $this->reload( $order );
		$this->assertCount( 1, $this->notes( $order, 'SecondRail payment ' . $payment['id'] ) );
		$this->assertCount( 1, $this->notes( $order, 'Payment complete.' ) );
	}

	/**
	 * R4.12: a charge in flight is open; an old or unreadable `processing` is waiting.
	 */
	public function test_r4_12_processing_by_age() {
		$order   = $this->order();
		$payment = $this->payment( $order, 'processing', array( 'created_at' => $this->ago( 10 ) ) );
		$this->list_payments( array( $payment ) );
		$open = array();
		$this->assertSame( 'open', Reconciler::reconcile( $order, $open ) );
		$this->assertSame( array( $payment['id'] ), $open );
		$order = $this->reload( $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( array(), $this->notes( $order ) );
		$this->assertSame( '', $order->get_meta( '_secondrail_waiting' ) );

		$this->list_payments( array( array_merge( $payment, array( 'created_at' => $this->ago( 180 ) ) ) ) );
		$this->assertSame( 'waiting', Reconciler::reconcile( $order ) );
		$this->assertSame( 'on-hold', $this->reload( $order )->get_status() );

		$other = $this->order();
		$this->list_payments( array( $this->payment( $other, 'processing', array( 'created_at' => 'not a date' ) ) ) );
		$this->assertSame( 'waiting', Reconciler::reconcile( $other ) );
		$this->assertSame( 'on-hold', $this->reload( $other )->get_status() );
	}

	/**
	 * R4.13: in flight, then failed: never on hold, never Failed, one failure note.
	 */
	public function test_r4_13_in_flight_then_failed_stays_pending() {
		$order   = $this->order();
		$payment = $this->payment( $order, 'processing', array( 'created_at' => $this->ago( 2 ) ) );
		$this->list_payments( array( $payment ) );
		Reconciler::reconcile( $order );
		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
		$failed = array_merge( $payment, array( 'status' => 'failed', 'error' => array( 'message' => 'Card declined' ) ) );
		$this->list_payments( array( $failed ) );
		$this->assertSame( 'unpaid', Reconciler::reconcile( $order ) );
		$order = $this->reload( $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertCount( 1, $this->notes( $order, 'Payment ' . $payment['id'] . ' failed: Card declined' ) );
		$this->assertSame( array(), $this->notes( $order, 'Waiting for SecondRail' ) );
		$this->assertSame( array(), $this->notes( $order, 'On hold' ) );
	}

	/**
	 * Row 5: an open payment changes nothing.
	 */
	public function test_r4_open_payment() {
		$order = $this->order();
		$this->list_payments( array( $this->payment( $order, 'requires_action' ) ) );
		$this->assertSame( 'open', Reconciler::reconcile( $order ) );
		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}
}
