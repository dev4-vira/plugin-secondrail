<?php
/**
 * R8: settings, connection and the webhook endpoint.
 *
 * @package SecondRail\WooCommerce\Tests
 */

namespace SecondRail\WooCommerce\Tests;

use SecondRail\WooCommerce\Gateway;
use SecondRail\WooCommerce\Webhook;

/**
 * R8.1–R8.3, R8.5–R8.7.
 */
class Test_Settings extends Test_Case {

	/**
	 * Posts the settings form.
	 *
	 * @param array $fields Field values.
	 * @return Gateway
	 */
	private function save( array $fields ) {
		$_POST = array();
		foreach ( array_merge( $this->settings(), $fields ) as $key => $value ) {
			if ( 'yes' === $value ) {
				$_POST[ 'woocommerce_secondrail_' . $key ] = '1';
			} elseif ( 'no' !== $value ) {
				$_POST[ 'woocommerce_secondrail_' . $key ] = $value;
			}
		}
		$gateway = new Gateway();
		$gateway->process_admin_options();
		$_POST = array();
		return $gateway;
	}

	/**
	 * Queues a successful connection.
	 */
	private function queue_connection() {
		$this->http->ok(
			'GET',
			'/account',
			array(
				'name'     => 'HoneyPacks',
				'livemode' => false,
			)
		);
	}

	/**
	 * Queues the endpoint creation.
	 *
	 * @param string $id Endpoint id.
	 */
	private function queue_endpoint( $id = 'we_1' ) {
		$this->http->ok(
			'POST',
			'/webhook_endpoints',
			array(
				'id'     => $id,
				'url'    => Webhook::webhook_url(),
				'secret' => 'whsec_' . $id,
			),
			201
		);
	}

	/**
	 * R8.1: a valid test key: account read, endpoint created once, stored not autoloaded.
	 */
	public function test_r8_1_valid_key_registers_the_webhook() {
		delete_option( 'woocommerce_secondrail_settings' );
		$this->queue_connection();
		$this->queue_endpoint();
		$gateway = $this->save( array() );
		$this->assertSame( array(), $gateway->get_errors() );
		$this->assertCount( 1, $this->http->seen( 'GET', '/account' ) );
		$post = $this->http->seen( 'POST', '/webhook_endpoints' );
		$this->assertCount( 1, $post );
		$this->assertSame( WC()->api_request_url( 'secondrail_webhook' ), $post[0]['body']['url'] );
		$this->assertSame(
			array( 'payment.succeeded', 'payment.failed', 'payment.cancelled', 'payment.authorized', 'payment.captured', 'payment.unknown', 'refund.succeeded', 'refund.failed' ),
			$post[0]['body']['event_types']
		);
		$stored = get_option( 'secondrail_wc_webhook_test' );
		$this->assertSame( 'we_1', $stored['id'] );
		$this->assertSame( 'whsec_we_1', $stored['secret'] );
		$this->assertArrayNotHasKey( 'secondrail_wc_webhook_test', wp_load_alloptions() );
		$this->queue_connection();
		$this->save( array() );
		$this->assertCount( 1, $this->http->seen( 'POST', '/webhook_endpoints' ) );
	}

	/**
	 * R8.2: a live key in the test field: an error and no request.
	 */
	public function test_r8_2_key_of_the_wrong_mode() {
		$gateway = $this->save( array( 'test_secret_key' => 'sk_live_wrong_mode_0001' ) );
		$this->assertCount( 1, $gateway->get_errors() );
		$this->assertSame( array(), $this->http->requests );
	}

	/**
	 * R8.3: GET /account answers 401: the message, and no webhook.
	 */
	public function test_r8_3_unauthorized() {
		$this->http->error( 'GET', '/account', 401, 'unauthorized', 'Invalid API key' );
		$gateway = $this->save( array() );
		$this->assertSame( array( 'Invalid API key' ), $gateway->get_errors() );
		$this->assertSame( array(), $this->http->seen( 'POST', '/webhook_endpoints' ) );
		$this->assertSame( 'sk_test_unit_key_0001', Gateway::secret_key( 'test' ) );
	}

	/**
	 * R8.5: another stored URL: delete the old endpoint, then create one.
	 */
	public function test_r8_5_url_changed() {
		update_option(
			'secondrail_wc_webhook_test',
			array(
				'id'     => 'we_old',
				'url'    => 'http://old.example/hook',
				'secret' => 'whsec_old',
			),
			false
		);
		$this->queue_connection();
		$this->http->ok( 'DELETE', '/webhook_endpoints/we_old', array( 'id' => 'we_old' ) );
		$this->queue_endpoint( 'we_2' );
		$this->save( array() );
		$methods = array_map(
			static function ( $request ) {
				return $request['method'] . ' ' . $request['path'];
			},
			$this->http->requests
		);
		$this->assertSame( array( 'GET /account', 'DELETE /webhook_endpoints/we_old', 'POST /webhook_endpoints' ), $methods );
		$this->assertSame( 'we_2', get_option( 'secondrail_wc_webhook_test' )['id'] );
	}

	/**
	 * R8.6: "Reconnect webhook" needs a valid nonce and `manage_woocommerce`.
	 */
	public function test_r8_6_reconnect_is_protected() {
		$this->assertFalse( Gateway::reconnect_webhook( array( '_wpnonce' => 'bad' ) ) );
		$shop_manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$subscriber   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->assertFalse( Gateway::reconnect_webhook( array( '_wpnonce' => wp_create_nonce( 'secondrail_wc_reconnect' ) ) ) );
		wp_set_current_user( $shop_manager );
		$this->assertFalse( Gateway::reconnect_webhook( array( '_wpnonce' => 'bad' ) ) );
		$this->assertSame( array(), $this->http->requests );
		$this->queue_endpoint( 'we_3' );
		$this->assertTrue( Gateway::reconnect_webhook( array( '_wpnonce' => wp_create_nonce( 'secondrail_wc_reconnect' ) ) ) );
		$this->assertSame( 'we_3', get_option( 'secondrail_wc_webhook_test' )['id'] );
	}

	/**
	 * R8.7: the stored key is never printed, survives an empty field, and never reaches the log.
	 */
	public function test_r8_7_key_is_kept_secret() {
		$gateway = new Gateway();
		$html    = $gateway->generate_settings_html( $gateway->get_form_fields(), false );
		$this->assertStringNotContainsString( 'sk_test_unit_key_0001', $html );
		$this->assertStringNotContainsString( 'sk_live_unit_key_0001', $html );
		$this->assertStringContainsString( 'type="password"', $html );
		update_option( 'woocommerce_secondrail_settings', $this->settings( array( 'debug' => 'yes' ) ) );
		$this->queue_connection();
		$this->queue_endpoint();
		$lines   = array();
		$capture = static function ( $message ) use ( &$lines ) {
			$lines[] = $message;
			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $capture );
		$this->save(
			array(
				'test_secret_key' => '',
				'live_secret_key' => '',
				'debug'           => 'yes',
			)
		);
		remove_filter( 'woocommerce_logger_log_message', $capture );
		$this->assertSame( 'sk_test_unit_key_0001', Gateway::secret_key( 'test' ) );
		$this->assertSame( 'sk_live_unit_key_0001', Gateway::secret_key( 'live' ) );
		$this->assertNotEmpty( $lines );
		foreach ( $lines as $line ) {
			$this->assertStringNotContainsString( 'sk_test_unit_key_0001', $line );
			$this->assertStringNotContainsString( 'whsec_', $line );
		}
	}
}
