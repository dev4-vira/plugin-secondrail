<?php
/**
 * WordPress test suite configuration.
 *
 * The test bootstrap empties the database it is given, so this file names `wordpress_test` and
 * nothing else. Never point it at `wordpress`.
 *
 * @package SecondRail\WooCommerce\Tests
 */

define( 'ABSPATH', '/var/www/html/' );

define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) );
define( 'DB_HOST', 'db' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Store' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );
define( 'WPLANG', '' );
