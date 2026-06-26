<?php
/**
 * Tests for which post types Co-Authors Plus is active on.
 *
 * Covers CoAuthors_Plus::is_post_type_enabled() (including the no-screen guard
 * from issue #1094) and supported_post_types() — its defaults, the
 * coauthors_supported_post_types filter, and the per-request memoisation from
 * issue #1049.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Plus
 */
class SupportedPostTypesTest extends TestCase {

	private $author1;

	private $post;

	public function set_up() {
		parent::set_up();

		$this->author1 = $this->create_author( 'author1' );
		$this->post    = $this->create_post( $this->author1 );
	}

	/**
	 * Checks coauthors plus is enabled for this post type.
	 *
	 * @covers ::is_post_type_enabled
	 */
	public function test_is_post_type_enabled(): void {

		global $coauthors_plus, $post;

		// Backing up global post.
		$post_backup = $post;

		// Checks when post type is null.
		$this->assertFalse( $coauthors_plus->is_post_type_enabled() );

		// Checks when post type is post.
		$this->assertTrue( $coauthors_plus->is_post_type_enabled( 'post' ) );

		// Checks when post type is page.
		$this->assertTrue( $coauthors_plus->is_post_type_enabled( 'page' ) );

		// Checks when post type is attachment.
		$this->assertFalse( $coauthors_plus->is_post_type_enabled( 'attachment' ) );

		// Checks when post type is revision.
		$this->assertFalse( $coauthors_plus->is_post_type_enabled( 'revision' ) );

		$post = $this->post;

		// Checks when post type set using global post.
		$this->assertTrue( $coauthors_plus->is_post_type_enabled() );

		$post   = '';
		$screen = get_current_screen();

		// Set the edit post current screen.
		set_current_screen( 'edit-post' );
		$this->assertTrue( $coauthors_plus->is_post_type_enabled() );

		$GLOBALS['current_screen'] = $screen;

		// Restore global post from backup.
		$post = $post_backup;
	}

	/**
	 * Ensures is_post_type_enabled() does not fatal when the admin screen is not
	 * yet initialised — reproduces the error reported in GitHub issue #1094, where
	 * a third-party plugin triggered save_post during plugins_loaded before the
	 * admin screen was set up.
	 *
	 * @covers ::is_post_type_enabled
	 * @ticket 1094
	 */
	public function test_is_post_type_enabled_without_screen_does_not_fatal(): void {

		global $coauthors_plus, $post;

		// Clear the global post so get_post_type() returns nothing.
		$post_backup = $post;
		$post        = '';

		// Null out the current screen to simulate a context where the screen has
		// not been initialised (e.g. save_post fired during plugins_loaded).
		$screen_backup             = $GLOBALS['current_screen'] ?? null;
		$GLOBALS['current_screen'] = null;

		// Must not throw or fatal; must return false (post type undetermined).
		$this->assertFalse( $coauthors_plus->is_post_type_enabled() );

		// Restore globals.
		$GLOBALS['current_screen'] = $screen_backup;
		$post                      = $post_backup;
	}

	/**
	 * Test the expected default supported post types.
	 *
	 * @covers ::supported_post_types
	 */
	public function test_default_supported_post_types(): void {
		$supported_post_types = ( new \CoAuthors_Plus() )->supported_post_types();
		$expected             = array(
			'post',
			'page',
		);
		$this->assertEquals( array_values( $expected ), array_values( $supported_post_types ) );
	}

	/**
	 * Test whether the supported post types can be filtered.
	 *
	 * @covers ::supported_post_types
	 */
	public function test_can_filter_supported_post_types(): void {
		// This should be detected.
		register_post_type(
			'foo',
			array(
				'supports' => array( 'author' ),
			)
		);

		// This doesn't support the author, so should not be detected.
		register_post_type(
			'bar',
			array(
				'supports' => array( 'title' ),
			)
		);

		$callback = function ( $post_types ) {
			$key = array_search( 'page', $post_types, true );
			unset( $post_types[ $key ] );

			return $post_types;
		};
		add_filter( 'coauthors_supported_post_types', $callback );

		$supported_post_types = ( new \CoAuthors_Plus() )->supported_post_types();

		$expected = array(
			'post',
			'foo',
		);
		$this->assertEquals( array_values( $expected ), array_values( $supported_post_types ) );

		// Clean up.
		remove_filter( 'coauthors_supported_post_types', $callback );
		unregister_post_type( 'foo' );
	}

