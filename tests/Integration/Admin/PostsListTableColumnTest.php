<?php
/**
 * Tests for the Authors column on the posts list table.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Admin;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Regression coverage for issue #1068.
 *
 * The "Authors" column on the posts list table read `global $post`, which is
 * only set while the list table renders inside The Loop. When a list table is
 * rendered from a programmatic WP_Query (for example Admin Columns exports),
 * `$post` is null and the column threw "Attempt to read property ID on null"
 * and rendered nothing. The column must instead use the `$post_id` the
 * `manage_posts_custom_column` hook passes.
 *
 * @covers \CoAuthors_Plus::_filter_manage_posts_custom_column
 */
class PostsListTableColumnTest extends TestCase {

	public function test_posts_custom_column_renders_from_post_id_without_global_post(): void {
		$author = $this->create_author( 'col_author_1068' );
		$post   = $this->create_post( $author );
		$this->_cap->add_coauthors( $post->ID, array( $author->user_login ) );

		// Simulate list-table rendering outside The Loop: no global $post.
		unset( $GLOBALS['post'] );

		ob_start();
		$this->_cap->_filter_manage_posts_custom_column( 'coauthors', $post->ID );
		$output = ob_get_clean();

		$this->assertStringContainsString(
			$author->display_name,
			$output,
			'The column must render the co-author using only the post ID, with no global $post available.'
		);
		$this->assertStringContainsString(
			'author_name=' . $author->user_nicename,
			$output,
			'The co-author link must point at the author filter.'
		);
	}

	public function test_posts_custom_column_ignores_other_columns(): void {
		$post = $this->create_post();
		unset( $GLOBALS['post'] );

		ob_start();
		$this->_cap->_filter_manage_posts_custom_column( 'some_other_column', $post->ID );
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'The method must output nothing for columns other than coauthors.' );
	}
}
