<?php
/**
 * The SecondRail payment gateway.
 *
 * @package SecondRail\WooCommerce
 */

namespace SecondRail\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce payment gateway: settings, `process_payment` and `process_refund`.
 */
class Gateway extends \WC_Payment_Gateway {

	const ID         = 'secondrail';
	const OPTION     = 'woocommerce_secondrail_settings';
	const CURRENCIES = array( 'USD', 'EUR', 'GBP', 'CAD', 'MXN', 'PEN' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'SecondRail', 'secondrail-for-woocommerce' );
		$this->method_description = __( 'Card payments on SecondRail\'s hosted payment page. No card field is rendered on the store.', 'secondrail-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
	}

	/**
	 * Hooks.
	 */
	public static function register_hooks() {
		add_action( 'admin_post_secondrail_wc_reconnect', array( __CLASS__, 'handle_reconnect' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . self::ID, array( __CLASS__, 'save_settings' ) );
	}

	/**
	 * Saves the settings form of the gateway registered in WooCommerce.
	 */
	public static function save_settings() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( isset( $gateways[ self::ID ] ) ) {
			$gateways[ self::ID ]->process_admin_options();
		}
	}

	/**
	 * Saves the form, then checks the key and ensures the webhook for the current mode (R8).
	 * A failure is a settings error; the form is still saved.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		$mode  = self::current_mode();
		$key   = self::secret_key( $mode );
		if ( 0 !== strpos( $key, 'live' === $mode ? 'sk_live_' : 'sk_test_' ) ) {
			$this->settings_error(
				'live' === $mode
					? __( 'Live mode needs a live secret key (sk_live_…).', 'secondrail-for-woocommerce' )
					: __( 'Test mode needs a test secret key (sk_test_…).', 'secondrail-for-woocommerce' )
			);
			return $saved;
		}
		try {
			$account = self::client( $mode )->get( '/account' );
			update_option(
				'secondrail_wc_account_' . $mode,
				array(
					'name'     => isset( $account['name'] ) ? (string) $account['name'] : '',
					'livemode' => ! empty( $account['livemode'] ),
				),
				false
			);
			Webhook::ensure_endpoint( $mode );
		} catch ( Api_Error $error ) {
			$this->settings_error( $error->getMessage() );
		}
		return $saved;
	}

	/**
	 * Records a settings error and shows it on the settings page.
	 *
	 * @param string $message Message.
	 */
	private function settings_error( $message ) {
		$this->add_error( $message );
		if ( class_exists( '\WC_Admin_Settings' ) ) {
			\WC_Admin_Settings::add_error( $message );
		}
	}

