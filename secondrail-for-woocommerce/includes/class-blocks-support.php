<?php
/**
 * Block checkout integration.
 *
 * @package SecondRail\WooCommerce
 */

namespace SecondRail\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * The `secondrail` payment method type for the block checkout. The payment itself is still made by
 * `Gateway::process_payment`, which returns the redirect to the hosted page.
 */
final class Blocks_Support extends AbstractPaymentMethodType {

	const HANDLE = 'secondrail-wc-blocks';

	/**
	 * Payment method name.
	 *
	 * @var string
	 */
	protected $name = Gateway::ID;

	/**
	 * Loads the gateway settings.
	 */
	public function initialize() {
		$this->settings = Gateway::options();
	}

	/**
	 * Active when the gateway is enabled.
	 *
	 * @return bool
	 */
	public function is_active() {
		return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	/**
	 * Registers the script that calls `registerPaymentMethod`.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			self::HANDLE,
			plugins_url( 'assets/js/blocks.js', SECONDRAIL_WC_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			SECONDRAIL_WC_VERSION,
			true
		);
		return array( self::HANDLE );
	}

	/**
	 * Data for the script (`secondrail_data`).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = new Gateway();
		return array(
			'title'       => $gateway->get_title(),
			'description' => $gateway->get_description(),
			'supports'    => array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ),
		);
	}
}
