<?php
/**
 * R5: return from the hosted page.
 *
 * @package SecondRail\WooCommerce\Tests
 */

namespace SecondRail\WooCommerce\Tests;

use SecondRail\WooCommerce\Webhook;

/**
 * R5.1–R5.5.
 */
class Test_Return extends Test_Case {

	/**
	 * Where the return handler sends the browser.
	 *
	 * @param array $query Query string.
	 * @return string
	 */
	private function target( array $query ) {
		return Webhook::return_target( $query );
	}

	/**
	 * R5.1: a wrong key: no API request, the shop page.
	 */
	public function test_r5_1_wrong_key() {
		$order = $this->order();
		$this->assertSame(
			wc_get_page_permalink( 'shop' ),
			$this->target(
				array(
					'order_id' => (string) $order->get_id(),
					'key'      => 'wc_order_wrong',
				)
			)
		);
		$this->assertSame( wc_get_page_permalink( 'shop' ), $this->target( array( 'order_id' => '999999' ) ) );
		$this->assertSame( array(), $this->http->requests );
	}

	/**
	 * R5.2: a succeeded payment: paid, order-received.
	 */
	public function test_r5_2_succeeded() {
		$order = $this->order();
		$this->list_payments( array( $this->payment( $order ) ) );
		$target = $this->target(
			array(
				'order_id' => (string) $order->get_id(),
				'key'      => $order->get_order_key(),
			)
		);
		$order  = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( $order->get_checkout_order_received_url(), $target );
	}

	/**
	 * R5.3: only a failed payment: one notice, checkout.
	 */
	public function test_r5_3_failed() {
		$order = $this->order();
		$this->list_payments( array( $this->payment( $order, 'failed' ) ) );
		$target = $this->target(
			array(
				'order_id' => (string) $order->get_id(),
				'key'      => $order->get_order_key(),
			)
		);
		$this->assertSame( wc_get_checkout_url(), $target );
		$this->assertSame( array( 'The payment was not completed. You can try again.' ), $this->error_notices() );
	}

	/**
	 * R5.4: the query's status is not a fact.
	 */
	public function test_r5_4_query_status_is_ignored() {
		$order   = $this->order();
		$payment = $this->payment( $order, 'failed' );
		$this->list_payments( array( $payment ) );
		$this->target(
			array(
				'order_id'   => (string) $order->get_id(),
				'key'        => $order->get_order_key(),
				'payment_id' => $payment['id'],
				'status'     => 'succeeded',
			)
		);
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	/**
	 * R5.6: the webhook holds the order's lock: order-received, no request, order untouched.
	 */
	public function test_r5_6_lock_held() {
		$order = $this->order();
		set_transient( 'secondrail_wc_lock_' . $order->get_id(), 1, 60 );
		$target = $this->target(
			array(
				'order_id' => (string) $order->get_id(),
				'key'      => $order->get_order_key(),
			)
		);
		delete_transient( 'secondrail_wc_lock_' . $order->get_id() );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $order->get_checkout_order_received_url(), $target );
		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( array(), $this->notes( $order ) );
	}

	/**
	 * R5.7: a charge still in flight: order-received with no error notice, still pending.
	 */
	public function test_r5_7_charge_in_flight() {
		$order = $this->order();
		$this->list_payments( array( $this->payment( $order, 'processing', array( 'created_at' => $this->ago( 3 ) ) ) ) );
		$target = $this->target(
			array(
				'order_id' => (string) $order->get_id(),
				'key'      => $order->get_order_key(),
			)
		);
		$order  = wc_get_order( $order->get_id() );
		$this->assertSame( $order->get_checkout_order_received_url(), $target );
		$this->assertSame( array(), $this->error_notices() );
		$this->assertSame( 'pending', $order->get_status() );
	}

	/**
	 * R5.5: the API is down: order-received, still pending.
	 */
	public function test_r5_5_api_down() {
		$order = $this->order();
		$this->http->raw( 'GET', '/payments', 0, new \WP_Error( 'http_request_failed', 'down' ) );
		$target = $this->target(
			array(
				'order_id' => (string) $order->get_id(),
				'key'      => $order->get_order_key(),
			)
		);
		$order  = wc_get_order( $order->get_id() );
		$this->assertSame( $order->get_checkout_order_received_url(), $target );
		$this->assertSame( 'pending', $order->get_status() );
	}
}
