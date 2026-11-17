<?php
/**
 * R9.2–R9.5: block checkout, loading without WooCommerce, deactivation, uninstall, order access.
 *
 * @package SecondRail\WooCommerce\Tests
 */

namespace SecondRail\WooCommerce\Tests;

use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use SecondRail\WooCommerce\Blocks_Support;
use SecondRail\WooCommerce\Reconciler;

/**
 * R9.2–R9.5.
 */
class Test_Lifecycle extends Test_Case {

	const PLUGIN_DIR = WP_PLUGIN_DIR . '/secondrail-for-woocommerce';

	/**
	 * R9.2: the payment method type is registered, active only when enabled, with its script and data.
	 */
	public function test_r9_2_block_checkout() {
		$registry = Package::container()->get( PaymentMethodRegistry::class );
		$this->assertTrue( $registry->is_registered( 'secondrail' ) );
		$method = new Blocks_Support();
		$method->initialize();
		$this->assertTrue( $method->is_active() );
		$handles = $method->get_payment_method_script_handles();
		$this->assertSame( array( 'secondrail-wc-blocks' ), $handles );
		$script = wp_scripts()->registered['secondrail-wc-blocks'];
		$this->assertSame( array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ), $script->deps );
		$this->assertStringEndsWith( 'secondrail-for-woocommerce/assets/js/blocks.js', $script->src );
		$this->assertSame(
			array(
				'title'       => 'Credit or debit card',
				'description' => 'You will be taken to a secure page to complete your payment.',
				'supports'    => array( 'products', 'refunds' ),
			),
			$method->get_payment_method_data()
		);
		update_option( 'woocommerce_secondrail_settings', $this->settings( array( 'enabled' => 'no' ) ) );
		$disabled = new Blocks_Support();
		$disabled->initialize();
		$this->assertFalse( $disabled->is_active() );
	}

	/**
	 * R9.3: without WooCommerce the plugin loads no class and raises no error.
	 */
	public function test_r9_3_without_woocommerce() {
		$script = tempnam( sys_get_temp_dir(), 'secondrail' );
		$code   = '<?php
			define( "ABSPATH", "/tmp/" );
			function add_action( ...$args ) {}
			function add_filter( ...$args ) {}
			function register_deactivation_hook( ...$args ) {}
			require ' . var_export( self::PLUGIN_DIR . '/secondrail-for-woocommerce.php', true ) . ';
			secondrail_wc_init();
			secondrail_wc_deactivate();
			echo class_exists( "SecondRail\\\\WooCommerce\\\\Gateway", false ) || class_exists( "SecondRail\\\\WooCommerce\\\\Api_Client", false ) ? "loaded" : "clean";
		';
		file_put_contents( $script, $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$output = array();
		$status = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 -d error_reporting=-1 ' . escapeshellarg( $script ) . ' 2>&1', $output, $status );
		unlink( $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame( array( 'clean' ), $output );
	}

	/**
	 * R9.4: deactivation deletes the endpoints (best effort) and the scheduled checks.
	 */
	public function test_r9_4_deactivation() {
		foreach ( array( 'test', 'live' ) as $mode ) {
			update_option(
				'secondrail_wc_webhook_' . $mode,
				array(
					'id'     => 'we_' . $mode,
					'url'    => 'http://example.org/hook',
					'secret' => 'whsec_' . $mode,
				),
				false
			);
		}
		$order = $this->order();
		Reconciler::schedule( $order->get_id() );
		$this->http->ok( 'DELETE', '/webhook_endpoints/we_test', array( 'id' => 'we_test' ) );
		$this->http->raw( 'DELETE', '/webhook_endpoints/we_live', 0, new \WP_Error( 'http_request_failed', 'unreachable' ) );
		secondrail_wc_deactivate();
		$this->assertFalse( get_option( 'secondrail_wc_webhook_test' ) );
		$this->assertFalse( get_option( 'secondrail_wc_webhook_live' ) );
		$this->assertSame( 0, $this->http->pending() );
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => Reconciler::ACTION, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ) );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * R9.4: uninstall deletes the plugin's options and nothing else.
	 */
	public function test_r9_4_uninstall() {
		$order = $this->order();
		foreach ( array( 'secondrail_wc_webhook_test', 'secondrail_wc_webhook_live', 'secondrail_wc_account_test', 'secondrail_wc_account_live' ) as $option ) {
			update_option( $option, array( 'id' => 'x' ), false );
		}
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'secondrail-for-woocommerce/secondrail-for-woocommerce.php' );
		}
		include self::PLUGIN_DIR . '/uninstall.php';
		foreach ( array( 'woocommerce_secondrail_settings', 'secondrail_wc_site_id', 'secondrail_wc_webhook_test', 'secondrail_wc_webhook_live', 'secondrail_wc_account_test', 'secondrail_wc_account_live' ) as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
		$this->assertSame( 'test', wc_get_order( $order->get_id() )->get_meta( '_secondrail_mode' ) );
		$this->assertSame( array(), $this->http->requests );
	}

	/**
	 * R9.5: orders only through wc_get_order and WC_Order; no post meta functions, no SQL.
	 */
	public function test_r9_5_no_direct_order_access() {
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::PLUGIN_DIR, \FilesystemIterator::SKIP_DOTS ) );
		$count = 0;
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			++$count;
			$source = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->assertDoesNotMatchRegularExpression( '/\b(get_post_meta|update_post_meta|add_post_meta|delete_post_meta|get_post|wp_update_post)\s*\(|\$wpdb|\bSELECT\b|\bUPDATE\s+\{?\$/i', $source, $file->getFilename() );
		}
		$this->assertGreaterThanOrEqual( 7, $count );
	}
}
