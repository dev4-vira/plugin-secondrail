<?php
/**
 * Order state from the SecondRail API.
 *
 * @package SecondRail\WooCommerce
 */

namespace SecondRail\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The one function that decides an order's state from the API (decision e), and the scheduled
 * check that calls it when nobody else does.
 */
class Reconciler {

	const ACTION = 'secondrail_wc_reconcile';
	const GROUP  = 'secondrail';

	// Seconds a `processing` payment is a charge in flight rather than a payment waiting.
	const IN_FLIGHT = 120;

	/**
	 * Hooks.
	 */
	public static function register_hooks() {
		add_action( self::ACTION, array( __CLASS__, 'run_check' ), 10, 2 );
	}

	/**
	 * Reads the order's payments and applies the first row of the R4 table that fits.
	 *
	 * @param \WC_Order $order    Order.
	 * @param string[]  $open_ids Out: ids of payments still `requires_action` or `created`.
	 * @return string `paid`, `review`, `waiting`, `open`, `unpaid`, or `ignored` for another gateway.
	 * @throws Api_Error When the API cannot be read, or `busy` when another run holds the order.
	 */
	public static function reconcile( \WC_Order $order, &$open_ids = array() ) {
		$open_ids = array();
		if ( Gateway::ID !== $order->get_payment_method() ) {
			return 'ignored';
		}
		// The return and the webhook arrive together, each with its own copy of the order. A
		// transient is not atomic, but it narrows the overlap to the gap between these two calls.
		$lock = 'secondrail_wc_lock_' . $order->get_id();
		if ( false !== get_transient( $lock ) ) {
			$error = new Api_Error( 0, 'busy', 'Another reconcile of this order is running.' );
			throw $error;
		}
		set_transient( $lock, 1, MINUTE_IN_SECONDS );
		try {
			// Re-read under the lock: the caller's copy may predate a run that just paid the order.
			$fresh = wc_get_order( $order->get_id() );
			return self::apply( $fresh ? $fresh : $order, $open_ids );
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * The R4 table, applied under the order's lock.
	 *
	 * @param \WC_Order $order    Order, freshly read.
	 * @param string[]  $open_ids Out: ids of payments still `requires_action` or `created`.
	 * @return string
	 * @throws Api_Error When the API cannot be read.
	 */
	private static function apply( \WC_Order $order, &$open_ids ) {
		$mode     = $order->get_meta( '_secondrail_mode' );
		$client   = Gateway::client( $mode ? $mode : Gateway::current_mode() );
		$payments = self::payments_of( $order, $client );
		$by       = array();
		foreach ( $payments as $payment ) {
			$status = $payment['status'];
			// A charge in flight is open, not waiting: putting the order on hold would email the
			// shopper for a payment about to succeed, and a decline would then turn it Failed.
			if ( 'processing' === $status && self::in_flight( $payment ) ) {
				$status = 'in_flight';
			}
			$by[ $status ][] = $payment;
		}
		$succeeded = isset( $by['succeeded'] ) ? $by['succeeded'] : array();
		$paid_id   = (string) $order->get_meta( '_secondrail_payment_id' );

		if ( $order->is_paid() || $order->has_status( 'refunded' ) ) {
			self::note_extra_payments( $order, $succeeded, $paid_id );
			$order->delete_meta_data( '_secondrail_waiting' );
			$order->save();
			return 'paid';
		}
		if ( $succeeded ) {
			$first = $succeeded[0];
			$order->update_meta_data( '_secondrail_payment_id', $first['id'] );
			$order->delete_meta_data( '_secondrail_waiting' );
			self::note_extra_payments( $order, $succeeded, $first['id'] );
			if ( Gateway::minor( $order->get_total() ) === (int) $first['amount']
				&& strtoupper( (string) $first['currency'] ) === strtoupper( $order->get_currency() ) ) {
				self::note_once(
					$order,
					'paid:' . $first['id'],
					sprintf(
						/* translators: 1: payment id, 2: card brand, 3: last 4 digits */
						__( 'SecondRail payment %1$s succeeded (%2$s ending in %3$s).', 'secondrail-for-woocommerce' ),
						$first['id'],
						isset( $first['card']['brand'] ) ? (string) $first['card']['brand'] : 'card',
						isset( $first['card']['last4'] ) ? (string) $first['card']['last4'] : '----'
					)
				);
				$order->save();
				$order->payment_complete( $first['id'] );
				return 'paid';
			}
			self::note_once( $order, 'review:' . $first['id'], __( 'Paid amount differs from the order total. Review before fulfilling.', 'secondrail-for-woocommerce' ) );
			$order->set_status( 'on-hold' );
			$order->save();
			return 'review';
		}
		foreach ( array( 'authorized', 'processing', 'unknown' ) as $status ) {
			if ( empty( $by[ $status ] ) ) {
				continue;
			}
			$waiting = $by[ $status ][0];
			$text    = 'authorized' === $status
				? __( 'Authorized in SecondRail; capture it there.', 'secondrail-for-woocommerce' )
				: __( 'Waiting for SecondRail to confirm the payment.', 'secondrail-for-woocommerce' );
			self::note_once( $order, $status . ':' . $waiting['id'], $text );
			$order->update_meta_data( '_secondrail_waiting', 'yes' );
			$order->set_status( 'on-hold' );
			$order->save();
			return 'waiting';
		}
		foreach ( array( 'requires_action', 'created', 'in_flight' ) as $status ) {
			foreach ( isset( $by[ $status ] ) ? $by[ $status ] : array() as $payment ) {
				$open_ids[] = $payment['id'];
			}
		}
		if ( $open_ids ) {
			return 'open';
		}
		if ( $order->has_status( 'on-hold' ) && $order->get_meta( '_secondrail_waiting' ) ) {
			$order->delete_meta_data( '_secondrail_waiting' );
			$order->set_status( 'failed' );
		}
		foreach ( isset( $by['failed'] ) ? $by['failed'] : array() as $payment ) {
			self::note_once(
				$order,
				'failed:' . $payment['id'],
				sprintf(
					/* translators: 1: payment id, 2: error message */
					__( 'Payment %1$s failed: %2$s', 'secondrail-for-woocommerce' ),
					$payment['id'],
					isset( $payment['error']['message'] ) ? (string) $payment['error']['message'] : __( 'no reason given', 'secondrail-for-woocommerce' )
				)
			);
		}
		$order->save();
		return 'unpaid';
	}

	/**
	 * Whether a `processing` payment was created two minutes ago or less. An unreadable
	 * `created_at` counts as old.
	 *
	 * @param array $payment Payment.
	 * @return bool
	 */
	private static function in_flight( array $payment ) {
		$created = isset( $payment['created_at'] ) ? strtotime( (string) $payment['created_at'] ) : false;
		return false !== $created && time() - $created <= self::IN_FLIGHT;
	}

	/**
	 * The order's payments (decision f: same site and same order id), earliest first.
	 *
	 * @param \WC_Order  $order  Order.
	 * @param Api_Client $client Client of the order's mode.
	 * @return array[]
	 * @throws Api_Error When the API cannot be read.
	 */
	private static function payments_of( \WC_Order $order, Api_Client $client ) {
		$listed = $client->get(
			'/payments',
			array(
				'external_id' => (string) $order->get_order_number(),
				'limit'       => 100,
			)
		);
		$site   = Gateway::site_id();
		$mine   = array();
		foreach ( $listed as $payment ) {
			$meta = isset( $payment['metadata'] ) && is_array( $payment['metadata'] ) ? $payment['metadata'] : array();
			if ( isset( $payment['id'], $payment['status'], $meta['wc_site'], $meta['wc_order_id'] )
				&& $site === $meta['wc_site'] && (string) $order->get_id() === (string) $meta['wc_order_id'] ) {
				$mine[] = $payment;
			}
		}
		usort(
			$mine,
			static function ( $a, $b ) {
				return strcmp( (string) $a['created_at'], (string) $b['created_at'] );
			}
		);
		return $mine;
	}

	/**
	 * Row 1's note for every succeeded payment other than the one that paid the order.
	 *
	 * @param \WC_Order $order     Order.
	 * @param array[]   $succeeded Succeeded payments.
	 * @param string    $paid_id   Payment that paid the order.
	 */
	private static function note_extra_payments( \WC_Order $order, array $succeeded, $paid_id ) {
		foreach ( $succeeded as $payment ) {
			if ( $payment['id'] !== $paid_id ) {
				self::note_once(
					$order,
					'second:' . $payment['id'],
					sprintf(
						/* translators: %s: payment id */
						__( 'Second successful payment %s for this order. Refund it in SecondRail.', 'secondrail-for-woocommerce' ),
						$payment['id']
					)
				);
			}
		}
	}

	/**
	 * Adds a note once per key (`_secondrail_noted`).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Note key.
	 * @param string    $text  Note.
	 */
	public static function note_once( \WC_Order $order, $key, $text ) {
		$noted = $order->get_meta( '_secondrail_noted' );
		$noted = is_array( $noted ) ? $noted : array();
		if ( in_array( $key, $noted, true ) ) {
			return;
		}
		$noted[] = $key;
		$order->update_meta_data( '_secondrail_noted', $noted );
		$order->add_order_note( $text );
	}

	/**
	 * Schedules the check of an order, replacing any check still pending for it.
	 *
	 * @param int $order_id Order id.
	 * @param int $run      Run counter.
	 * @param int $delay    Seconds from now.
	 */
	public static function schedule( $order_id, $run = 0, $delay = 15 * MINUTE_IN_SECONDS ) {
		for ( $previous = 0; $previous <= 3; $previous++ ) {
			as_unschedule_all_actions( self::ACTION, self::args( $order_id, $previous ), self::GROUP );
		}
		as_schedule_single_action( time() + $delay, self::ACTION, self::args( $order_id, $run ), self::GROUP );
	}

	/**
	 * The scheduled check: reconcile, and back off (1 h, 6 h, 24 h) while the outcome is pending.
	 *
	 * @param int $order_id Order id.
	 * @param int $run      Run counter, from 0.
	 */
	public static function run_check( $order_id, $run = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		try {
			$result = self::reconcile( $order );
		} catch ( Api_Error $error ) {
			$result = 'error';
		}
		$delays = array( HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS );
		if ( in_array( $result, array( 'waiting', 'open', 'review', 'error' ), true ) && $run < count( $delays ) ) {
			self::schedule( $order_id, $run + 1, $delays[ $run ] );
		}
	}

	/**
	 * Unschedules every check of the plugin.
	 */
	public static function unschedule_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION );
		}
	}

	/**
	 * Action arguments.
	 *
	 * @param int $order_id Order id.
	 * @param int $run      Run counter.
	 * @return array
	 */
	private static function args( $order_id, $run ) {
		return array(
			'order_id' => (int) $order_id,
			'run'      => (int) $run,
		);
	}
}
