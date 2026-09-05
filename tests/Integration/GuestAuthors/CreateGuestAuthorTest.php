<?php
/**
 * Tests for creating guest authors: create(), create_guest_author_from_user_id()
 * and the term-creation failure / cleanup paths.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Guest_Authors
 */
class CreateGuestAuthorTest extends TestCase {

	private $editor1;

	public function set_up() {
		parent::set_up();

		$this->editor1 = $this->create_editor( 'editor1' );
	}

	/**
	 * Checks guest author from an existing WordPress user.
	 *
	 * @covers CoAuthors_Guest_Authors::create_guest_author_from_user_id()
	 */
	public function test_create_guest_author_from_user_id(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Checks create guest author when user don't exist.
		$response = $guest_author_obj->create_guest_author_from_user_id( 0 );

		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'invalid-user', $response->get_error_code() );

		// Checks create guest author when user exist.
		$guest_author_id = $guest_author_obj->create_guest_author_from_user_id( $this->editor1->ID );
		$guest_author    = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );

		$this->assertInstanceOf( \stdClass::class, $guest_author );
	}

	/**
	 * Checks that update_author_term returns WP_Error when wp_insert_term fails.
	 *
	 * @covers CoAuthors_Plus::update_author_term()
	 *
	 * @link https://github.com/Automattic/Co-Authors-Plus/issues/1135
	 */
	public function test_update_author_term_returns_error_on_insert_failure(): void {

		global $coauthors_plus;

		// Force wp_insert_term to fail by using a filter.
		add_filter(
			'pre_insert_term',
			function () {
				return new \WP_Error( 'term_exists', 'A term with this slug already exists.' );
			}
		);

		// Create a mock coauthor object with all required properties.
		$coauthor                = new \stdClass();
		$coauthor->ID            = 0;
		$coauthor->user_nicename = 'test-author-' . wp_rand();
		$coauthor->user_login    = 'test-author-' . wp_rand();
		$coauthor->display_name  = 'Test Author';
		$coauthor->first_name    = 'Test';
		$coauthor->last_name     = 'Author';
		$coauthor->user_email    = 'test@example.com';

		$result = $coauthors_plus->update_author_term( $coauthor );

		$this->assertInstanceOf( 'WP_Error', $result );

		// Clean up filter.
		remove_all_filters( 'pre_insert_term' );
	}

	/**
	 * Checks that creating a guest author returns WP_Error when term creation fails
	 * and cleans up the orphaned post.
	 *
	 * @covers CoAuthors_Guest_Authors::create()
	 *
	 * @link https://github.com/Automattic/Co-Authors-Plus/issues/1135
	 */
	public function test_create_guest_author_returns_error_and_cleans_up_on_term_failure(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Count posts before attempting to create the guest author.
		$posts_before = get_posts(
			array(
				'post_type'   => $guest_author_obj->post_type,
				'post_status' => 'any',
				// phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_numberposts -- Test-only: exhaustively count guest author posts in the isolated test database.
				'numberposts' => -1,
			)
		);
		$count_before = count( $posts_before );

		// Force wp_insert_term to fail by using a filter.
		add_filter(
			'pre_insert_term',
			function () {
				return new \WP_Error( 'term_exists', 'A term with this slug already exists.' );
			}
		);

		// Try to create a guest author - term creation will fail.
		$result = $guest_author_obj->create(
			array(
				'user_login'   => 'test-cleanup-author-' . wp_rand(),
				'display_name' => 'Test Cleanup Author',
			)
		);

		// Clean up filter.
		remove_all_filters( 'pre_insert_term' );

		// Should return a WP_Error.
		$this->assertInstanceOf( 'WP_Error', $result );

		// Verify no orphaned post was left behind.
		$posts_after = get_posts(
			array(
				'post_type'   => $guest_author_obj->post_type,
				'post_status' => 'any',
				// phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_numberposts -- Test-only: exhaustively count guest author posts in the isolated test database.
				'numberposts' => -1,
			)
		);
		$count_after = count( $posts_after );

		$this->assertEquals( $count_before, $count_after, 'Orphaned guest author post should be cleaned up on term creation failure.' );
	}

	/**
	 * Checks that creating a guest author via the create() method works end-to-end.
	 *
	 * Note: create() copies the display name into post_title, so the post it
	 * inserts is never empty and core's "empty content" check cannot fire on
	 * this path. The guard against re-adding title/editor/excerpt post type
	 * support lives in test_empty_guest_author_post_is_not_rejected_as_empty().
	 *
	 * @covers CoAuthors_Guest_Authors::create()
	 */
	public function test_create_guest_author_succeeds_with_display_name(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$guest_author_id = $guest_author_obj->create(
			array(
				'user_login'   => 'test-empty-content-author',
				'display_name' => 'Test Empty Content Author',
			)
		);

		$this->assertIsInt( $guest_author_id, 'create() should return a post ID.' );
		$this->assertGreaterThan( 0, $guest_author_id );

		$guest_author = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );
		$this->assertInstanceOf( \stdClass::class, $guest_author );
		$this->assertEquals( 'Test Empty Content Author', $guest_author->display_name );
	}

	/**
	 * A completely empty guest author post must not be rejected by core.
	 *
	 * Core's wp_insert_post() only refuses a post whose title, content and excerpt
	 * are all empty when the post type supports all three of 'title',
	 * 'editor' and 'excerpt' (the $maybe_empty check). The guest-author post
	 * type deliberately supports only 'thumbnail', so a meta-only post with
	 * nothing in those fields always saves — which is why the old
	 * filter_wp_insert_post_empty_content() escape hatch could be deleted.
	 *
	 * This is the tripwire for that reasoning: if title, editor and excerpt
	 * support are ever all re-added to the guest-author post type,
	 * wp_insert_post() starts returning WP_Error( 'empty_content' ) here and
	 * this test fails, flagging that empty guest author posts would again be
	 * rejected without a replacement for the deleted filter.
	 *
	 * @covers CoAuthors_Guest_Authors::register_hooks()
	 */
	public function test_empty_guest_author_post_is_not_rejected_as_empty(): void {

		global $coauthors_plus;

		$post_id = wp_insert_post(
			array(
				'post_type'    => $coauthors_plus->guest_authors->post_type,
				'post_title'   => '',
				'post_content' => '',
				'post_excerpt' => '',
			),
			true
		);

		$this->assertIsInt( $post_id, 'An empty guest-author post should insert, not be rejected as empty content.' );
		$this->assertGreaterThan( 0, $post_id );
	}
}
