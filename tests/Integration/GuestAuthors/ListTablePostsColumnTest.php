<?php
/**
 * Tests for the Posts column in the guest authors list table.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_WP_List_Table
 */
class ListTablePostsColumnTest extends TestCase {

	public function set_up() {
		parent::set_up();

		// WP_List_Table resolves its screen from the admin page hook.
		$GLOBALS['hook_suffix'] = 'users_page_view-guest-authors';
	}

	public function tear_down() {
		unset( $GLOBALS['hook_suffix'] );

		parent::tear_down();
	}

	/**
	 * Gets the number shown in the Posts column for a guest author.
	 *
	 * @param object $guest_author Guest author.
	 * @return string The number shown.
	 */
	private function get_posts_column_count( $guest_author ): string {
		$table = new \CoAuthors_WP_List_Table();

		return wp_strip_all_tags( $table->column_posts( $guest_author ) );
	}

	/**
	 * A linked guest author with a slug of their own has their own term. The
	 * link in this column finds the linked user's posts too, so the number
	 * has to include them even when nothing is on the guest author's term.
	 *
	 * @covers ::column_posts
	 */
	public function test_linked_guest_author_with_no_posts_on_own_term_shows_linked_users_posts(): void {
		global $coauthors_plus;

		$author = $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => 'ownslug',
				'display_name' => 'Own Slug Author',
			)
		);

		$this->factory()->post->create_many(
			3,
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
			)
		);

		$guest_author = $coauthors_plus->guest_authors->get_guest_author_by(
			'ID',
			$coauthors_plus->guest_authors->create_guest_author_from_user_id( $author->ID )
		);

		// Precondition for this scenario: nothing is on the guest author's own term.
		$this->assertSame( 0, $coauthors_plus->get_author_term( $guest_author )->count );

		$this->assertSame( '3', $this->get_posts_column_count( $guest_author ) );
	}

	/**
	 * A link to a user who no longer exists shouldn't hide the guest author's
	 * own posts.
	 *
	 * @covers ::column_posts
	 */
	public function test_guest_author_linked_to_missing_user_shows_own_posts(): void {
		global $coauthors_plus;

		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Dangling Link',
				'user_login'   => 'dangling-link',
			)
		);
		update_post_meta( $guest_author_id, $coauthors_plus->guest_authors->get_post_meta_key( 'linked_account' ), 'no-such-user' );
		$coauthors_plus->guest_authors->delete_guest_author_cache( $guest_author_id );

		$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'ID', $guest_author_id, true );

		// Precondition for this scenario: the link is still there.
		$this->assertSame( 'no-such-user', $guest_author->linked_account );

		foreach ( $this->factory()->post->create_many( 2, array( 'post_status' => 'publish' ) ) as $post_id ) {
			$coauthors_plus->add_coauthors( $post_id, array( $guest_author->user_login ) );
		}

		$this->assertSame( '2', $this->get_posts_column_count( $guest_author ) );
	}
}
