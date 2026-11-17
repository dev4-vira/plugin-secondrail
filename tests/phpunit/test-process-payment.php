<?php
/**
 * R3: checkout through `process_payment`.
 *
 * @package SecondRail\WooCommerce\Tests
 */

namespace SecondRail\WooCommerce\Tests;

use SecondRail\WooCommerce\Gateway;
use SecondRail\WooCommerce\Reconciler;

/**
 * R3.1–R3.10.
 */
class Test_Process_Payment extends Test_Case {

	/**
	 * Link as the API returns it.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $id    Link id.
	 * @param array     $over  Overrides.
	 * @return array
	 */
	private function link( $order, $id = 'lnk_new', array $over = array() ) {
		return array_merge(
			array(
				'id'          => $id,
				'object'      => 'action_link',
				'url'         => 'https://pay.secondrail.test/p/' . $id,
				'status'      => 'active',
				'auto_return' => true,
				'external_id' => (string) $order->get_order_number(),
			),
			$over
		);
	}

	/**
	 * Runs `process_payment`.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	private function pay( $order ) {
		return ( new Gateway() )->process_payment( $order->get_id() );
	}

	/**
	 * An order that already opened a link.
	 *
	 * @return \WC_Order
	 */
	private function second_submit_order() {
		return $this->order(
			'19.99',
			'secondrail',
			array(
				'_secondrail_link_id' => 'lnk_old',
				'_secondrail_attempt' => 1,
			)
		);
	}

	/**
	 * Pending checks of the plugin.
	 *
	 * @return array
	 */
	private function pending_checks() {
		return as_get_scheduled_actions(
			array(
				'hook'   => Reconciler::ACTION,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
	}

	/**
	 * R3.1: the first submit opens one link with that body and key.
	 */
	public function test_r3_1_first_submit() {
		$order = $this->order( '19.99', 'secondrail', array( '_secondrail_mode' => '' ) );
		$this->http->ok( 'POST', '/action_links', $this->link( $order ), 201 );
		$result = $this->pay( $order );
		$this->assertSame(
			array(
				'result'   => 'success',
				'redirect' => 'https://pay.secondrail.test/p/lnk_new',
			),
			$result
		);
		$requests = $this->http->seen( 'POST', '/action_links' );
		$this->assertCount( 1, $requests );
		$this->assertCount( 1, $this->http->requests );
		$id = (string) $order->get_id();
		$this->assertSame( 'wc-' . self::SITE . '-' . $id . '-1', $requests[0]['headers']['Idempotency-Key'] );
		$this->assertSame(
			array(
				'purpose'     => 'payment_link',
				'amount'      => 1999,
				'currency'    => 'USD',
				'description' => 'Order ' . $order->get_order_number() . ' - ' . get_bloginfo( 'name' ),
				'external_id' => (string) $order->get_order_number(),
				'metadata'    => array(
					'wc_order_id'    => $id,
					'wc_site'        => self::SITE,
					'source'         => 'woocommerce',
					'plugin_version' => '0.1.0',
				),
				'return_url'  => add_query_arg(
					array(
						'order_id' => $id,
						'key'      => $order->get_order_key(),
					),
					WC()->api_request_url( 'secondrail_return' )
				),
				'cancel_url'  => wc_get_checkout_url(),
				'auto_return' => true,
				'expires_in'  => 1800,
			),
			$requests[0]['body']
		);
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'lnk_new', $order->get_meta( '_secondrail_link_id' ) );
		$this->assertSame( '1', (string) $order->get_meta( '_secondrail_attempt' ) );
		$this->assertSame( 'test', $order->get_meta( '_secondrail_mode' ) );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertCount( 1, $this->notes( $order, 'SecondRail payment page opened (lnk_new).' ) );
		$this->assertCount( 1, $this->pending_checks() );
	}

	/**
	 * R3.2: the API fails on the link.
	 */
	public function test_r3_2_api_failure() {
		$order = $this->order( '19.99', 'secondrail', array( '_secondrail_mode' => '' ) );
		$this->http->error( 'POST', '/action_links', 503, 'provider_unavailable', 'Try later' );
		$this->assertSame( array( 'result' => 'failure' ), $this->pay( $order ) );
		$this->assertSame( array( 'We could not start the payment. Please try again.' ), $this->error_notices() );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '', $order->get_meta( '_secondrail_link_id' ) );
		$this->assertSame( '', $order->get_meta( '_secondrail_attempt' ) );
		$this->assertSame( '', $order->get_meta( '_secondrail_mode' ) );
		$this->assertSame( array(), $this->notes( $order ) );
		$this->assertSame( array(), $this->pending_checks() );
	}

