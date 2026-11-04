<?php
/**
 * Base test case for the WordPress-free unit suite.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit;

use Brain\Monkey\Functions;
use Yoast\WPTestUtils\BrainMonkey\TestCase as BrainMonkeyTestCase;

/**
 * Base test case for unit tests.
 *
 * Extends the Yoast Brain Monkey test case, which boots Brain Monkey in its
 * snake_case `set_up()` fixture. Subclasses that need their own fixtures MUST
 * override the snake_case `set_up()` / `tear_down()` methods (calling the
 * parent), never the camelCase `setUp()` / `tearDown()`, or Brain Monkey
 * initialisation will be bypassed.
 */
abstract class TestCase extends BrainMonkeyTestCase {

	/**
	 * Let get_coauthors() run to completion without WordPress.
	 *
	 * With no co-author terms and guest authors forced, it skips both the term
	 * lookup results and the post_author fallback (so no $wpdb is needed) and goes
	 * straight to its `get_coauthors` filter, which the calling test asserts on.
	 */
	protected function stub_get_coauthors_dependencies(): void {
		Functions\when( 'cap_get_coauthor_terms_for_post' )->justReturn( array() );

		$GLOBALS['coauthors_plus'] = (object) array( 'force_guest_authors' => true );
	}

	/**
	 * Clean up the global seeded by stub_get_coauthors_dependencies().
	 */
	protected function tear_down(): void {
		unset( $GLOBALS['coauthors_plus'] );

		parent::tear_down();
	}
}
