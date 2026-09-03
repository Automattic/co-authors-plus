<?php
/**
 * Tests for the Bulk Edit co-author hooks.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Admin;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Bulk Edit support is registered unconditionally, and works.
 *
 * `bulk_edit_custom_box` and `bulk_edit_posts` both arrived in WordPress 6.3,
 * so the hooks used to be registered behind a version_compare() against the
 * running WordPress. The plugin now declares 6.4 as its minimum, which makes
 * that check dead code — but only for as long as the declared minimum stays
 * above 6.3, which is what the first test here holds.
 *
 * @covers \CoAuthors_Plus::register_hooks
 * @covers \CoAuthors_Plus::action_bulk_edit_update_coauthors
 */
class BulkEditHooksTest extends TestCase {

	/**
	 * The WordPress version that introduced both Bulk Edit hooks.
	 */
	const BULK_EDIT_HOOKS_SINCE = '6.3';

	/**
	 * The plugin's declared minimum WordPress is new enough for the hooks.
	 *
	 * If this ever fails, the version guard was load-bearing after all and
	 * needs to come back rather than the assertion being relaxed.
	 */
	public function test_declared_minimum_wordpress_provides_the_bulk_edit_hooks(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$requires = get_plugin_data( COAUTHORS_PLUS_FILE, false, false )['RequiresWP'];

		$this->assertNotEmpty( $requires, 'The plugin header must declare "Requires at least".' );
		$this->assertTrue(
			version_compare( $requires, self::BULK_EDIT_HOOKS_SINCE, '>=' ),
			sprintf(
				'Declared minimum WordPress %s is older than %s, which introduced the Bulk Edit hooks.',
				$requires,
				self::BULK_EDIT_HOOKS_SINCE
			)
		);
	}

	/**
	 * Both hooks are attached.
	 */
	public function test_registers_both_bulk_edit_hooks(): void {
		$this->assertNotFalse(
			has_action( 'bulk_edit_custom_box', array( $this->_cap, '_action_bulk_edit_custom_box' ) )
		);
		$this->assertNotFalse(
			has_action( 'bulk_edit_posts', array( $this->_cap, 'action_bulk_edit_update_coauthors' ) )
		);
	}

	/**
	 * A bulk edit assigns the submitted co-authors to every selected post.
	 */
	public function test_bulk_edit_assigns_coauthors_to_each_selected_post(): void {
		wp_set_current_user( $this->create_editor( 'bulk_editor' )->ID );

		$coauthor = $this->create_author( 'bulk_coauthor' );
		$first    = $this->create_post();
		$second   = $this->create_post();

		$this->_cap->action_bulk_edit_update_coauthors(
			array(),
			array(
				'post'      => array( $first->ID, $second->ID ),
				'post_type' => 'post',
				'coauthors' => array( $coauthor->user_nicename ),
			)
		);

		$this->assertPostHasCoAuthors( $first->ID, array( $coauthor ) );
		$this->assertPostHasCoAuthors( $second->ID, array( $coauthor ) );
	}

	/**
	 * A bulk edit that submits no co-authors leaves the existing byline alone.
	 */
	public function test_bulk_edit_without_coauthors_leaves_the_byline_alone(): void {
		wp_set_current_user( $this->create_editor( 'bulk_editor' )->ID );

		$original = $this->create_author( 'bulk_original' );
		$post     = $this->create_post( $original );
		$this->_cap->add_coauthors( $post->ID, array( $original->user_nicename ) );

		$this->_cap->action_bulk_edit_update_coauthors(
			array(),
			array(
				'post'      => array( $post->ID ),
				'post_type' => 'post',
			)
		);

		$this->assertPostHasCoAuthors( $post->ID, array( $original ) );
	}
}