	/**
	 * R3.3: previous payments all failed: revoke, then a new link with attempt 2.
	 */
	public function test_r3_3_second_submit_after_failures() {
		$order = $this->second_submit_order();
		$this->list_payments( array( $this->payment( $order, 'failed' ) ) );
		$this->http->ok( 'POST', '/action_links/lnk_old/revoke', array( 'id' => 'lnk_old' ) );
		$this->http->ok( 'POST', '/action_links', $this->link( $order, 'lnk_two' ), 201 );
		$result = $this->pay( $order );
		$this->assertSame( 'https://pay.secondrail.test/p/lnk_two', $result['redirect'] );
		$this->assertSame(
			'wc-' . self::SITE . '-' . $order->get_id() . '-2',
			$this->http->seen( 'POST', '/action_links' )[0]['headers']['Idempotency-Key']
		);
		$this->assertSame( 'lnk_two', wc_get_order( $order->get_id() )->get_meta( '_secondrail_link_id' ) );
		$this->assertSame( 0, $this->http->pending() );
	}

	/**
	 * R3.4: a previous payment succeeded: no new link, the order is paid.
	 */
	public function test_r3_4_second_submit_already_paid() {
		$order = $this->second_submit_order();
		$this->list_payments( array( $this->payment( $order ) ) );
		$result = $this->pay( $order );
		$order  = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame(
			array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
			),
			$result
		);
		$this->assertCount( 1, $this->http->requests );
	}

	/**
	 * R3.5: a previous payment is processing or unknown: on hold, no new link.
	 */
	public function test_r3_5_second_submit_waiting() {
		foreach ( array( 'processing', 'unknown' ) as $status ) {
			$order = $this->second_submit_order();
			$this->list_payments( array( $this->payment( $order, $status, array( 'created_at' => $this->ago( 180 ) ) ) ) );
			$result = $this->pay( $order );
			$order  = wc_get_order( $order->get_id() );
			$this->assertSame( 'on-hold', $order->get_status(), $status );
			$this->assertSame( $order->get_checkout_order_received_url(), $result['redirect'] );
		}
		$this->assertCount( 2, $this->http->requests );
	}

	/**
	 * R3.6: a previous payment needs action: cancel it, reconcile again, then a new link.
	 */
	public function test_r3_6_open_payment_is_cancelled() {
		$order   = $this->second_submit_order();
		$payment = $this->payment( $order, 'requires_action' );
		$this->list_payments( array( $payment ) );
		$this->http->ok( 'POST', '/payments/' . $payment['id'] . '/cancel', array_merge( $payment, array( 'status' => 'cancelled' ) ) );
		$this->list_payments( array( array_merge( $payment, array( 'status' => 'cancelled' ) ) ) );
		$this->http->ok( 'POST', '/action_links/lnk_old/revoke', array( 'id' => 'lnk_old' ) );
		$this->http->ok( 'POST', '/action_links', $this->link( $order, 'lnk_two' ), 201 );
		$result = $this->pay( $order );
		$this->assertSame( 'https://pay.secondrail.test/p/lnk_two', $result['redirect'] );
		$this->assertSame( 0, $this->http->pending() );
	}

	/**
	 * R3.6: the cancel answers `succeeded`: R3.4 applies.
	 */
	public function test_r3_6_cancel_returns_succeeded() {
		$order   = $this->second_submit_order();
		$payment = $this->payment( $order, 'requires_action' );
		$this->list_payments( array( $payment ) );
		$paid = array_merge( $payment, array( 'status' => 'succeeded' ) );
		$this->http->ok( 'POST', '/payments/' . $payment['id'] . '/cancel', $paid );
		$this->list_payments( array( $paid ) );
		$result = $this->pay( $order );
		$order  = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( $order->get_checkout_order_received_url(), $result['redirect'] );
		$this->assertSame( array(), $this->http->seen( 'POST', '/action_links' ) );
	}

	/**
	 * R3.6: a payment still open after the cancel blocks the attempt.
	 */
	public function test_r3_6_still_open_fails() {
		$order   = $this->second_submit_order();
		$payment = $this->payment( $order, 'requires_action' );
		$this->list_payments( array( $payment ) );
		$this->http->error( 'POST', '/payments/' . $payment['id'] . '/cancel', 409, 'invalid_state' );
		$this->list_payments( array( $payment ) );
		$this->assertSame( array( 'result' => 'failure' ), $this->pay( $order ) );
		$this->assertSame(
			array( 'We could not confirm your previous payment attempt yet. Try again in a few minutes.' ),
			$this->error_notices()
		);
	}

	/**
	 * R3.11: a charge in flight cannot be cancelled: no new link, try again later, still pending.
	 */
	public function test_r3_11_charge_in_flight() {
		$order   = $this->second_submit_order();
		$payment = $this->payment( $order, 'processing', array( 'created_at' => $this->ago( 5 ) ) );
		$this->list_payments( array( $payment ) );
		$this->http->error( 'POST', '/payments/' . $payment['id'] . '/cancel', 409, 'invalid_state' );
		$this->list_payments( array( $payment ) );
		$this->assertSame( array( 'result' => 'failure' ), $this->pay( $order ) );
		$this->assertSame(
			array( 'We could not confirm your previous payment attempt yet. Try again in a few minutes.' ),
			$this->error_notices()
		);
		$this->assertSame( array(), $this->http->seen( 'POST', '/action_links' ) );
		$this->assertSame( array(), $this->http->seen( 'POST', '/action_links/lnk_old/revoke' ) );
		$this->assertSame( 0, $this->http->pending() );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'lnk_old', $order->get_meta( '_secondrail_link_id' ) );
	}

	/**
	 * R3.7: `idempotency_conflict` moves to the next attempt, which is the one saved.
	 */
	public function test_r3_7_idempotency_conflict() {
		$order = $this->order();
		$this->http->error( 'POST', '/action_links', 409, 'idempotency_conflict', 'Different body' );
		$this->http->ok( 'POST', '/action_links', $this->link( $order ), 201 );
		$this->assertSame( 'success', $this->pay( $order )['result'] );
		$keys = array_map(
			static function ( $request ) {
				return $request['headers']['Idempotency-Key'];
			},
			$this->http->seen( 'POST', '/action_links' )
		);
		$prefix = 'wc-' . self::SITE . '-' . $order->get_id() . '-';
		$this->assertSame( array( $prefix . '1', $prefix . '2' ), $keys );
		$this->assertSame( '2', (string) wc_get_order( $order->get_id() )->get_meta( '_secondrail_attempt' ) );
	}

	/**
	 * R3.8: an API without the F24 fields: the link is revoked and the result is failure.
	 */
	public function test_r3_8_api_without_checkout_fields() {
		$order = $this->order();
		$this->http->ok( 'POST', '/action_links', $this->link( $order, 'lnk_old_api', array( 'auto_return' => null ) ), 201 );
		$this->http->ok( 'POST', '/action_links/lnk_old_api/revoke', array( 'id' => 'lnk_old_api' ) );
		$this->assertSame( 'failure', $this->pay( $order )['result'] );
		$this->assertSame( 0, $this->http->pending() );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_secondrail_link_id' ) );
	}

	/**
	 * R3.9: minor units, and only supported currencies make the gateway available.
	 */
	public function test_r3_9_amounts_and_currency() {
		$this->assertSame( 1999, Gateway::minor( '19.99' ) );
		$this->assertSame( 7, Gateway::minor( '0.07' ) );
		$this->assertSame( 123450, Gateway::minor( '1234.50' ) );
		update_option( 'woocommerce_currency', 'USD' );
		$this->assertTrue( ( new Gateway() )->is_available() );
		update_option( 'woocommerce_currency', 'JPY' );
		$this->assertFalse( ( new Gateway() )->is_available() );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_secondrail_settings', $this->settings( array( 'test_secret_key' => '' ) ) );
		$this->assertFalse( ( new Gateway() )->is_available() );
		update_option( 'woocommerce_secondrail_settings', $this->settings( array( 'api_base' => '' ) ) );
		$this->assertFalse( ( new Gateway() )->is_available() );
		update_option( 'woocommerce_secondrail_settings', $this->settings( array( 'enabled' => 'no' ) ) );
		$this->assertFalse( ( new Gateway() )->is_available() );
	}

	/**
	 * R3.10: a 404 or 409 on revoke does not stop the new link.
	 */
	public function test_r3_10_revoke_errors_are_not_fatal() {
		foreach ( array( array( 404, 'not_found' ), array( 409, 'invalid_state' ) ) as $answer ) {
			$order = $this->second_submit_order();
			$this->list_payments( array() );
			$this->http->error( 'POST', '/action_links/lnk_old/revoke', $answer[0], $answer[1] );
			$this->http->ok( 'POST', '/action_links', $this->link( $order, 'lnk_two' ), 201 );
			$this->assertSame( 'success', $this->pay( $order )['result'], $answer[1] );
		}
		$this->assertSame( 0, $this->http->pending() );
	}
}
