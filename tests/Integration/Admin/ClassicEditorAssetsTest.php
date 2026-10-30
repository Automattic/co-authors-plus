<?php
/**
 * Tests for the classic-editor co-author box assets.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Admin;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * JQuery must still reach the head after dropping its explicit enqueue.
 *
 * The co-authors-plus.js handle declares jquery as a dependency, but is
 * registered for the footer, so relying on that alone would move jQuery out of
 * the head.
 * What keeps it there is jquery-ui-sortable, enqueued on the line below with
 * no group of its own — WP_Dependencies resolves its jquery dependency into
 * the head group, exactly as the explicit enqueue used to.
 *
 * @coversDefaultClass \CoAuthors_Plus
 */
class ClassicEditorAssetsTest extends TestCase {

	public function set_up() {
		parent::set_up();

		wp_set_current_user( $this->create_editor( 'assets_editor' )->ID );
	}

	public function tear_down() {
		// The dependency registries are process globals, so an enqueue made by
		// one test would leak into the next and defeat the not-enqueued
		// assertions. Unsetting them makes wp_scripts()/wp_styles() rebuild.
		unset( $GLOBALS['pagenow'], $GLOBALS['wp_scripts'], $GLOBALS['wp_styles'] );

		parent::tear_down();
	}

	/**
	 * Run the enqueue callback as if on the given admin page.
	 *
	 * The enqueue_scripts() callback bails unless it is on a whitelisted admin
	 * page for a post type that supports co-authors.
	 *
	 * @param string $pagenow The admin page filename, e.g. 'post.php'.
	 */
	private function enqueue_on( string $pagenow ): void {
		$GLOBALS['pagenow'] = $pagenow;
		set_current_screen( $pagenow );

		$this->_cap->enqueue_scripts( $pagenow );
	}

	/**
	 * The plugin's own script and style are enqueued.
	 */
	public function test_enqueues_the_co_authors_assets(): void {
		$this->enqueue_on( 'post.php' );

		$this->assertTrue( wp_script_is( 'co-authors-plus-js', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'co-authors-plus-css', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'jquery-ui-sortable', 'enqueued' ) );
	}

	/**
	 * JQuery is still resolved into the head group.
	 *
	 * This is the assertion that matters: WP_Dependencies::all_deps() walks the
	 * queue for group 0 and collects everything that must print in the head. If
	 * jQuery dropped to the footer, inline jQuery() calls printed in the head by
	 * other plugins would break.
	 */
	public function test_jquery_is_resolved_into_the_head_group(): void {
		$this->enqueue_on( 'post.php' );

		$scripts = wp_scripts();
		$scripts->all_deps( $scripts->queue, true, 0 );

		$this->assertContains( 'jquery', $scripts->to_do );
	}

	/**
	 * JQuery actually appears in the printed head scripts.
	 */
	public function test_jquery_is_printed_in_the_head(): void {
		$this->enqueue_on( 'post.php' );

		ob_start();
		wp_print_head_scripts();
		$head = (string) ob_get_clean();

		$this->assertStringContainsString( 'jquery', $head );
	}

	/**
	 * Nothing is enqueued on an admin page the plugin does not touch.
	 *
	 * The dashboard is not in the _pages_whitelist that is_valid_page() checks,
	 * so the callback must bail before enqueueing anything.
	 */
	public function test_enqueues_nothing_on_an_unrelated_admin_page(): void {
		$this->enqueue_on( 'index.php' );

		$this->assertFalse( wp_script_is( 'co-authors-plus-js', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'co-authors-plus-css', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'jquery-ui-sortable', 'enqueued' ) );
	}
}
