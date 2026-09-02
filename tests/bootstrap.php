<?php
/**
 * PHPUnit bootstrap file.
 *
 * A single bootstrap serves both test suites. WordPress is booted only for the
 * integration suite (`--testsuite integration`); the unit suite runs without
 * WordPress, using Brain Monkey to mock WordPress functions.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

use Yoast\WPTestUtils\WPIntegration;

// Always register the Composer autoloader (plugin classmap + test PSR-4) for both suites.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/*
 * Work out whether the integration suite is being run. Support both the
 * `--testsuite integration` (two args) and `--testsuite=integration` (one arg) forms.
 */
$argv_local     = $GLOBALS['argv'] ?? array();
$is_integration = in_array( '--testsuite=integration', $argv_local, true );

$testsuite_key = array_search( '--testsuite', $argv_local, true );
if ( false !== $testsuite_key && isset( $argv_local[ $testsuite_key + 1 ] ) && 'integration' === $argv_local[ $testsuite_key + 1 ] ) {
	$is_integration = true;
}

if ( ! $is_integration ) {
	/*
	 * Unit suite: no WordPress. Define ABSPATH so plugin files guarded by
	 * `if ( ! defined( 'ABSPATH' ) ) { exit; }` can be loaded under test.
	 */
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	// Load cache infrastructure before unit tests instantiate legacy classes.
	require_once dirname( __DIR__ ) . '/php/cache/class-keys.php';

	return;
}

// ---------------------------------------------------------------------------
// Integration suite: boot the WordPress test environment.
// ---------------------------------------------------------------------------

require_once dirname( __DIR__ ) . '/vendor/yoast/wp-test-utils/src/WPIntegration/bootstrap-functions.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to the PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/co-authors-plus.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Make sure the Composer autoload file has been generated.
WPIntegration\check_composer_autoload_exists();

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

/*
 * Register the custom autoloader to overload the PHPUnit MockObject classes when running on PHP 8.
 *
 * This function has to be called _last_, after the WP test bootstrap, to make sure it registers
 * itself in FRONT of the Composer autoload (which also prepends itself to the autoload queue).
 */
WPIntegration\register_mockobject_autoloader();
