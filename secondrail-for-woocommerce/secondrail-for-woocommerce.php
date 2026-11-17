<?php
/**
 * Plugin Name:          SecondRail for WooCommerce
 * Description:          Take card payments through SecondRail's hosted payment page. No card field is rendered on the store.
 * Version:              0.1.0
 * Requires at least:    7.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               SecondRail
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          secondrail-for-woocommerce
 * WC requires at least: 11.0
 * WC tested up to:      11.3
 *
 * @package SecondRail\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'SECONDRAIL_WC_VERSION', '0.1.0' );
define( 'SECONDRAIL_WC_FILE', __FILE__ );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Loads the plugin's classes. Returns false when WooCommerce is not active.
 *
 * @return bool
 */
function secondrail_wc_load() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return false;
	}
	require_once __DIR__ . '/includes/class-api-client.php';
	require_once __DIR__ . '/includes/class-gateway.php';
	require_once __DIR__ . '/includes/class-reconciler.php';
	require_once __DIR__ . '/includes/class-webhook.php';
	return true;
}

/**
 * Registers the gateway and its handlers once every plugin is loaded.
 */
function secondrail_wc_init() {
	if ( ! secondrail_wc_load() ) {
		return;
	}
	add_filter(
		'woocommerce_payment_gateways',
		static function ( $gateways ) {
			$gateways[] = \SecondRail\WooCommerce\Gateway::class;
			return $gateways;
		}
	);
	\SecondRail\WooCommerce\Gateway::register_hooks();
	\SecondRail\WooCommerce\Reconciler::register_hooks();
	\SecondRail\WooCommerce\Webhook::register_hooks();
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		static function ( $registry ) {
			require_once __DIR__ . '/includes/class-blocks-support.php';
			$registry->register( new \SecondRail\WooCommerce\Blocks_Support() );
		}
	);
}
add_action( 'plugins_loaded', 'secondrail_wc_init' );

/**
 * Deactivation: removes the webhook endpoints and the scheduled checks. Orders are not touched.
 */
function secondrail_wc_deactivate() {
	if ( secondrail_wc_load() ) {
		\SecondRail\WooCommerce\Webhook::remove_endpoints();
		\SecondRail\WooCommerce\Reconciler::unschedule_all();
	}
}
register_deactivation_hook( __FILE__, 'secondrail_wc_deactivate' );
