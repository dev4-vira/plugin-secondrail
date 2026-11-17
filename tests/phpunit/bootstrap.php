<?php
/**
 * PHPUnit bootstrap: WordPress and WooCommerce from the volume, the database `wordpress_test`.
 *
 * @package SecondRail\WooCommerce\Tests
 */

$secondrail_repo = dirname( __DIR__, 2 );
require $secondrail_repo . '/vendor/autoload.php';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $secondrail_repo . '/vendor/yoast/phpunit-polyfills' );

$secondrail_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
require $secondrail_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require WP_PLUGIN_DIR . '/secondrail-for-woocommerce/secondrail-for-woocommerce.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		WC_Install::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();
	}
);

require $secondrail_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-http-stub.php';
require __DIR__ . '/class-test-case.php';
