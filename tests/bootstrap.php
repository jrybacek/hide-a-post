<?php
/**
 * PHPUnit bootstrap for Hide A Post integration tests.
 *
 * @package Hide_A_Post
 */

$hap_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $hap_tests_dir ) {
	$hap_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Points wp-phpunit at the test config.
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $hap_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/hide-a-post.php';
	}
);

require $hap_tests_dir . '/includes/bootstrap.php';
