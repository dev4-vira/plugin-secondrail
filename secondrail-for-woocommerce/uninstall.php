<?php
/**
 * Uninstall: deletes the plugin's options. Orders and their notes are kept.
 *
 * @package SecondRail\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array(
	'woocommerce_secondrail_settings',
	'secondrail_wc_site_id',
	'secondrail_wc_webhook_test',
	'secondrail_wc_webhook_live',
	'secondrail_wc_account_test',
	'secondrail_wc_account_live',
) as $secondrail_wc_option ) {
	delete_option( $secondrail_wc_option );
}
