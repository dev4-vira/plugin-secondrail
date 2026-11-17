<?php
/**
 * R6: the webhook handler. R8.4 (the webhook URL constant) is the last test of the last file,
 * because a constant cannot be undefined once a test defines it.
 *
 * @package SecondRail\WooCommerce\Tests
 */

namespace SecondRail\WooCommerce\Tests;

use SecondRail\WooCommerce\Webhook;

/**
 * R6.1–R6.10, R8.4.
 */
class Test_Webhook extends Test_Case {

	const TEST_SECRET = 'whsec_unit_test_secret';
	const LIVE_SECRET = 'whsec_unit_live_secret';

	/**
	 * Stores both endpoints.
	 */
	public function set_up() {
		parent::set_up();
		update_option(
			'secondrail_wc_webhook_test',
			array(
				'id'     => 'we_test',
				'url'    => 'http://example.org/?wc-api=secondrail_webhook',
				'secret' => self::TEST_SECRET,
			),
			false
		);
		update_option(
			'secondrail_wc_webhook_live',
			array(
				'id'     => 'we_live',
				'url'    => 'http://example.org/?wc-api=secondrail_webhook',
				'secret' => self::LIVE_SECRET,
			),
			false
		);
	}

	/**
	 * Signature header for a body.
	 *
	 * @param string $raw    Raw body.
	 * @param string $secret Secret.
	 * @param int    $age    Seconds since signing.
	 * @return string
	 */
	private function sign( $raw, $secret = self::TEST_SECRET, $age = 0 ) {
		$t = time() - $age;
		return 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $raw, $secret );
	}

	/**
	 * Event body.
	 *
	 * @param string $type     Event type.
	 * @param array  $object   Data object.
	 * @param bool   $livemode Livemode.
	 * @return string
	 */
	private function event( $type, array $object, $livemode = false ) {
		return wp_json_encode(
			array(
				'id'       => 'evt_1',
				'object'   => 'event',
				'type'     => $type,
				'livemode' => $livemode,
				'data'     => array(
					'object'   => $object,
					'previous' => null,
				),
			)
		);
	}

	/**
	 * A refund object.
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $status   Status.
	 * @param array     $metadata Metadata.
	 * @return array
	 */
	private function refund( $order, $status, array $metadata ) {
		return array(
			'id'       => 're_1',
			'object'   => 'refund',
			'payment'  => 'pay_paid',
			'amount'   => 500,
			'currency' => 'USD',
			'status'   => $status,
			'error'    => 'failed' === $status ? array( 'message' => 'Refund rejected' ) : null,
			'metadata' => $metadata,
		);
	}

	/**
	 * R6.1: a valid v1, and a valid v0 beside a wrong v1.
	 */
	public function test_r6_1_valid_signatures() {
		$raw = $this->event( 'ping', array( 'endpoint' => 'we_test' ) );
		$this->assertSame( 200, Webhook::handle( $raw, $this->sign( $raw ) ) );
		$t        = time();
		$rotation = 't=' . $t . ',v1=' . str_repeat( 'a', 64 ) . ',v0=' . hash_hmac( 'sha256', $t . '.' . $raw, self::TEST_SECRET );
		$this->assertSame( 200, Webhook::handle( $raw, $rotation ) );
	}

	/**
	 * R6.2: bad signatures are 400 with no further work.
	 */
	public function test_r6_2_bad_signatures() {
		$order = $this->order();
		$raw   = $this->event( 'payment.succeeded', $this->payment( $order ) );
		$this->assertSame( 400, Webhook::handle( $raw, $this->sign( $raw, 'whsec_wrong' ) ) );
		$this->assertSame( 400, Webhook::handle( $raw, '' ) );
		$this->assertSame( 400, Webhook::handle( $raw, $this->sign( $raw, self::TEST_SECRET, 301 ) ) );
		$this->assertSame( 400, Webhook::handle( $raw . ' ', $this->sign( $raw ) ) );
		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * R6.3: ping.
	 */
	public function test_r6_3_ping() {
		$raw = $this->event( 'ping', array( 'endpoint' => 'we_test' ) );
		$this->assertSame( 200, Webhook::handle( $raw, $this->sign( $raw ) ) );
		$this->assertSame( array(), $this->http->requests );
	}

	/**
	 * R6.4: payment.succeeded for an order of this install pays it.
	 */
	public function test_r6_4_payment_succeeded() {
		$order   = $this->order();
		$payment = $this->payment( $order );
		$raw     = $this->event( 'payment.succeeded', $payment );
		$this->list_payments( array( $payment ) );
		$this->assertSame( 200, Webhook::handle( $raw, $this->sign( $raw ) ) );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	/**
	 * R6.5: another install's payment is ignored.
	 */
	public function test_r6_5_other_site() {
		$order = $this->order();
		$raw   = $this->event( 'payment.succeeded', $this->payment( $order, 'succeeded', array( 'metadata' => array( 'wc_site' => 'other' ) ) ) );
		$this->assertSame( 200, Webhook::handle( $raw, $this->sign( $raw ) ) );
		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * R6.6: a live event verified with the test secret is ignored; so is an order of another mode.
	 */
	public function test_r6_6_mode_mismatch() {
		$order = $this->order();
		$raw   = $this->event( 'payment.succeeded', $this->payment( $order ), true );
		$this->assertSame( 200, Webhook::handle( $raw, $this->sign( $raw ) ) );
		$live = $this->event( 'payment.succeeded', $this->payment( $order ), true );
		$this->assertSame( 200, Webhook::handle( $live, $this->sign( $live, self::LIVE_SECRET ) ) );
		$this->assertSame( array(), $this->http->requests );
	}

	/**
	 * R6.7: refund.failed adds one note, once.
	 */
	public function test_r6_7_refund_failed() {
		$order = $this->order();
		$raw   = $this->event(
			'refund.failed',
			$this->refund(
				$order,
				'failed',
				array(
					'source'      => 'woocommerce',
					'wc_order_id' => (string) $order->get_id(),
					'wc_site'     => self::SITE,
				)
			)
		);
		$this->assertSame( 200, Webhook::handle( $raw, $this->sign( $raw ) ) );
		$this->assertSame( 200, Webhook::handle( $raw, $this->sign( $raw ) ) );
		$this->assertCount( 1, $this->notes( $order, 'Refund re_1 failed in SecondRail: Refund rejected. Handle it manually.' ) );
	}

	/**
	 * R6.8: a console refund finds its order through the payment; a plugin refund adds nothing.
	 */
	public function test_r6_8_refund_succeeded() {
		$order = $this->order();
		$raw   = $this->event( 'refund.succeeded', $this->refund( $order, 'succeeded', array() ) );
		$this->http->ok( 'GET', '/payments/pay_paid', $this->payment( $order, 'succeeded', array( 'id' => 'pay_paid' ) ) );
		$this->assertSame( 200, Webhook::handle( $raw, $this->sign( $raw ) ) );
		$this->assertCount( 1, $this->http->seen( 'GET', '/payments/pay_paid' ) );
		$this->assertCount( 1, $this->notes( $order, 'Refunded 5.00 USD in SecondRail (re_1). Record it in WooCommerce if needed.' ) );
		$other = $this->order();
		$own   = $this->event(
			'refund.succeeded',
			$this->refund(
				$other,
				'succeeded',
				array(
					'source'      => 'woocommerce',
					'wc_order_id' => (string) $other->get_id(),
					'wc_site'     => self::SITE,
				)
			)
		);
		$this->assertSame( 200, Webhook::handle( $own, $this->sign( $own ) ) );
		$this->assertSame( array(), $this->notes( $other, 'Refunded' ) );
	}

	/**
	 * R6.9: an API failure during the reconcile is a 500.
	 */
	public function test_r6_9_api_failure_is_500() {
		$order = $this->order();
		$raw   = $this->event( 'payment.succeeded', $this->payment( $order ) );
		$this->http->error( 'GET', '/payments', 503, 'provider_unavailable' );
		$this->assertSame( 500, Webhook::handle( $raw, $this->sign( $raw ) ) );
	}

	/**
	 * R6.11: the order's lock is held: 503 so SecondRail delivers again, the order untouched.
	 */
	public function test_r6_11_lock_held_is_503() {
		$order = $this->order();
		$raw   = $this->event( 'payment.succeeded', $this->payment( $order ) );
		set_transient( 'secondrail_wc_lock_' . $order->get_id(), 1, 60 );
		$status = Webhook::handle( $raw, $this->sign( $raw ) );
		delete_transient( 'secondrail_wc_lock_' . $order->get_id() );
		$this->assertSame( 503, $status );
		$this->assertSame( array(), $this->http->requests );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
	}

	/**
	 * R6.10: a body over 256 KB.
	 */
	public function test_r6_10_body_too_large() {
		$raw = str_repeat( 'a', 256 * 1024 + 1 );
		$this->assertSame( 400, Webhook::handle( $raw, $this->sign( $raw ) ) );
	}

	/**
	 * R8.4: with SECONDRAIL_WC_WEBHOOK_URL defined, that URL is registered. Keep this test last.
	 */
	public function test_r8_4_webhook_url_constant() {
		delete_option( 'secondrail_wc_webhook_test' );
		define( 'SECONDRAIL_WC_WEBHOOK_URL', 'http://plugin-secondrail-wp/?wc-api=secondrail_webhook' );
		$this->http->ok(
			'POST',
			'/webhook_endpoints',
			array(
				'id'     => 'we_new',
				'secret' => 'whsec_new',
			),
			201
		);
		Webhook::ensure_endpoint( 'test' );
		$this->assertSame( SECONDRAIL_WC_WEBHOOK_URL, $this->http->requests[0]['body']['url'] );
	}
}
