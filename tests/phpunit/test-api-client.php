<?php
/**
 * R2: the SecondRail API client.
 *
 * @package SecondRail\WooCommerce\Tests
 */

namespace SecondRail\WooCommerce\Tests;

use SecondRail\WooCommerce\Api_Client;
use SecondRail\WooCommerce\Api_Error;

/**
 * R2.1–R2.6.
 */
class Test_Api_Client extends Test_Case {

	const KEY = 'sk_test_unit_key_0001';

	/**
	 * Client in test.
	 *
	 * @param bool   $debug Debug log on.
	 * @param string $base  API base.
	 * @return Api_Client
	 */
	private function client( $debug = false, $base = Http_Stub::API_BASE ) {
		return new Api_Client( $base, self::KEY, $debug );
	}

	/**
	 * Expects an Api_Error from a call.
	 *
	 * @param callable $call Call.
	 * @return Api_Error
	 */
	private function api_error( callable $call ) {
		try {
			$call();
		} catch ( Api_Error $error ) {
			return $error;
		}
		$this->fail( 'Api_Error expected' );
	}

	/**
	 * R2.1: write and read headers and body.
	 */
	public function test_r2_1_write_and_read_requests() {
		$this->http->ok( 'POST', '/action_links', array( 'id' => 'lnk_1' ), 201 );
		$this->http->ok( 'GET', '/account', array( 'name' => 'Shop' ) );
		$this->client()->post( '/action_links', array( 'amount' => 1999 ), 'key-1' );
		$this->client()->get( '/account' );
		list( $write, $read ) = $this->http->requests;
		$this->assertSame( 'Bearer ' . self::KEY, $write['headers']['Authorization'] );
		$this->assertSame( '2026-11-01', $write['headers']['SecondRail-Version'] );
		$this->assertSame( 'application/json', $write['headers']['Accept'] );
		$this->assertSame( 'SecondRail-WooCommerce/0.1.0', $write['headers']['User-Agent'] );
		$this->assertSame( 'application/json', $write['headers']['Content-Type'] );
		$this->assertSame( 'key-1', $write['headers']['Idempotency-Key'] );
		$this->assertSame( array( 'amount' => 1999 ), $write['body'] );
		$this->assertSame( 45, $write['args']['timeout'] );
		$this->assertSame( 0, $write['args']['redirection'] );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $read['headers'] );
		$this->assertArrayNotHasKey( 'Content-Type', $read['headers'] );
		$this->assertEmpty( $read['raw'] );
		$this->assertSame( 15, $read['args']['timeout'] );
		$this->assertSame( 'Bearer ' . self::KEY, $read['headers']['Authorization'] );
	}

	/**
	 * R2.1: a write without a key of its own sends a random UUID.
	 */
	public function test_r2_1_write_without_key_sends_uuid() {
		$this->http->ok( 'POST', '/payments/pay_1/cancel', array( 'id' => 'pay_1' ) );
		$this->client()->post( '/payments/pay_1/cancel' );
		$key = $this->http->requests[0]['headers']['Idempotency-Key'];
		$this->assertTrue( wp_is_uuid( $key ) );
	}

	/**
	 * R2.2: a 201 envelope returns its data.
	 */
	public function test_r2_2_success_returns_data() {
		$this->http->ok( 'POST', '/action_links', array( 'id' => 'lnk_1' ), 201 );
		$this->assertSame( array( 'id' => 'lnk_1' ), $this->client()->post( '/action_links', array(), 'k' ) );
	}

	/**
	 * R2.3: an error envelope raises Api_Error with its details.
	 */
	public function test_r2_3_error_envelope() {
		$this->http->error( 'POST', '/action_links', 409, 'idempotency_conflict', 'Key reused', array( 'x' => 1 ) );
		$error = $this->api_error(
			function () {
				$this->client()->post( '/action_links', array(), 'k' );
			}
		);
		$this->assertSame( 409, $error->status );
		$this->assertSame( 'idempotency_conflict', $error->error_code );
		$this->assertSame( 'Key reused', $error->getMessage() );
		$this->assertSame( array( 'x' => 1 ), $error->data );
		$this->assertSame( 'req_error', $error->request_id );
	}

	/**
	 * R2.4: transport errors and bodies that are not the envelope.
	 */
	public function test_r2_4_transport_and_invalid_bodies() {
		$this->http->raw( 'GET', '/account', 0, new \WP_Error( 'http_request_failed', 'Timed out' ) );
		$this->http->raw( 'GET', '/account', 502, '<html>Bad gateway</html>' );
		$this->http->raw( 'GET', '/account', 200, '{"code": "ok", ' );
		$codes = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$codes[] = $this->api_error(
				function () {
					$this->client()->get( '/account' );
				}
			)->error_code;
		}
		$this->assertSame( array( 'network_error', 'invalid_response', 'invalid_response' ), $codes );
	}

	/**
	 * R2.5: the debug log has the path and request id, never the key or the body.
	 */
	public function test_r2_5_debug_log_without_secrets() {
		$lines  = array();
		$logger = new class( $lines ) implements \WC_Logger_Interface {
			/**
			 * Captured lines.
			 *
			 * @var array
			 */
			public $lines;

			/**
			 * Constructor.
			 *
			 * @param array $lines Lines.
			 */
			public function __construct( &$lines ) {
				$this->lines = &$lines;
			}

			/**
			 * Unused.
			 *
			 * @param string $handle  Handle.
			 * @param string $message Message.
			 * @param string $level   Level.
			 */
			public function add( $handle, $message, $level = 'notice' ) {
				$this->lines[] = $message;
				return true;
			}

			/**
			 * Records a line.
			 *
			 * @param string $level   Level.
			 * @param string $message Message.
			 * @param array  $context Context.
			 */
			public function log( $level, $message, $context = array() ) {
				$this->lines[] = $message;
			}

			// phpcs:disable Squiz.Commenting.FunctionComment.Missing
			public function emergency( $message, $context = array() ) {
				$this->log( 'emergency', $message, $context );
			}
			public function alert( $message, $context = array() ) {
				$this->log( 'alert', $message, $context );
			}
			public function critical( $message, $context = array() ) {
				$this->log( 'critical', $message, $context );
			}
			public function error( $message, $context = array() ) {
				$this->log( 'error', $message, $context );
			}
			public function warning( $message, $context = array() ) {
				$this->log( 'warning', $message, $context );
			}
			public function notice( $message, $context = array() ) {
				$this->log( 'notice', $message, $context );
			}
			public function info( $message, $context = array() ) {
				$this->log( 'info', $message, $context );
			}
			public function debug( $message, $context = array() ) {
				$this->log( 'debug', $message, $context );
			}
			// phpcs:enable
		};
		$swap   = static function () use ( $logger ) {
			return $logger;
		};
		add_filter( 'woocommerce_logging_class', $swap );
		$this->http->ok( 'POST', '/action_links', array( 'id' => 'lnk_1' ), 201 );
		$this->client( true )->post( '/action_links', array( 'description' => 'secret-body-value' ), 'k' );
		remove_filter( 'woocommerce_logging_class', $swap );
		$log = implode( "\n", $lines );
		$this->assertStringContainsString( '/action_links', $log );
		$this->assertStringContainsString( 'req_test_0', $log );
		$this->assertStringNotContainsString( self::KEY, $log );
		$this->assertStringNotContainsString( 'secret-body-value', $log );
	}

	/**
	 * R2.6: a trailing slash on the base does not double the slash.
	 */
	public function test_r2_6_trailing_slash() {
		$this->http->ok( 'GET', '/account', array() );
		$this->client( false, Http_Stub::API_BASE . '/' )->get( '/account' );
		$this->assertSame( Http_Stub::API_BASE . '/account', $this->http->requests[0]['url'] );
	}
}
