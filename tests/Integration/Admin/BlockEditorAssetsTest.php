<?php
/**
 * Tests for block-editor detection and asset loading.
 *
 * Covers CoAuthors_Plus::is_block_editor() (including the no-screen guard from
 * issue #1094), the sidebar plugin asset enqueue, and the classic-editor
 * meta box being suppressed when the block editor is active.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Admin;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Plus
 */
class BlockEditorAssetsTest extends TestCase {

	private $editor1;

	public function set_up() {
		parent::set_up();

		$this->editor1 = $this->create_editor( 'editor1' );
	}

	/**
	 * @covers ::is_block_editor
	 */
	public function test_is_block_editor(): void {
		global $coauthors_plus;

		set_current_screen( 'post-new.php' );

		$this->assertTrue( $coauthors_plus->is_block_editor() );

		set_current_screen( 'wp-login.php' );

		$this->assertFalse( $coauthors_plus->is_block_editor() );
	}

	/**
	 * Ensures is_block_editor() returns false gracefully when the admin screen has
	 * not yet been initialised.
	 *
	 * @covers ::is_block_editor
	 * @ticket 1094
	 */
	public function test_is_block_editor_without_screen_returns_false(): void {

		global $coauthors_plus;

		$screen_backup             = $GLOBALS['current_screen'] ?? null;
		$GLOBALS['current_screen'] = null;

		// Must not throw or fatal; must return false.
		$this->assertFalse( $coauthors_plus->is_block_editor() );

		// Restore.
		$GLOBALS['current_screen'] = $screen_backup;
	}

	/**
	 * @covers ::enqueue_sidebar_plugin_assets
	 */
	public function test_enqueue_editor_assets(): void {
		$asset_file = dirname( COAUTHORS_PLUS_FILE ) . '/build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			$this->markTestSkipped( 'Build files not present. Run npm run build to test asset enqueuing.' );
		}

		// Default state.
		do_action( 'enqueue_block_editor_assets' );

		$this->assertFalse( wp_script_is( 'coauthors-sidebar-js' ) );
		$this->assertFalse( wp_style_is( 'coauthors-sidebar-css' ) );

		// Enabled post type and user who can edit, feature not enabled.
		wp_set_current_user( $this->editor1->ID );
		set_current_screen( 'edit-post' );

		do_action( 'enqueue_block_editor_assets' );

		$this->assertTrue( wp_script_is( 'coauthors-sidebar-js' ) );
		$this->assertTrue( wp_style_is( 'coauthors-sidebar-css' ) );
	}

	/**
	 * @covers ::add_coauthors_box
	 */
	public function test_add_coauthors_box(): void {
		global $coauthors_plus, $wp_meta_boxes;

		wp_set_current_user( $this->editor1->ID );
		set_current_screen( 'post-new.php' );

		$coauthors_plus->add_coauthors_box();

		$this->assertNull( $wp_meta_boxes, 'Failed to assert the coauthors metabox is not added when the block editor is loaded.' );
	}
}
