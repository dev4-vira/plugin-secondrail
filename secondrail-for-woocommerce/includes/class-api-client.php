<?php
/**
 * SecondRail API client.
 *
 * @package SecondRail\WooCommerce
 */

namespace SecondRail\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client for the SecondRail API: JSON in and out, the `{code, message, data, meta}` envelope,
 * no redirect following. One instance per mode, built with that mode's key.
 */
class Api_Client {

	const API_VERSION = '2026-11-01';

	/**
	 * API base URL, with the version segment.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Secret key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Whether every call is logged.
	 *
	 * @var bool
	 */
	private $debug;

	/**
	 * Constructor.
	 *
	 * @param string $base  API base URL.
	 * @param string $key   Secret key.
	 * @param bool   $debug Log every call.
	 */
	public function __construct( $base, $key, $debug = false ) {
		$this->base  = rtrim( (string) $base, '/' );
		$this->key   = (string) $key;
		$this->debug = (bool) $debug;
	}

	/**
	 * GET.
	 *
	 * @param string $path  Path below the base.
	 * @param array  $query Query parameters.
	 * @return array Envelope data.
	 * @throws Api_Error On any failure.
	 */
	public function get( $path, array $query = array() ) {
		if ( $query ) {
			$path .= '?' . http_build_query( $query, '', '&' );
		}
		return $this->request( 'GET', $path );
	}

	/**
	 * POST.
	 *
	 * @param string $path            Path below the base.
	 * @param array  $body            JSON body.
	 * @param string $idempotency_key Idempotency key; a random UUID when empty.
	 * @return array Envelope data.
	 * @throws Api_Error On any failure.
	 */
	public function post( $path, array $body = array(), $idempotency_key = '' ) {
		return $this->request( 'POST', $path, $body, $idempotency_key );
	}

	/**
	 * DELETE.
	 *
	 * @param string $path Path below the base.
	 * @return array Envelope data.
	 * @throws Api_Error On any failure.
	 */
	public function delete( $path ) {
		return $this->request( 'DELETE', $path );
	}

	/**
	 * Sends one request and unwraps the envelope.
	 *
	 * @param string     $method          HTTP method.
	 * @param string     $path            Path below the base.
	 * @param array|null $body            JSON body (writes).
	 * @param string     $idempotency_key Idempotency key (writes).
	 * @return array
	 * @throws Api_Error On any failure.
	 */
	private function request( $method, $path, $body = null, $idempotency_key = '' ) {
		$write   = 'GET' !== $method;
		$headers = array(
			'Authorization'      => 'Bearer ' . $this->key,
			'Accept'             => 'application/json',
			'SecondRail-Version' => self::API_VERSION,
			'User-Agent'         => 'SecondRail-WooCommerce/' . SECONDRAIL_WC_VERSION,
		);
		$args    = array(
			'method'      => $method,
			'timeout'     => $write ? 45 : 15,
			'redirection' => 0,
		);
		if ( $write ) {
			$headers['Content-Type']    = 'application/json';
			$headers['Idempotency-Key'] = '' !== $idempotency_key ? $idempotency_key : wp_generate_uuid4();
			$args['body']               = wp_json_encode( $body ? $body : new \stdClass() );
		}
		$args['headers'] = $headers;
		$url             = $this->base . '/' . ltrim( $path, '/' );
		$route           = (string) wp_parse_url( $url, PHP_URL_PATH );

		// Errors are built before `throw`: they carry data for the caller and are never printed.
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->log( $method, $route, 0, 'network_error', '' );
			$error = new Api_Error( 0, 'network_error', $response->get_error_message() );
			throw $error;
		}
		$status   = (int) wp_remote_retrieve_response_code( $response );
		$envelope = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $envelope ) || ! isset( $envelope['code'] ) || ! is_string( $envelope['code'] ) ) {
			$this->log( $method, $route, $status, 'invalid_response', '' );
			$error = new Api_Error( $status, 'invalid_response', 'The SecondRail API answered something that is not its envelope.' );
			throw $error;
		}
		$request_id = isset( $envelope['meta']['request_id'] ) ? (string) $envelope['meta']['request_id'] : '';
		$this->log( $method, $route, $status, $envelope['code'], $request_id );
		if ( $status < 200 || $status >= 300 || 'ok' !== $envelope['code'] ) {
			$error = new Api_Error(
				$status,
				$envelope['code'],
				isset( $envelope['message'] ) ? (string) $envelope['message'] : '',
				isset( $envelope['data'] ) ? $envelope['data'] : null,
				$request_id
			);
			throw $error;
		}
		return isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
	}

	/**
	 * Logs one call: never a key, a body or a signature.
	 *
	 * @param string $method     HTTP method.
	 * @param string $route      URL path.
	 * @param int    $status     HTTP status.
	 * @param string $code       Envelope code.
	 * @param string $request_id Request id.
	 */
	private function log( $method, $route, $status, $code, $request_id ) {
		$failed = 'ok' !== $code;
		if ( ! $this->debug && ! $failed ) {
			return;
		}
		$message = sprintf( '%s %s -> %d %s %s', $method, $route, $status, $code, $request_id );
		wc_get_logger()->log( $failed ? 'error' : 'info', trim( $message ), array( 'source' => 'secondrail' ) );
	}
}

/**
 * A failed SecondRail API call: HTTP status, envelope code, message, data and request id.
 */
class Api_Error extends \Exception {

	/**
	 * HTTP status (0 when the request did not reach the API).
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Envelope code, or `network_error` / `invalid_response`.
	 *
	 * @var string
	 */
	public $error_code;

	/**
	 * Envelope data.
	 *
	 * @var mixed
	 */
	public $data;

	/**
	 * `meta.request_id`.
	 *
	 * @var string
	 */
	public $request_id;

	/**
	 * Constructor.
	 *
	 * @param int    $status     HTTP status.
	 * @param string $error_code Envelope code.
	 * @param string $message    Envelope message.
	 * @param mixed  $data       Envelope data.
	 * @param string $request_id Request id.
	 */
	public function __construct( $status, $error_code, $message = '', $data = null, $request_id = '' ) {
		parent::__construct( $message );
		$this->status     = (int) $status;
		$this->error_code = (string) $error_code;
		$this->data       = $data;
		$this->request_id = (string) $request_id;
	}
}
