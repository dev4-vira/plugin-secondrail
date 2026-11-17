<?php
/**
 * Plugin bootstrap, headers and the test harness itself.
 *
 * @package SecondRail\WooCommerce\Tests
 */

namespace SecondRail\WooCommerce\Tests;

/**
 * R1.3, R9.1, R10.1.
 */
class Test_Plugin extends Test_Case {

	/**
	 * R1.3: the stub answers queued requests and flags any other.
	 */
	public function test_r1_3_stub_answers_queued_and_flags_others() {
		$this->http->ok( 'GET', '/account', array( 'name' => 'Shop' ) );
		$answer = wp_remote_get( Http_Stub::API_BASE . '/account' );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $answer ) );
		$other = wp_remote_get( 'https://example.com/' );
		$this->assertWPError( $other );
		$this->assertSame( array( 'GET https://example.com/' ), $this->http->unexpected );
		$this->assertCount( 2, $this->http->requests );
		$this->http->unexpected = array();
	}

	/**
	 * R1.3: the suite runs against `wordpress_test` and nothing else.
	 */
	public function test_r1_3_runs_on_the_test_database() {
		$this->assertSame( 'wordpress_test', DB_NAME );
		$config = file_get_contents( dirname( __DIR__ ) . '/phpunit/wp-tests-config.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( 1, preg_match_all( "/define\( 'DB_NAME', '([^']+)' \)/", $config, $names ) );
		$this->assertSame( array( 'wordpress_test' ), $names[1] );
	}

	/**
	 * R9.1: compatibility with HPOS and the block checkout is declared.
	 */
	public function test_r9_1_declares_compatibility() {
		$util = \Automattic\WooCommerce\Utilities\FeaturesUtil::class;
		$file = 'secondrail-for-woocommerce/secondrail-for-woocommerce.php';
		foreach ( array( 'custom_order_tables', 'cart_checkout_blocks' ) as $feature ) {
			$compatible = $util::get_compatible_plugins_for_feature( $feature, true );
			$this->assertContains( $file, $compatible['compatible'], $feature );
		}
	}

	/**
	 * R10.1: header values and the version in the header, readme and constant agree.
	 */
	public function test_r10_1_headers_and_version() {
		$file    = WP_PLUGIN_DIR . '/secondrail-for-woocommerce/secondrail-for-woocommerce.php';
		$headers = get_file_data(
			$file,
			array(
				'version'      => 'Version',
				'requires'     => 'Requires at least',
				'requires_php' => 'Requires PHP',
				'wc_requires'  => 'WC requires at least',
				'wc_tested'    => 'WC tested up to',
				'license'      => 'License',
				'text_domain'  => 'Text Domain',
				'plugins'      => 'Requires Plugins',
			)
		);
		$this->assertSame(
			array(
				'version'      => '0.1.0',
				'requires'     => '7.0',
				'requires_php' => '7.4',
				'wc_requires'  => '11.0',
				'wc_tested'    => '11.3',
				'license'      => 'GPL-2.0-or-later',
				'text_domain'  => 'secondrail-for-woocommerce',
				'plugins'      => 'woocommerce',
			),
			$headers
		);
		$readme = get_file_data( dirname( $file ) . '/readme.txt', array( 'stable' => 'Stable tag' ) );
		$this->assertSame( '0.1.0', $readme['stable'] );
		$this->assertSame( '0.1.0', SECONDRAIL_WC_VERSION );
	}
}