	/**
	 * "Reconnect webhook": replaces the current mode's endpoint. Needs the nonce and
	 * `manage_woocommerce`.
	 *
	 * @param array $query Request query.
	 * @return bool Whether the endpoint was replaced.
	 */
	public static function reconnect_webhook( array $query ) {
		$nonce = isset( $query['_wpnonce'] ) ? sanitize_text_field( (string) $query['_wpnonce'] ) : '';
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $nonce, 'secondrail_wc_reconnect' ) ) {
			return false;
		}
		try {
			Webhook::ensure_endpoint( self::current_mode(), true );
		} catch ( Api_Error $error ) {
			self::log_error( 'Could not reconnect the webhook: ' . $error->error_code );
			return false;
		}
		return true;
	}

	/**
	 * `admin-post.php?action=secondrail_wc_reconnect`.
	 */
	public static function handle_reconnect() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reconnect_webhook() verifies the nonce.
		$query = wp_unslash( $_GET );
		$done  = self::reconnect_webhook( is_array( $query ) ? $query : array() );
		wp_safe_redirect(
			add_query_arg(
				'secondrail_reconnected',
				$done ? '1' : '0',
				admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . self::ID )
			)
		);
		exit;
	}

	/**
	 * The settings form, then a read-only box with the account and the webhook.
	 */
	public function admin_options() {
		parent::admin_options();
		$mode     = self::current_mode();
		$account  = get_option( 'secondrail_wc_account_' . $mode );
		$endpoint = get_option( 'secondrail_wc_webhook_' . $mode );
		$link     = wp_nonce_url( admin_url( 'admin-post.php?action=secondrail_wc_reconnect' ), 'secondrail_wc_reconnect' );
		echo '<h3>' . esc_html__( 'Connection', 'secondrail-for-woocommerce' ) . '</h3><table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Account', 'secondrail-for-woocommerce' ) . '</th><td>';
		if ( is_array( $account ) && '' !== $account['name'] ) {
			echo esc_html( $account['name'] ) . ' &middot; ' . esc_html( $account['livemode'] ? __( 'live', 'secondrail-for-woocommerce' ) : __( 'test', 'secondrail-for-woocommerce' ) );
		} else {
			esc_html_e( 'Not connected. Save a secret key to connect.', 'secondrail-for-woocommerce' );
		}
		echo '</td></tr><tr><th scope="row">' . esc_html__( 'Webhook', 'secondrail-for-woocommerce' ) . '</th><td>';
		if ( is_array( $endpoint ) && ! empty( $endpoint['id'] ) ) {
			echo esc_html__( 'Registered', 'secondrail-for-woocommerce' ) . ' &middot; <code>' . esc_html( $endpoint['url'] ) . '</code>';
		} else {
			esc_html_e( 'Not registered', 'secondrail-for-woocommerce' );
		}
		echo ' <a class="button" href="' . esc_url( $link ) . '">' . esc_html__( 'Reconnect webhook', 'secondrail-for-woocommerce' ) . '</a>';
		echo '</td></tr></tbody></table>';
	}

	/**
	 * A secret key input: always rendered empty, with a placeholder when a key is stored.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_secret_key_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$stored    = '' !== (string) $this->get_option( $key );
		$hint      = $stored ? __( 'A key is stored. Leave empty to keep it.', 'secondrail-for-woocommerce' ) : '';
		return sprintf(
			'<tr valign="top"><th scope="row" class="titledesc"><label for="%1$s">%2$s</label></th><td class="forminp"><fieldset><input class="input-text regular-input" type="password" name="%1$s" id="%1$s" value="" autocomplete="off" placeholder="%3$s" /></fieldset></td></tr>',
			esc_attr( $field_key ),
			esc_html( $data['title'] ),
			esc_attr( $hint )
		);
	}

	/**
	 * Keeps the stored key when the field is submitted empty.
	 *
	 * @param string $key   Field key.
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function validate_secret_key_field( $key, $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		return '' === $value ? (string) $this->get_option( $key ) : $value;
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable', 'secondrail-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable SecondRail', 'secondrail-for-woocommerce' ),
				'default' => 'no',
			),
			'title'           => array(
				'title'   => __( 'Title', 'secondrail-for-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Credit or debit card', 'secondrail-for-woocommerce' ),
			),
			'description'     => array(
				'title'   => __( 'Description', 'secondrail-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'You will be taken to a secure page to complete your payment.', 'secondrail-for-woocommerce' ),
			),
			'api_base'        => array(
				'title'       => __( 'API base URL', 'secondrail-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Includes the version segment, for example https://api.example.com/v1', 'secondrail-for-woocommerce' ),
				'default'     => '',
			),
			'testmode'        => array(
				'title'   => __( 'Test mode', 'secondrail-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Use the test secret key', 'secondrail-for-woocommerce' ),
				'default' => 'yes',
			),
			'test_secret_key' => array(
				'title'   => __( 'Test secret key', 'secondrail-for-woocommerce' ),
				'type'    => 'secret_key',
				'default' => '',
			),
			'live_secret_key' => array(
				'title'   => __( 'Live secret key', 'secondrail-for-woocommerce' ),
				'type'    => 'secret_key',
				'default' => '',
			),
			'debug'           => array(
				'title'   => __( 'Debug log', 'secondrail-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Log every SecondRail API call (never keys or bodies)', 'secondrail-for-woocommerce' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Enabled, configured for the current mode, and the store currency is supported.
	 *
	 * @return bool
	 */
	public function is_available() {
		$options = self::options();
		return parent::is_available()
			&& ! empty( $options['api_base'] )
			&& '' !== self::secret_key( self::current_mode() )
			&& in_array( get_woocommerce_currency(), self::CURRENCIES, true );
	}

	/**
	 * Sends the shopper to the hosted payment page, or settles a previous attempt first.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}
		if ( $order->get_meta( '_secondrail_link_id' ) ) {
			try {
				$previous = $this->settle_previous( $order );
			} catch ( Api_Error $error ) {
				return $this->start_failed( $error );
			}
			if ( 'done' === $previous ) {
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}
			if ( 'blocked' === $previous ) {
				wc_add_notice( __( 'We could not confirm your previous payment attempt yet. Try again in a few minutes.', 'secondrail-for-woocommerce' ), 'error' );
				return array( 'result' => 'failure' );
			}
		}
		return $this->open_link( $order );
	}

	/**
	 * Refunds through SecondRail (R7). The key follows the total refunded on the order, so a
	 * repeat after a lost answer replays the first refund instead of refunding twice.
	 *
	 * @param int        $order_id Order id.
	 * @param float|null $amount   Amount.
	 * @param string     $reason   Reason.
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'secondrail_refund', __( 'Order not found.', 'secondrail-for-woocommerce' ) );
		}
		$minor = null === $amount ? 0 : self::minor( $amount );
		if ( $minor <= 0 ) {
			return new \WP_Error( 'secondrail_refund', __( 'Enter an amount to refund.', 'secondrail-for-woocommerce' ) );
		}
		$payment_id = (string) $order->get_meta( '_secondrail_payment_id' );
		if ( '' === $payment_id ) {
			return new \WP_Error( 'secondrail_refund', __( 'This order has no SecondRail payment to refund.', 'secondrail-for-woocommerce' ) );
		}
		$site = self::site_id();
		$mode = $order->get_meta( '_secondrail_mode' );
		// Read from the data store, not get_total_refunded(): that caches a total that includes this
		// refund, and WooCommerce does not clear it when it deletes a refund the gateway refused.
		$refunded = (float) $order->get_data_store()->get_total_refunded( $order );
		$failures = (int) $order->get_meta( '_secondrail_refund_failures' );
		try {
			$refund = self::client( $mode ? $mode : self::current_mode() )->post(
				'/refunds',
				array(
					'payment'  => $payment_id,
					'amount'   => $minor,
					'reason'   => 'requested_by_customer',
					'note'     => (string) $reason,
					'metadata' => array(
						'source'      => 'woocommerce',
						'wc_order_id' => (string) $order->get_id(),
						'wc_site'     => $site,
					),
				),
				'wc-refund-' . $site . '-' . $order->get_id() . '-' . self::minor( $refunded ) . '-' . $failures
			);
		} catch ( Api_Error $error ) {
			// The key stays as it is when the outcome is unknown, so a retry cannot refund twice.
			$unknown = $error->status >= 500
				|| in_array( $error->error_code, array( 'network_error', 'invalid_response', 'idempotency_conflict', 'idempotency_in_progress' ), true );
			if ( $unknown ) {
				return new \WP_Error( 'secondrail_refund', __( 'The refund outcome is unknown. Check the payment in SecondRail before trying again.', 'secondrail-for-woocommerce' ) );
			}
			return new \WP_Error( 'secondrail_refund', $error->getMessage() );
		}
		// The API answers 201 whatever the outcome: a refused refund moved no money. SecondRail
		// replays the stored refusal to the same key for 24 hours, so the counter moves the key.
		$status = isset( $refund['status'] ) ? (string) $refund['status'] : '';
		if ( in_array( $status, array( 'failed', 'cancelled' ), true ) ) {
			$order->update_meta_data( '_secondrail_refund_failures', $failures + 1 );
			$order->save();
			return new \WP_Error(
				'secondrail_refund',
				! empty( $refund['error']['message'] )
					? (string) $refund['error']['message']
					: __( 'SecondRail could not make the refund.', 'secondrail-for-woocommerce' )
			);
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: refund id, 2: amount, 3: refund status */
				__( 'Refund %1$s of %2$s sent to SecondRail (%3$s).', 'secondrail-for-woocommerce' ),
				isset( $refund['id'] ) ? $refund['id'] : '',
				number_format( $minor / 100, 2, '.', '' ),
				isset( $refund['status'] ) ? $refund['status'] : ''
			)
		);
		return true;
	}

	/**
	 * Step 1 of R3: the outcome of the order's previous link.
	 *
	 * @param \WC_Order $order Order.
	 * @return string `done` (paid or waiting), `blocked` (a payment is still open) or `new`.
	 * @throws Api_Error When the API cannot be read.
	 */
	private function settle_previous( \WC_Order $order ) {
		$mode   = $order->get_meta( '_secondrail_mode' );
		$client = self::client( $mode ? $mode : self::current_mode() );
		for ( $pass = 0; $pass < 2; $pass++ ) {
			$open   = array();
			$result = Reconciler::reconcile( $order, $open );
			if ( in_array( $result, array( 'paid', 'review', 'waiting' ), true ) ) {
				return 'done';
			}
			if ( 'open' !== $result ) {
				break;
			}
			if ( 1 === $pass ) {
				return 'blocked';
			}
			foreach ( $open as $payment_id ) {
				try {
					$client->post( '/payments/' . rawurlencode( $payment_id ) . '/cancel' );
				} catch ( Api_Error $error ) {
					if ( 'invalid_state' !== $error->error_code ) {
						throw $error;
					}
				}
			}
		}
		self::revoke( $client, (string) $order->get_meta( '_secondrail_link_id' ) );
		return 'new';
	}

	/**
	 * Steps 2 and 3 of R3: creates the link and saves it on the order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	private function open_link( \WC_Order $order ) {
		$mode     = self::current_mode();
		$site     = self::site_id();
		$attempt  = (int) $order->get_meta( '_secondrail_attempt' ) + 1;
		$external = (string) $order->get_order_number();
		$body     = array(
			'purpose'     => 'payment_link',
			'amount'      => self::minor( $order->get_total() ),
			'currency'    => $order->get_currency(),
			/* translators: 1: order number, 2: store name */
			'description' => sprintf( __( 'Order %1$s - %2$s', 'secondrail-for-woocommerce' ), $external, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'external_id' => $external,
			'metadata'    => array(
				'wc_order_id'    => (string) $order->get_id(),
				'wc_site'        => $site,
				'source'         => 'woocommerce',
				'plugin_version' => SECONDRAIL_WC_VERSION,
			),
			'return_url'  => add_query_arg(
				array(
					'order_id' => (string) $order->get_id(),
					'key'      => $order->get_order_key(),
				),
				WC()->api_request_url( 'secondrail_return' )
			),
			'cancel_url'  => wc_get_checkout_url(),
			'auto_return' => true,
			'expires_in'  => 1800,
		);
		try {
			$client = self::client( $mode );
			$link   = self::create_link( $client, $body, $site, $order, $attempt );
		} catch ( Api_Error $error ) {
			return $this->start_failed( $error );
		}
		if ( ! isset( $link['auto_return'], $link['external_id'], $link['id'], $link['url'] )
			|| true !== $link['auto_return'] || $external !== $link['external_id'] ) {
			if ( isset( $link['id'] ) ) {
				self::revoke( $client, (string) $link['id'] );
			}
			self::log_error( 'This SecondRail API does not support hosted checkout fields' );
			wc_add_notice( __( 'We could not start the payment. Please try again.', 'secondrail-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$order->update_meta_data( '_secondrail_link_id', $link['id'] );
		$order->update_meta_data( '_secondrail_attempt', $attempt );
		$order->update_meta_data( '_secondrail_mode', $mode );
		/* translators: %s: link id */
		$order->add_order_note( sprintf( __( 'SecondRail payment page opened (%s).', 'secondrail-for-woocommerce' ), $link['id'] ) );
		$order->save();
		Reconciler::schedule( $order->get_id() );
		return array(
			'result'   => 'success',
			'redirect' => $link['url'],
		);
	}

	/**
	 * `POST /action_links`; an `idempotency_conflict` (the order changed since a lost request)
	 * moves to the next attempt once.
	 *
	 * @param Api_Client $client  Client.
	 * @param array      $body    Request body.
	 * @param string     $site    Site id.
	 * @param \WC_Order  $order   Order.
	 * @param int        $attempt In: first attempt; out: the attempt that created the link.
	 * @return array
	 * @throws Api_Error When the link cannot be created.
	 */
	private static function create_link( Api_Client $client, array $body, $site, \WC_Order $order, &$attempt ) {
		try {
			return $client->post( '/action_links', $body, self::link_key( $site, $order, $attempt ) );
		} catch ( Api_Error $error ) {
			if ( 'idempotency_conflict' !== $error->error_code ) {
				throw $error;
			}
		}
		++$attempt;
		return $client->post( '/action_links', $body, self::link_key( $site, $order, $attempt ) );
	}

	/**
	 * Idempotency key of a link attempt.
	 *
	 * @param string    $site    Site id.
	 * @param \WC_Order $order   Order.
	 * @param int       $attempt Attempt.
	 * @return string
	 */
	private static function link_key( $site, \WC_Order $order, $attempt ) {
		return 'wc-' . $site . '-' . $order->get_id() . '-' . $attempt;
	}

	/**
	 * Revokes a link; a 404 or a 409 `invalid_state` means it is already unusable.
	 *
	 * @param Api_Client $client  Client.
	 * @param string     $link_id Link id.
	 */
	private static function revoke( Api_Client $client, $link_id ) {
		if ( '' === $link_id ) {
			return;
		}
		try {
			$client->post( '/action_links/' . rawurlencode( $link_id ) . '/revoke' );
		} catch ( Api_Error $error ) {
			if ( 404 !== $error->status && 'invalid_state' !== $error->error_code ) {
				self::log_error( 'Could not revoke the previous SecondRail link: ' . $error->error_code );
			}
		}
	}

	/**
	 * A link could not be created: log it and tell the shopper.
	 *
	 * @param Api_Error $error Error.
	 * @return array
	 */
	private function start_failed( Api_Error $error ) {
		self::log_error( sprintf( 'Could not start the payment: %s %s %s', $error->status, $error->error_code, $error->request_id ) );
		wc_add_notice( __( 'We could not start the payment. Please try again.', 'secondrail-for-woocommerce' ), 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * Logs an error (never a key or a body).
	 *
	 * @param string $message Message.
	 */
	public static function log_error( $message ) {
		wc_get_logger()->error( $message, array( 'source' => 'secondrail' ) );
	}

	/**
	 * Stored settings.
	 *
	 * @return array
	 */
	public static function options() {
		$options = get_option( self::OPTION, array() );
		return is_array( $options ) ? $options : array();
	}

	/**
	 * `test` or `live`, from the "Test mode" setting.
	 *
	 * @return string
	 */
	public static function current_mode() {
		$options = self::options();
		return isset( $options['testmode'] ) && 'no' === $options['testmode'] ? 'live' : 'test';
	}

	/**
	 * Secret key of a mode.
	 *
	 * @param string $mode `test` or `live`.
	 * @return string
	 */
	public static function secret_key( $mode ) {
		$options = self::options();
		$field   = 'live' === $mode ? 'live_secret_key' : 'test_secret_key';
		return isset( $options[ $field ] ) ? trim( (string) $options[ $field ] ) : '';
	}

	/**
	 * API client of a mode.
	 *
	 * @param string $mode `test` or `live`.
	 * @return Api_Client
	 * @throws Api_Error When the base URL or the key is missing.
	 */
	public static function client( $mode ) {
		$options = self::options();
		$base    = isset( $options['api_base'] ) ? trim( (string) $options['api_base'] ) : '';
		$key     = self::secret_key( $mode );
		if ( '' === $base || '' === $key ) {
			$error = new Api_Error( 0, 'not_configured', __( 'SecondRail is not configured.', 'secondrail-for-woocommerce' ) );
			throw $error;
		}
		return new Api_Client( $base, $key, isset( $options['debug'] ) && 'yes' === $options['debug'] );
	}

	/**
	 * Random identifier of this install (decision f), created once.
	 *
	 * @return string
	 */
	public static function site_id() {
		$site = get_option( 'secondrail_wc_site_id' );
		if ( ! is_string( $site ) || '' === $site ) {
			$site = wp_generate_uuid4();
			update_option( 'secondrail_wc_site_id', $site );
		}
		return $site;
	}

	/**
	 * An amount in minor units.
	 *
	 * @param string|float $amount Amount.
	 * @return int
	 */
	public static function minor( $amount ) {
		return (int) round( (float) $amount * 100 );
	}
}
