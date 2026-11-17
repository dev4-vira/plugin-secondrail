<?php
/**
 * R7: refunds from the WooCommerce order screen.
 *
 * @package SecondRail\WooCommerce\Tests
 */

namespace SecondRail\WooCommerce\Tests;

use SecondRail\WooCommerce\Gateway;

/**
 * R7.1–R7.5.
 */
class Test_Refund extends Test_Case {

	/**
	 * A paid order.
	 *
	 * @return \WC_Order
	 */
	private function paid_order() {
		return $this->order(
			'19.99',
			'secondrail',
			array( '_secondrail_payment_id' => 'pay_paid' )
		);
	}

	/**
	 * Refunds through the gateway.
	 *
	 * @param \WC_Order  $order  Order.
	 * @param mixed      $amount Amount.
	 * @param string     $reason Reason.
	 * @return bool|\WP_Error
	 */
	private function refund( $order, $amount, $reason = 'Damaged item' ) {
		return ( new Gateway() )->process_refund( $order->get_id(), $amount, $reason );
	}

	/**
	 * R7.1: one request with that body and key; a note with the refund id and status.
	 */
	public function test_r7_1_refund() {
		$order = $this->paid_order();
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '2.00',
			)
		);
		$this->http->ok(
			'POST',
			'/refunds',
			array(
				'id'     => 're_1',
				'status' => 'pending',
			),
			201
		);
		$this->assertTrue( $this->refund( wc_get_order( $order->get_id() ), '5.00' ) );
		$requests = $this->http->seen( 'POST', '/refunds' );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'wc-refund-' . self::SITE . '-' . $order->get_id() . '-200-0', $requests[0]['headers']['Idempotency-Key'] );
		$this->assertSame(
			array(
				'payment'  => 'pay_paid',
				'amount'   => 500,
				'reason'   => 'requested_by_customer',
				'note'     => 'Damaged item',
				'metadata' => array(
					'source'      => 'woocommerce',
					'wc_order_id' => (string) $order->get_id(),
					'wc_site'     => self::SITE,
				),
			),
			$requests[0]['body']
		);
		$this->assertCount( 1, $this->notes( $order, 'Refund re_1 of 5.00 sent to SecondRail (pending).' ) );
	}

	/**
	 * R7.1: the key follows the total refunded, so a repeat after a lost answer replays.
	 */
	public function test_r7_1_key_through_wc_create_refund() {
		$order = $this->paid_order();
		$this->http->ok(
			'POST',
			'/refunds',
			array(
				'id'     => 're_2',
				'status' => 'succeeded',
			),
			201
		);
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '5.00',
				'reason'         => 'Late',
				'refund_payment' => true,
			)
		);
		$this->assertNotWPError( $refund );
		$this->assertSame( 'wc-refund-' . self::SITE . '-' . $order->get_id() . '-500-0', $this->http->requests[0]['headers']['Idempotency-Key'] );
	}

	/**
	 * R7.2: 409 `exceeds_refundable` is a WP_Error with the envelope's message.
	 */
	public function test_r7_2_exceeds_refundable() {
		$order = $this->paid_order();
		$this->http->error( 'POST', '/refunds', 409, 'exceeds_refundable', 'The amount exceeds what can be refunded' );
		$result = $this->refund( $order, '50.00' );
		$this->assertWPError( $result );
		$this->assertSame( 'The amount exceeds what can be refunded', $result->get_error_message() );
	}

	/**
	 * R7.3: no payment id: WP_Error, no request.
	 */
	public function test_r7_3_no_payment() {
		$this->assertWPError( $this->refund( $this->order(), '5.00' ) );
		$this->assertSame( array(), $this->http->requests );
	}

	const UNKNOWN = 'The refund outcome is unknown. Check the payment in SecondRail before trying again.';

	/**
	 * R7.4: a transport failure, a body that is not the envelope and a 503: outcome unknown.
	 */
	public function test_r7_4_transport_failure() {
		$order = $this->paid_order();
		$this->http->raw( 'POST', '/refunds', 0, new \WP_Error( 'http_request_failed', 'timeout' ) );
		$this->http->raw( 'POST', '/refunds', 502, '<html>Bad gateway</html>' );
		$this->http->error( 'POST', '/refunds', 503, 'provider_unavailable', 'Try later' );
		for ( $i = 0; $i < 3; $i++ ) {
			$result = $this->refund( $order, '5.00' );
			$this->assertWPError( $result );
			$this->assertSame( self::UNKNOWN, $result->get_error_message() );
		}
	}

	/**
	 * Refund failure counter of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return int
	 */
	private function failures( $order ) {
		return (int) wc_get_order( $order->get_id() )->get_meta( '_secondrail_refund_failures' );
	}

	/**
	 * R7.8: a refused refund moves the counter, so the retry has another key and goes through.
	 */
	public function test_r7_8_retry_after_refusal() {
		$order = $this->paid_order();
		$this->http->ok(
			'POST',
			'/refunds',
			array(
				'id'     => 're_refused',
				'status' => 'failed',
				'error'  => array( 'message' => 'Refused' ),
			),
			201
		);
		$this->http->ok(
			'POST',
			'/refunds',
			array(
				'id'     => 're_ok',
				'status' => 'succeeded',
			),
			201
		);
		$args = array(
			'order_id'       => $order->get_id(),
			'amount'         => '5.00',
			'reason'         => 'Late',
			'refund_payment' => true,
		);
		$this->assertWPError( wc_create_refund( $args ) );
		$this->assertSame( 1, $this->failures( $order ) );
		$this->assertNotWPError( wc_create_refund( $args ) );
		$keys   = array_map(
			static function ( $request ) {
				return $request['headers']['Idempotency-Key'];
			},
			$this->http->seen( 'POST', '/refunds' )
		);
		$prefix = 'wc-refund-' . self::SITE . '-' . $order->get_id() . '-500-';
		$this->assertSame( array( $prefix . '0', $prefix . '1' ), $keys );
		$this->assertSame( 5.0, (float) wc_get_order( $order->get_id() )->get_total_refunded() );
		$this->assertSame( 1, $this->failures( $order ) );
	}

	/**
	 * R7.8: an accepted refund, a transport failure and `exceeds_refundable` leave the counter.
	 */
	public function test_r7_8_counter_moves_only_on_refusal() {
		$order = $this->paid_order();
		$this->http->ok(
			'POST',
			'/refunds',
			array(
				'id'     => 're_ok',
				'status' => 'pending',
			),
			201
		);
		$this->http->raw( 'POST', '/refunds', 0, new \WP_Error( 'http_request_failed', 'timeout' ) );
		$this->http->error( 'POST', '/refunds', 409, 'exceeds_refundable', 'Too much' );
		$this->assertTrue( $this->refund( $order, '5.00' ) );
		$this->assertWPError( $this->refund( $order, '5.00' ) );
		$too_much = $this->refund( $order, '50.00' );
		$this->assertSame( 'Too much', $too_much->get_error_message() );
		$this->assertSame( 0, $this->failures( $order ) );
	}

	/**
	 * R7.9: idempotency conflicts mean the outcome is unknown; the counter does not move.
	 */
	public function test_r7_9_idempotency_errors_are_unknown() {
		$order = $this->paid_order();
		$this->http->error( 'POST', '/refunds', 409, 'idempotency_conflict', 'Key reused' );
		$this->http->error( 'POST', '/refunds', 409, 'idempotency_in_progress', 'Still running' );
		for ( $i = 0; $i < 2; $i++ ) {
			$result = $this->refund( $order, '5.00' );
			$this->assertWPError( $result );
			$this->assertSame( self::UNKNOWN, $result->get_error_message() );
		}
		$this->assertSame( 0, $this->failures( $order ) );
	}

	/**
	 * R7.6: a 201 whose refund failed or was cancelled is an error and leaves no "sent" note.
	 */
	public function test_r7_6_refused_refund_is_an_error() {
		$order = $this->paid_order();
		$this->http->ok(
			'POST',
			'/refunds',
			array(
				'id'     => 're_failed',
				'status' => 'failed',
				'error'  => array( 'message' => 'The processor refused the refund' ),
			),
			201
		);
		$this->http->ok(
			'POST',
			'/refunds',
			array(
				'id'     => 're_cancelled',
				'status' => 'cancelled',
				'error'  => null,
			),
			201
		);
		$failed = $this->refund( $order, '5.00' );
		$this->assertWPError( $failed );
		$this->assertSame( 'The processor refused the refund', $failed->get_error_message() );
		$cancelled = $this->refund( $order, '5.00' );
		$this->assertWPError( $cancelled );
		$this->assertSame( 'SecondRail could not make the refund.', $cancelled->get_error_message() );
		$this->assertSame( array(), $this->notes( $order, 'sent to SecondRail' ) );
	}

	/**
	 * R7.6: through wc_create_refund, a refused refund leaves nothing refunded.
	 */
	public function test_r7_6_wc_create_refund_records_nothing() {
		$order = $this->paid_order();
		$this->http->ok(
			'POST',
			'/refunds',
			array(
				'id'     => 're_failed',
				'status' => 'failed',
				'error'  => array( 'message' => 'The processor refused the refund' ),
			),
			201
		);
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '5.00',
				'reason'         => 'Late',
				'refund_payment' => true,
			)
		);
		$this->assertWPError( $refund );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 0.0, (float) $order->get_total_refunded() );
		$this->assertSame( array(), $order->get_refunds() );
	}

	/**
	 * R7.7: an `unknown` refund is accepted and its note says so.
	 */
	public function test_r7_7_unknown_refund() {
		$order = $this->paid_order();
		$this->http->ok(
			'POST',
			'/refunds',
			array(
				'id'     => 're_unknown',
				'status' => 'unknown',
			),
			201
		);
		$this->assertTrue( $this->refund( $order, '5.00' ) );
		$this->assertCount( 1, $this->notes( $order, 'Refund re_unknown of 5.00 sent to SecondRail (unknown).' ) );
	}

	/**
	 * R7.5: no amount, or zero.
	 */
	public function test_r7_5_no_amount() {
		$order = $this->paid_order();
		$this->assertWPError( $this->refund( $order, null ) );
		$this->assertWPError( $this->refund( $order, '0' ) );
		$this->assertSame( array(), $this->http->requests );
	}
}
