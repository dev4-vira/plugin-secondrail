<?php
/**
 * Answers every HTTP request through `pre_http_request`, so no test reaches the network.
 *
 * @package SecondRail\WooCommerce\Tests
 */

namespace SecondRail\WooCommerce\Tests;

/**
 * Queue of canned SecondRail responses. A request nobody queued is recorded as unexpected, gets
 * a `WP_Error`, and fails the test in `Test_Case::tear_down()`.
 */
class Http_Stub {

	const API_BASE = 'https://api.secondrail.test/v1';

	/**
	 * Every request seen: method, url, path, query, headers, body (decoded), args.
	 *
	 * @var array[]
	 */
	public $requests = array();

	/**
	 * Requests that matched nothing in the queue.
	 *
	 * @var string[]
	 */
	public $unexpected = array();

	/**
	 * Pending responses.
	 *
	 * @var array[]
	 */
	private $queue = array();

	/**
	 * Starts answering.
	 */
	public function install() {
		add_filter( 'pre_http_request', array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * Stops answering.
	 */
	public function uninstall() {
		remove_filter( 'pre_http_request', array( $this, 'handle' ), 10 );
	}

	/**
	 * Queues a success envelope.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path below the API base, without query string.
	 * @param mixed  $data   Envelope data.
	 * @param int    $status HTTP status.
	 */
	public function ok( $method, $path, $data, $status = 200 ) {
		$this->raw(
			$method,
			$path,
			$status,
			wp_json_encode(
				array(
					'code'    => 'ok',
					'message' => 'Success',
					'data'    => $data,
					'meta'    => array( 'request_id' => 'req_test_' . count( $this->queue ) ),
				)
			)
		);
	}

	/**
	 * Queues an error envelope.
	 *
	 * @param string $method  HTTP method.
	 * @param string $path    Path below the API base.
	 * @param int    $status  HTTP status.
	 * @param string $code    Envelope code.
	 * @param string $message Envelope message.
	 * @param mixed  $data    Envelope data.
	 */
	public function error( $method, $path, $status, $code, $message = 'Failed', $data = null ) {
		$this->raw(
			$method,
			$path,
			$status,
			wp_json_encode(
				array(
					'code'    => $code,
					'message' => $message,
					'data'    => $data,
					'meta'    => array( 'request_id' => 'req_error' ),
				)
			)
		);
	}

	/**
	 * Queues a raw body, or a `WP_Error` from the transport.
	 *
	 * @param string           $method HTTP method.
	 * @param string           $path   Path below the API base.
	 * @param int              $status HTTP status.
	 * @param string|\WP_Error $body   Raw body or transport error.
	 */
	public function raw( $method, $path, $status, $body ) {
		$this->queue[] = array( strtoupper( $method ), $path, $status, $body );
	}

	/**
	 * Requests not consumed yet.
	 *
	 * @return int
	 */
	public function pending() {
		return count( $this->queue );
	}

	/**
	 * Requests seen for a method and path.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path below the API base.
	 * @return array[]
	 */
	public function seen( $method, $path ) {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) use ( $method, $path ) {
					return strtoupper( $method ) === $request['method'] && $path === $request['path'];
				}
			)
		);
	}

	/**
	 * The `pre_http_request` filter.
	 *
	 * @param mixed  $pre  Previous value.
	 * @param array  $args Request arguments.
	 * @param string $url  URL.
	 * @return array|\WP_Error
	 */
	public function handle( $pre, $args, $url ) {
		$method = strtoupper( isset( $args['method'] ) ? $args['method'] : 'GET' );
		$full   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base   = (string) wp_parse_url( self::API_BASE, PHP_URL_PATH );
		$path   = 0 === strpos( $url, self::API_BASE ) ? substr( $full, strlen( $base ) ) : $url;
		$query  = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$body             = isset( $args['body'] ) ? $args['body'] : null;
		$this->requests[] = array(
			'method'  => $method,
			'url'     => $url,
			'path'    => $path,
			'query'   => $query,
			'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
			'raw'     => $body,
			'body'    => is_string( $body ) ? json_decode( $body, true ) : $body,
			'args'    => $args,
		);
		foreach ( $this->queue as $index => $item ) {
			if ( $item[0] === $method && $item[1] === $path ) {
				array_splice( $this->queue, $index, 1 );
				if ( is_wp_error( $item[3] ) ) {
					return $item[3];
				}
				return array(
					'headers'  => array(),
					'body'     => $item[3],
					'response' => array(
						'code'    => $item[2],
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		$this->unexpected[] = $method . ' ' . $url;
		return new \WP_Error( 'http_stub_unexpected', 'Unexpected request: ' . $method . ' ' . $url );
	}
}
