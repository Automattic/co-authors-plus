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

		// enqueue_scripts() bails unless it is on a whitelisted admin page for a
		// post type that supports co-authors.
		$GLOBALS['pagenow'] = 'post.php';
		set_current_screen( 'post.php' );

		$this->_cap->enqueue_scripts( 'post.php' );
	}

	public function tear_down() {
		unset( $GLOBALS['pagenow'] );

		parent::tear_down();
	}

	/**
	 * The plugin's own script and style are enqueued.
	 */
	public function test_enqueues_the_co_authors_assets(): void {
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
		$scripts = wp_scripts();
		$scripts->all_deps( $scripts->queue, true, 0 );

		$this->assertContains( 'jquery', $scripts->to_do );
	}

	/**
	 * JQuery actually appears in the printed head scripts.
	 */
	public function test_jquery_is_printed_in_the_head(): void {
		ob_start();
		wp_print_head_scripts();
		$head = (string) ob_get_clean();

		$this->assertStringContainsString( 'jquery', $head );
	}
}
