<?php
/**
 * Tests for the guest author delete confirmation screen.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers \CoAuthors_Guest_Authors::view_guest_authors_list
 */
class DeleteConfirmationScreenTest extends TestCase {

	/**
	 * @var string
	 */
	private $manage_cap_backup;

	/**
	 * @var array
	 */
	private $get_backup;

	public function set_up() {
		parent::set_up();

		global $coauthors_plus;
		$this->manage_cap_backup = $coauthors_plus->guest_authors->list_guest_authors_cap;
		$this->get_backup        = $_GET;

		// For submit_button().
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}

	public function tear_down() {
		global $coauthors_plus;
		$coauthors_plus->guest_authors->list_guest_authors_cap = $this->manage_cap_backup;
		$_GET = $this->get_backup;
		remove_all_filters( 'coauthors_guest_author_manage_cap' );
		( new \CoAuthors_Guest_Authors() )->register_hooks();

		parent::tear_down();
	}

	/**
	 * Renders the confirmation screen for a guest author created by an
	 * administrator, as the given user, under a manage cap lowered to edit_posts.
	 *
	 * @param \WP_User $user Acting user.
	 *
	 * @return string Screen output.
	 */
	private function render_confirmation_as( \WP_User $user ): string {
		global $coauthors_plus;

		// As if a site filtered coauthors_guest_author_manage_cap down to edit_posts.
		add_filter(
			'coauthors_guest_author_manage_cap',
			static function () {
				return 'edit_posts';
			}
		);
		( new \CoAuthors_Guest_Authors() )->register_hooks();
		$coauthors_plus->guest_authors->list_guest_authors_cap = 'edit_posts';

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Confirm Me',
				'user_login'   => 'confirm-me',
			)
		);

		wp_set_current_user( $user->ID );
		$_GET = array(
			'action'   => 'delete',
			'id'       => (string) $guest_author_id,
			'_wpnonce' => wp_create_nonce( 'guest-author-delete' ),
		);

		ob_start();
		try {
			$coauthors_plus->guest_authors->view_guest_authors_list();
		} finally {
			$output = ob_get_clean();
		}

		return $output;
	}

	public function test_confirmation_screen_dies_without_delete_post(): void {
		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( esc_html__( "You don't have permission to delete this guest author.", 'co-authors-plus' ) );

		$this->render_confirmation_as( $this->create_author( 'confirm-author' ) );
	}

	public function test_confirmation_screen_renders_with_delete_post(): void {
		$output = $this->render_confirmation_as( $this->create_editor( 'confirm-editor' ) );

		$this->assertStringContainsString( 'value="delete-guest-author"', $output );
	}

	/**
	 * Deleting only changes posts on the guest author's own term, so the count
	 * on this screen can't include the rest of the linked user's posts. The
	 * Posts column does, as it answers a different question.
	 */
	public function test_confirmation_screen_only_counts_posts_on_linked_guest_authors_own_term(): void {
		global $coauthors_plus;

		$guest_author = $this->create_linked_guest_author_after_posts_by_user( 3 );

		foreach ( $this->factory()->post->create_many( 2, array( 'post_status' => 'publish' ) ) as $post_id ) {
			$coauthors_plus->add_coauthors( $post_id, array( $guest_author->user_login ) );
		}

		$output = $this->render_confirmation_for( (int) $guest_author->ID );

		$this->assertStringContainsString( 'There are 2 posts associated with this guest author.', $output );
		$this->assertStringContainsString( 'id="reassign-another"', $output );
	}

	/**
	 * With nothing on the guest author's own term, there's nothing to reassign.
	 */
	public function test_confirmation_screen_offers_no_reassignment_when_own_term_has_no_posts(): void {
		$guest_author = $this->create_linked_guest_author_after_posts_by_user( 3 );

		$output = $this->render_confirmation_for( (int) $guest_author->ID );

		$this->assertStringContainsString( 'There are no posts associated with this guest author.', $output );
		$this->assertStringNotContainsString( 'id="reassign-another"', $output );
	}

	/**
	 * Creates a user with published posts, then a guest author linked to them
	 * with a slug of their own, so the posts are on the user's term only.
	 *
	 * @param int $post_count Number of posts to create.
	 * @return object The guest author.
	 */
	private function create_linked_guest_author_after_posts_by_user( int $post_count ) {
		global $coauthors_plus;

		$author = $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => 'ownslug',
				'display_name' => 'Own Slug Author',
			)
		);

		$this->factory()->post->create_many(
			$post_count,
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
			)
		);

		return $coauthors_plus->guest_authors->get_guest_author_by(
			'ID',
			$coauthors_plus->guest_authors->create_guest_author_from_user_id( $author->ID )
		);
	}

	/**
	 * Renders the confirmation screen for a guest author as an administrator.
	 *
	 * @param int $guest_author_id Guest author ID.
	 * @return string Screen output.
	 */
	private function render_confirmation_for( int $guest_author_id ): string {
		global $coauthors_plus;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array(
			'action'   => 'delete',
			'id'       => (string) $guest_author_id,
			'_wpnonce' => wp_create_nonce( 'guest-author-delete' ),
		);

		ob_start();
		try {
			$coauthors_plus->guest_authors->view_guest_authors_list();
		} finally {
			$output = ob_get_clean();
		}

		return $output;
	}
}