	/**
	 * Regression coverage for issue #1049.
	 *
	 * Previously supported_post_types() was recomputed on every call —
	 * get_post_types() plus the coauthors_supported_post_types filter — hundreds of
	 * times per request.
	 * It now returns the value stored on $this->supported_post_types after the
	 * first call. This test pins that memoisation: once computed, a later change to
	 * the filter does not alter the result within the same request.
	 *
	 * @covers ::supported_post_types
	 * @ticket 1049
	 */
	public function test_supported_post_types_is_memoised_after_first_call(): void {
		// Clear any value cached during bootstrap so the first call below computes fresh.
		$this->_cap->supported_post_types = array();

		$first = $this->_cap->supported_post_types();
		$this->assertNotEmpty( $first, 'The first computation should return the supported post types.' );

		// A filter added after the first call must be ignored while the result is cached.
		$add_type = static function ( $types ) {
			$types[] = 'cap_post_type_added_late';
			return $types;
		};
		add_filter( 'coauthors_supported_post_types', $add_type );

		try {
			$second = $this->_cap->supported_post_types();
		} finally {
			remove_filter( 'coauthors_supported_post_types', $add_type );
			$this->_cap->supported_post_types = array();
		}

		$this->assertSame( $first, $second, 'supported_post_types() should return the memoised result on subsequent calls.' );
		$this->assertNotContains( 'cap_post_type_added_late', $second, 'A filter change after the first call must not leak into the cached result.' );
	}

	/**
	 * Regression coverage for the Authors box disappearing on custom post types.
	 *
	 * The memoisation from #1049 froze the supported-post-type list on the first
	 * call. That call can arrive before `init` — a capability check during
	 * bootstrap (kses_init() on set_current_user) reaches supported_post_types()
	 * via filter_user_has_cap() → get_to_be_filtered_caps(). Caching there froze a
	 * list of only the built-in post types, so every custom post type registered
	 * on `init` was excluded for the rest of the request and its Authors box was
	 * hidden, leaving only the native single-author selector.
	 *
	 * The list must therefore not be memoised until `wp_loaded` has fired, by
	 * which point all post types are registered.
	 *
	 * @covers ::supported_post_types
	 */
	public function test_early_call_before_wp_loaded_does_not_freeze_the_list(): void {
		global $wp_actions;

		$this->_cap->supported_post_types = array();

		// Simulate reaching supported_post_types() before wp_loaded has fired.
		$wp_loaded_count = $wp_actions['wp_loaded'] ?? 0;
		unset( $wp_actions['wp_loaded'] );

		try {
			// An early call must compute a result but must NOT memoise it.
			$early = $this->_cap->supported_post_types();
			$this->assertNotEmpty( $early, 'The early call should still return the built-in post types.' );
			$this->assertEmpty(
				$this->_cap->supported_post_types,
				'The list must not be memoised before wp_loaded.'
			);

			// A custom post type registers later (as it would on init).
			register_post_type( 'cap_late_cpt', array( 'supports' => array( 'author' ) ) );

			// Once wp_loaded has fired, the late post type must be detected — not
			// excluded by a stale list frozen during the early call.
			$wp_actions['wp_loaded'] = max( $wp_loaded_count, 1 );
			$supported               = $this->_cap->supported_post_types();

			$this->assertContains(
				'cap_late_cpt',
				$supported,
				'A custom post type registered after an early call must still be supported.'
			);
		} finally {
			$wp_actions['wp_loaded']          = $wp_loaded_count;
			$this->_cap->supported_post_types = array();
			unregister_post_type( 'cap_late_cpt' );
		}
	}
}
