<?php
/**
 * Tests for setting co-authors from the Bulk Edit screen.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Admin;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Runs core's bulk_edit_posts(), so the plugin sees exactly what core passes
 * to the bulk_edit_posts action.
 *
 * @coversDefaultClass \CoAuthors_Plus
 */
class BulkEditTest extends TestCase {

	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/post.php';
	}

	/**
	 * @param int[]    $post_ids  Posts selected in the list table.
	 * @param string[] $coauthors Co-author slugs entered in the Bulk Edit form.
	 */
	private function bulk_edit( array $post_ids, array $coauthors ): array {
		return bulk_edit_posts(
			array(
				'post_type' => 'post',
				'_status'   => '-1',
				'post'      => $post_ids,
				'coauthors' => $coauthors,
			)
		);
	}

	/**
	 * @covers ::action_bulk_edit_update_coauthors
	 */
	public function test_bulk_edit_skips_posts_the_user_cannot_edit(): void {
		add_filter(
			'coauthors_plus_edit_authors',
			static function ( $can_set_authors ) {
				return $can_set_authors || current_user_can( 'publish_posts' );
			}
		);
		$owner      = $this->create_author( 'owner' );
		$user       = $this->create_author( 'other_author' );
		$their_post = $this->create_post( $owner );
		$own_post   = $this->create_post( $user );
		wp_set_current_user( $user->ID );

		$result = $this->bulk_edit( array( $own_post->ID, $their_post->ID ), array( 'other_author' ) );

		$this->assertSame( array( $their_post->ID ), $result['skipped'] );
		$this->assertPostHasCoAuthors( $own_post->ID, array( $user ) );
		$this->assertPostHasCoAuthors( $their_post->ID, array( $owner ) );
	}

	/**
	 * @covers ::action_bulk_edit_update_coauthors
	 */
	public function test_bulk_edit_skips_posts_locked_by_another_user(): void {
		$author      = $this->create_author();
		$editor      = $this->create_editor();
		$other       = $this->create_editor( 'other_editor' );
		$open_post   = $this->create_post( $author );
		$locked_post = $this->create_post( $author );

		wp_set_current_user( $other->ID );
		wp_set_post_lock( $locked_post->ID );
		wp_set_current_user( $editor->ID );

		$result = $this->bulk_edit( array( $open_post->ID, $locked_post->ID ), array( 'editor' ) );

		$this->assertSame( array( $locked_post->ID ), $result['locked'] );
		$this->assertPostHasCoAuthors( $open_post->ID, array( $editor ) );
		$this->assertPostHasCoAuthors( $locked_post->ID, array( $author ) );
	}
}
