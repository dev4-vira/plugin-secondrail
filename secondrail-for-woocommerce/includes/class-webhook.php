<?php
/**
 * Webhook and return handlers, and the webhook endpoint registration.
 *
 * @package SecondRail\WooCommerce
 */

namespace SecondRail\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Handlers of `wc-api=secondrail_return` and `wc-api=secondrail_webhook`. Neither trusts what it
 * receives: the return URL's status and the webhook body only trigger a reconcile.
 */
class Webhook {

	const MAX_BODY  = 262144;
	const TOLERANCE = 300;

	// Events that carry an outcome (decision i). `payment.created` arrives mid-charge: left out.
	const EVENT_TYPES = array(
		'payment.succeeded',
		'payment.failed',
		'payment.cancelled',
		'payment.authorized',
		'payment.captured',
		'payment.unknown',
		'refund.succeeded',
		'refund.failed',
	);

	/**
	 * Hooks.
	 */
	public static function register_hooks() {
		add_action( 'woocommerce_api_secondrail_return', array( __CLASS__, 'handle_return' ) );
		add_action( 'woocommerce_api_secondrail_webhook', array( __CLASS__, 'handle_webhook' ) );
	}

	/**
	 * `GET <wc-api secondrail_return>`: redirects and stops.
	 */
	public static function handle_return() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The order key authenticates the request; the query only triggers a reconcile.
		$query = wp_unslash( $_GET );
		wp_safe_redirect( self::return_target( is_array( $query ) ? $query : array() ) );
		exit;
	}

	/**
	 * Where the return goes (R5). The query's `status` and `payment_id` are ignored.
	 *
	 * @param array $query Query string.
	 * @return string URL.
	 */
	public static function return_target( array $query ) {
		$order_id = isset( $query['order_id'] ) ? absint( $query['order_id'] ) : 0;
		$key      = isset( $query['key'] ) ? sanitize_text_field( (string) $query['key'] ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return wc_get_page_permalink( 'shop' );
		}
		try {
			$result = Reconciler::reconcile( $order );
		} catch ( Api_Error $error ) {
			$result = 'waiting';
		}
		// `open` is a charge still in flight: the webhook or the scheduled check will settle it.
		if ( 'unpaid' !== $result ) {
			return $order->get_checkout_order_received_url();
		}
		wc_add_notice( __( 'The payment was not completed. You can try again.', 'secondrail-for-woocommerce' ), 'error' );
		return wc_get_checkout_url();
	}

	/**
	 * `POST <wc-api secondrail_webhook>`: answers with a status and stops.
	 */
	public static function handle_webhook() {
		$raw    = (string) file_get_contents( 'php://input', false, null, 0, self::MAX_BODY + 1 );
		$header = isset( $_SERVER['HTTP_SECONDRAIL_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SECONDRAIL_SIGNATURE'] ) ) : '';
		status_header( self::handle( $raw, $header ) );
		exit;
	}

	/**
	 * Handles one delivery (R6) and returns the HTTP status to answer.
	 *
	 * @param string $raw       Raw body.
	 * @param string $signature `SecondRail-Signature` header.
	 * @return int
	 */
	public static function handle( $raw, $signature ) {
		if ( strlen( $raw ) > self::MAX_BODY ) {
			return 400;
		}
		$mode = self::verify( $raw, $signature );
		if ( null === $mode ) {
			return 400;
		}
		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) || ! isset( $event['type'] ) ) {
			return 400;
		}
		if ( 'ping' === $event['type'] ) {
			return 200;
		}
		if ( ! isset( $event['livemode'] ) || ( 'live' === $mode ) !== (bool) $event['livemode'] ) {
			return 200;
		}
		$object = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
		try {
			if ( 0 === strpos( $event['type'], 'payment.' ) ) {
				self::on_payment( $object, $mode );
			} elseif ( 0 === strpos( $event['type'], 'refund.' ) ) {
				self::on_refund( $event['type'], $object, $mode );
			}
		} catch ( Api_Error $error ) {
			Gateway::log_error( sprintf( 'Webhook %s could not be handled: %s %s', $event['type'], $error->error_code, $error->request_id ) );
			return 'busy' === $error->error_code ? 503 : 500;
		}
		return 200;
	}

	/**
	 * The mode whose secret signed the body, or null.
	 *
	 * @param string $raw       Raw body.
	 * @param string $signature Header value `t=<unix>,v1=<hex>[,v0=<hex>]`.
	 * @return string|null
	 */
	private static function verify( $raw, $signature ) {
		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', $signature ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] || 'v0' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}
		if ( ! $timestamp || ! $signatures || abs( time() - $timestamp ) > self::TOLERANCE ) {
			return null;
		}
		foreach ( array( 'test', 'live' ) as $mode ) {
			$endpoint = get_option( 'secondrail_wc_webhook_' . $mode );
			if ( ! is_array( $endpoint ) || empty( $endpoint['secret'] ) ) {
				continue;
			}
			$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw, (string) $endpoint['secret'] );
			foreach ( $signatures as $candidate ) {
				if ( hash_equals( $expected, $candidate ) ) {
					return $mode;
				}
			}
		}
		return null;
	}

	/**
	 * `payment.*`: reconcile the order it belongs to, when it is this install's and this mode's.
	 *
	 * @param array  $payment Payment.
	 * @param string $mode    Verified mode.
	 * @throws Api_Error When the reconcile cannot read the API.
	 */
	private static function on_payment( array $payment, $mode ) {
		$order = self::order_from( isset( $payment['metadata'] ) ? $payment['metadata'] : array(), $mode );
		if ( $order ) {
			Reconciler::reconcile( $order );
		}
	}

	/**
	 * `refund.*`: notes for a failed refund and for a refund made outside WooCommerce.
	 *
	 * @param string $type   Event type.
	 * @param array  $refund Refund.
	 * @param string $mode   Verified mode.
	 * @throws Api_Error When the payment cannot be read.
	 */
	private static function on_refund( $type, array $refund, $mode ) {
		if ( ! in_array( $type, array( 'refund.failed', 'refund.succeeded' ), true ) || empty( $refund['id'] ) ) {
			return;
		}
		$metadata = isset( $refund['metadata'] ) && is_array( $refund['metadata'] ) ? $refund['metadata'] : array();
		$source   = isset( $metadata['source'] ) ? (string) $metadata['source'] : '';
		if ( 'refund.succeeded' === $type && 'woocommerce' === $source ) {
			return;
		}
		if ( empty( $metadata['wc_order_id'] ) && ! empty( $refund['payment'] ) ) {
			$payment  = Gateway::client( $mode )->get( '/payments/' . rawurlencode( (string) $refund['payment'] ) );
			$metadata = isset( $payment['metadata'] ) && is_array( $payment['metadata'] ) ? $payment['metadata'] : array();
		}
		$order = self::order_from( $metadata, $mode );
		if ( ! $order ) {
			return;
		}
		if ( 'refund.failed' === $type ) {
			$text = sprintf(
				/* translators: 1: refund id, 2: error message */
				__( 'Refund %1$s failed in SecondRail: %2$s. Handle it manually.', 'secondrail-for-woocommerce' ),
				$refund['id'],
				isset( $refund['error']['message'] ) ? (string) $refund['error']['message'] : __( 'no reason given', 'secondrail-for-woocommerce' )
			);
		} else {
			$text = sprintf(
				/* translators: 1: amount and currency, 2: refund id */
				__( 'Refunded %1$s in SecondRail (%2$s). Record it in WooCommerce if needed.', 'secondrail-for-woocommerce' ),
				number_format( (int) $refund['amount'] / 100, 2, '.', '' ) . ' ' . strtoupper( (string) $refund['currency'] ),
				$refund['id']
			);
		}
		Reconciler::note_once( $order, 'refund:' . $refund['id'], $text );
		$order->save();
	}

	/**
	 * The order named by a payment's or refund's metadata, if it is this install's, paid with
	 * secondrail, in this mode.
	 *
	 * @param array  $metadata Metadata.
	 * @param string $mode     Verified mode.
	 * @return \WC_Order|null
	 */
	private static function order_from( $metadata, $mode ) {
		if ( ! is_array( $metadata ) || ! isset( $metadata['wc_site'], $metadata['wc_order_id'] )
			|| Gateway::site_id() !== $metadata['wc_site'] ) {
			return null;
		}
		$order = wc_get_order( absint( $metadata['wc_order_id'] ) );
		if ( ! $order || Gateway::ID !== $order->get_payment_method() || $mode !== $order->get_meta( '_secondrail_mode' ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * The URL SecondRail delivers to.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		if ( defined( 'SECONDRAIL_WC_WEBHOOK_URL' ) ) {
			return (string) constant( 'SECONDRAIL_WC_WEBHOOK_URL' );
		}
		return WC()->api_request_url( 'secondrail_webhook' );
	}

	/**
	 * Makes sure a mode has an endpoint for the current URL; replaces it when the URL changed.
	 *
	 * @param string $mode  `test` or `live`.
	 * @param bool   $force Replace it even when it looks current.
	 * @return array Stored endpoint: id, url, secret.
	 * @throws Api_Error When it cannot be created.
	 */
	public static function ensure_endpoint( $mode, $force = false ) {
		$option = 'secondrail_wc_webhook_' . $mode;
		$url    = self::webhook_url();
		$stored = get_option( $option );
		if ( ! $force && is_array( $stored ) && ! empty( $stored['id'] ) && $url === $stored['url'] ) {
			return $stored;
		}
		$client = Gateway::client( $mode );
		if ( is_array( $stored ) && ! empty( $stored['id'] ) ) {
			try {
				$client->delete( '/webhook_endpoints/' . rawurlencode( (string) $stored['id'] ) );
			} catch ( Api_Error $error ) {
				Gateway::log_error( 'Could not delete the previous webhook endpoint: ' . $error->error_code );
			}
			delete_option( $option );
		}
		$created  = $client->post(
			'/webhook_endpoints',
			array(
				'url'         => $url,
				'description' => 'SecondRail for WooCommerce',
				'event_types' => self::EVENT_TYPES,
			)
		);
		$endpoint = array(
			'id'     => isset( $created['id'] ) ? (string) $created['id'] : '',
			'url'    => $url,
			'secret' => isset( $created['secret'] ) ? (string) $created['secret'] : '',
		);
		update_option( $option, $endpoint, false );
		return $endpoint;
	}

	/**
	 * Deletes both stored endpoints, best effort.
	 */
	public static function remove_endpoints() {
		foreach ( array( 'test', 'live' ) as $mode ) {
			$option = 'secondrail_wc_webhook_' . $mode;
			$stored = get_option( $option );
			if ( is_array( $stored ) && ! empty( $stored['id'] ) ) {
				try {
					Gateway::client( $mode )->delete( '/webhook_endpoints/' . rawurlencode( (string) $stored['id'] ) );
				} catch ( Api_Error $error ) {
					Gateway::log_error( 'Could not delete the webhook endpoint: ' . $error->error_code );
				}
			}
			delete_option( $option );
		}
	}
}
