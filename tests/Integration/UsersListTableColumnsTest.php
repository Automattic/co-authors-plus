<?php
/**
 * Tests for the custom Users list table columns.
 *
 * @package CoAuthors
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration;

/**
 * Tests for {@see \CoAuthors_Plus::_filter_manage_users_columns()} and
 * {@see \CoAuthors_Plus::_filter_manage_users_custom_column()}.
 *
 * The Users screen replaces the core "Posts" column with a CAP-aware count and
 * also surfaces the linked guest author (when one exists) so administrators
 * can see at a glance why a user's post count differs from the default.
 */
class UsersListTableColumnsTest extends TestCase {

	/**
	 * The Posts column should be removed and replaced by the linked-author
	 * column followed by the CAP post-count column, with all other columns
	 * left in their original position.
	 *
	 * @covers \CoAuthors_Plus::_filter_manage_users_columns
	 */
	public function test_columns_replaces_posts_with_linked_author_then_post_count(): void {
		$columns = array(
			'cb'       => '<input type="checkbox" />',
			'username' => 'Username',
			'name'     => 'Name',
			'email'    => 'Email',
			'role'     => 'Role',
			'posts'    => 'Posts',
		);

		$filtered = $this->_cap->_filter_manage_users_columns( $columns );

		$this->assertSame(
			array( 'cb', 'username', 'name', 'email', 'role', 'coauthors_linked_author', 'coauthors_post_count' ),
			array_keys( $filtered ),
			'The Posts column is replaced by linked author then post count, preserving column order.'
		);
		$this->assertArrayNotHasKey( 'posts', $filtered, 'The original Posts column is removed.' );
		$this->assertSame( 'Linked Guest Author', $filtered['coauthors_linked_author'] );
		$this->assertSame( 'Posts', $filtered['coauthors_post_count'] );
	}

	/**
	 * If a site has already removed the core Posts column (for example via a
	 * different plugin), the filter should be a no-op rather than appending
	 * stray columns.
	 *
	 * @covers \CoAuthors_Plus::_filter_manage_users_columns
	 */
	public function test_columns_filter_is_noop_when_posts_column_absent(): void {
		$columns = array(
			'cb'       => '',
			'username' => 'Username',
		);

		$filtered = $this->_cap->_filter_manage_users_columns( $columns );

		$this->assertSame( $columns, $filtered, 'No CAP columns are added when the Posts column is not present.' );
	}

	/**
	 * The post-count column should render a link to the user's filtered posts
	 * view, with the user_nicename safely escaped into the href.
	 *
	 * @covers \CoAuthors_Plus::_filter_manage_users_custom_column
	 */
	public function test_post_count_column_renders_link_for_user_with_posts(): void {
		$author = $this->create_author( 'columns-author' );

		$this->factory()->post->create_many(
			2,
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$value = $this->_cap->_filter_manage_users_custom_column( '', 'coauthors_post_count', $author->ID );

		$this->assertStringContainsString( 'edit.php?author_name=' . $author->user_nicename, $value );
		$this->assertStringContainsString( 'class="edit"', $value );
		$this->assertStringContainsString( '>2</a>', $value, 'The post count is rendered inside the link.' );
	}

	/**
	 * A user with no posts should render a literal `0` rather than an empty
	 * link, matching the prior behaviour.
	 *
	 * @covers \CoAuthors_Plus::_filter_manage_users_custom_column
	 */
	public function test_post_count_column_renders_zero_for_user_without_posts(): void {
		$author = $this->create_author( 'columns-empty' );

		$value = $this->_cap->_filter_manage_users_custom_column( '', 'coauthors_post_count', $author->ID );

		$this->assertSame( '0', $value, 'A user with no posts is rendered as 0 with no anchor.' );
	}

	/**
	 * The linked-author column should render a link to the guest author's
	 * edit screen when the user has a linked guest author, with the
	 * display_name safely escaped.
	 *
	 * @covers \CoAuthors_Plus::_filter_manage_users_custom_column
	 */
	public function test_linked_author_column_renders_link_when_guest_author_is_linked(): void {
		$user = $this->create_author( 'columns-linked' );

		$guest_id = $this->_cap->guest_authors->create_guest_author_from_user_id( $user->ID );
		$this->assertIsInt( $guest_id, 'The guest author was created from the user.' );

		$value = $this->_cap->_filter_manage_users_custom_column( '', 'coauthors_linked_author', $user->ID );

		$this->assertStringContainsString( 'post.php?post=' . $guest_id . '&action=edit', $value );
		$this->assertStringContainsString( 'class="edit"', $value );
		$this->assertStringContainsString( '>columns-linked</a>', $value, 'The guest author display name is rendered as the link text.' );
	}

	/**
	 * A user with no linked guest author should leave the column value
	 * untouched so other plugins can populate it.
	 *
	 * @covers \CoAuthors_Plus::_filter_manage_users_custom_column
	 */
	public function test_linked_author_column_is_empty_for_user_with_no_linked_guest_author(): void {
		$user = $this->create_author( 'columns-unlinked' );

		$value = $this->_cap->_filter_manage_users_custom_column( '', 'coauthors_linked_author', $user->ID );

		$this->assertSame( '', $value, 'No markup is appended when the user has no linked guest author.' );
	}

	/**
	 * Columns the filter does not recognise must pass through unchanged so
	 * other plugins relying on the same hook keep working.
	 *
	 * @covers \CoAuthors_Plus::_filter_manage_users_custom_column
	 */
	public function test_unknown_column_passes_value_through_unchanged(): void {
		$author = $this->create_author( 'columns-passthrough' );

		$value = $this->_cap->_filter_manage_users_custom_column( 'untouched', 'some_other_column', $author->ID );

		$this->assertSame( 'untouched', $value );
	}
}
