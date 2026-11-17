<?php
/**
 * Base test case: a configured gateway in test mode and the HTTP stub.
 *
 * @package SecondRail\WooCommerce\Tests
 */

namespace SecondRail\WooCommerce\Tests;

/**
 * Shared fixtures.
 */
abstract class Test_Case extends \WP_UnitTestCase {

	const SITE = '6f1c2a34-6b2e-4c1d-9a51-2f0f6f9d1a01';

	/**
	 * HTTP stub.
	 *
	 * @var Http_Stub
	 */
	protected $http;

	/**
	 * Configures the gateway and installs the stub.
	 */
	public function set_up() {
		parent::set_up();
		$this->http = new Http_Stub();
		$this->http->install();
		update_option( 'woocommerce_secondrail_settings', $this->settings() );
		update_option( 'secondrail_wc_site_id', self::SITE );
		if ( null === WC()->session ) {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
		}
		wc_clear_notices();
	}

	/**
	 * Fails the test on any request nobody queued.
	 */
	public function tear_down() {
		$unexpected = $this->http->unexpected;
		$this->http->uninstall();
		wc_clear_notices();
		parent::tear_down();
		$this->assertSame( array(), $unexpected, 'Requests nobody queued' );
	}

	/**
	 * Gateway settings used by default.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	protected function settings( array $over = array() ) {
		return array_merge(
			array(
				'enabled'         => 'yes',
				'title'           => 'Credit or debit card',
				'description'     => 'You will be taken to a secure page to complete your payment.',
				'api_base'        => Http_Stub::API_BASE,
				'testmode'        => 'yes',
				'test_secret_key' => 'sk_test_unit_key_0001',
				'live_secret_key' => 'sk_live_unit_key_0001',
				'debug'           => 'no',
			),
			$over
		);
	}

	/**
	 * A pending order paid with secondrail.
	 *
	 * @param string $total          Order total.
	 * @param string $payment_method Payment method id.
	 * @param array  $meta           Order meta.
	 * @return \WC_Order
	 */
	protected function order( $total = '19.99', $payment_method = 'secondrail', array $meta = array() ) {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_payment_method( $payment_method );
		$order->set_total( $total );
		$order->set_status( 'pending' );
		foreach ( array_merge( array( '_secondrail_mode' => 'test' ), $meta ) as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	/**
	 * A payment as `GET /payments` returns it, bound to an order.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $status Payment status.
	 * @param array     $over   Overrides.
	 * @return array
	 */
	protected function payment( $order, $status = 'succeeded', array $over = array() ) {
		static $sequence = 0;
		++$sequence;
		return array_replace_recursive(
			array(
				'id'          => 'pay_' . str_pad( (string) $sequence, 4, '0', STR_PAD_LEFT ),
				'object'      => 'payment',
				'status'      => $status,
				'amount'      => (int) round( (float) $order->get_total() * 100 ),
				'currency'    => $order->get_currency(),
				'external_id' => (string) $order->get_order_number(),
				'metadata'    => array(
					'wc_order_id'    => (string) $order->get_id(),
					'wc_site'        => self::SITE,
					'source'         => 'woocommerce',
					'plugin_version' => '0.1.0',
				),
				'card'        => array(
					'brand' => 'visa',
					'last4' => '4242',
				),
				'error'       => 'failed' === $status ? array( 'message' => 'Card declined' ) : null,
				'created_at'  => gmdate( 'Y-m-d\TH:i:s', 1700000000 + $sequence ) . 'Z',
			),
			$over
		);
	}

	/**
	 * A `created_at` some seconds ago, in the platform's format (microseconds, `Z`).
	 *
	 * @param int $seconds Seconds ago.
	 * @return string
	 */
	protected function ago( $seconds ) {
		return gmdate( 'Y-m-d\TH:i:s', time() - $seconds ) . '.123456Z';
	}

	/**
	 * Queues `GET /payments` with these payments.
	 *
	 * @param array[] $payments Payments.
	 */
	protected function list_payments( array $payments ) {
		$this->http->ok( 'GET', '/payments', $payments );
	}

	/**
	 * Notes of an order that contain a text.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $text  Text.
	 * @return string[]
	 */
	protected function notes( $order, $text = '' ) {
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$found = array();
		foreach ( $notes as $note ) {
			if ( '' === $text || false !== strpos( $note->content, $text ) ) {
				$found[] = $note->content;
			}
		}
		return $found;
	}

	/**
	 * Error notices added to the session.
	 *
	 * @return string[]
	 */
	protected function error_notices() {
		return array_map(
			static function ( $notice ) {
				return $notice['notice'];
			},
			wc_get_notices( 'error' )
		);
	}
}
