<?php
/**
 * Tests for CoAuthors_Plus::coauthors_set_post_author_field().
 *
 * @package CoAuthors
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Tests the wp_insert_post_data filter that derives a post's post_author from
 * the selected co-author when a post is saved.
 *
 * @covers \CoAuthors_Plus::coauthors_set_post_author_field
 */
class CoauthorsSetPostAuthorFieldTest extends TestCase {

	/**
	 * @var int
	 */
	private $author1;

	/**
	 * @var int|\WP_Error
	 */
	private $author1_post1;

	public function set_up() {
		parent::set_up();

		$this->author1 = $this->factory()->user->create(
			array(
				'role'       => 'author',
				'user_login' => 'author1',
			)
		);

		// Authoring a post through wp_insert_post fires save_post, so CoAuthors
		// Plus creates the author1 co-author term for this post.
		$this->author1_post1 = wp_insert_post(
			array(
				'post_author'  => $this->author1,
				'post_status'  => 'publish',
				'post_content' => rand_str(),
				'post_title'   => rand_str(),
				'post_type'    => 'post',
			)
		);
	}

	public function tear_down(): void {
		unset( $_REQUEST['coauthors-nonce'], $_POST['coauthors'], $_POST['coauthors-nonce'] );
		parent::tear_down();
	}

	/**
	 * Returns data as it is when post type is not allowed.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_set_post_author_field
	 */
	public function test_coauthors_set_post_author_field_when_post_type_is_attachment(): void {

		global $coauthors_plus;

		$this->assertEquals(
			10,
			has_filter(
				'wp_insert_post_data',
				array(
					$coauthors_plus,
					'coauthors_set_post_author_field',
				)
			)
		);

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->author1,
				'post_type'   => 'attachment',
			)
		);

		$post = get_post( $post_id );

		$post_array = array(
			'ID'          => $post->ID,
			'post_type'   => $post->post_type,
			'post_author' => $post->post_author,
		);
		$data       = $post_array;

		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $post_array );

		// Attachments are not an enabled post type, so the filter bails early and
		// returns the data untouched, leaving post_author exactly as supplied.
		$this->assertEquals( $data, $new_data );
		$this->assertEquals( $this->author1, $new_data['post_author'] );
	}

	/**
	 * When no co-author form data is present and the post already has author
	 * terms, post_author is re-derived from the first co-author term.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_set_post_author_field
	 */
	public function test_coauthors_set_post_author_field_when_coauthor_is_not_set(): void {

		global $coauthors_plus;

		$author1_post1 = get_post( $this->author1_post1 );

		$post_array = array(
			'ID'          => $author1_post1->ID,
			'post_type'   => $author1_post1->post_type,
			'post_author' => $author1_post1->post_author,
		);
		$data       = $post_array;

		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $post_array );

		// The post carries an author1 co-author term (created when it was
		// authored in set_up), so the filter re-asserts post_author from that
		// term, which resolves back to author1.
		$this->assertEquals( $this->author1, $new_data['post_author'] );
	}

	/**
	 * When a WP user co-author is selected via the meta box form with a valid
	 * nonce, post_author is set to that user's ID.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_set_post_author_field
	 */
	public function test_coauthors_set_post_author_field_when_coauthor_is_set(): void {

		global $coauthors_plus;

		// An editor can set authors, so the form branch is exercised.
		$editor_id = $this->factory()->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'set_field_editor',
			)
		);
		wp_set_current_user( $editor_id );

		$user_id = $this->factory()->user->create(
			array(
				'role'          => 'author',
				'user_login'    => 'test_admin',
				'user_nicename' => 'test_admin',
			)
		);

		$user = get_user_by( 'id', $user_id );

		$_REQUEST['coauthors-nonce'] = wp_create_nonce( 'coauthors-edit' );
		$_POST['coauthors']          = array(
			$user->user_nicename,
		);

		// Seed the post with a different author so a real transformation is
		// observable when the selected co-author is applied.
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $editor_id,
			)
		);

		$post = get_post( $post_id );

		$post_array = array(
			'ID'          => $post->ID,
			'post_type'   => $post->post_type,
			'post_author' => $post->post_author,
		);
		$data       = $post_array;

		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $post_array );

		// post_author should be transformed from the seeded editor to the
		// selected WP user co-author.
		$this->assertEquals( $user_id, $new_data['post_author'] );
		$this->assertNotEquals( $editor_id, $new_data['post_author'] );
	}

	/**
	 * When the selected co-author is a guest author linked to a WP user,
	 * post_author is set to that linked user's ID.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_set_post_author_field
	 */
	public function test_coauthors_set_post_author_field_when_guest_author_is_linked_with_wp_user(): void {

		global $coauthors_plus;

		// An editor can set authors, so the form branch is exercised.
		$editor_id = $this->factory()->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'linked_guest_editor',
			)
		);
		wp_set_current_user( $editor_id );

		$author1 = get_user_by( 'id', $this->author1 );

		// Seed the post with the editor as author so the transformation to the
		// linked guest author's WP user (author1) is observable.
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $editor_id,
			)
		);

		$post = get_post( $post_id );

		$post_array = array(
			'ID'          => $post->ID,
			'post_type'   => $post->post_type,
			'post_author' => $post->post_author,
		);
		$data       = $post_array;

		$_REQUEST['coauthors-nonce'] = wp_create_nonce( 'coauthors-edit' );
		$_POST['coauthors']          = array(
			$author1->user_nicename,
		);

		// Create guest author with a linked account for author1.
		$coauthors_plus->guest_authors = new \CoAuthors_Guest_Authors();
		$coauthors_plus->guest_authors->create_guest_author_from_user_id( $this->author1 );

		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $post_array );

		// post_author should map to the WP user linked to the guest author.
		$this->assertEquals( $this->author1, $new_data['post_author'] );
		$this->assertNotEquals( $editor_id, $new_data['post_author'] );
	}

	/**
	 * Falls back to the current user when post_author is missing from the data.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_set_post_author_field
	 */
	public function test_coauthors_set_post_author_field_when_post_author_is_not_set(): void {

		global $coauthors_plus;

		wp_set_current_user( $this->author1 );

		$_POST    = array();
		$_REQUEST = array();

		// Use a post with no author terms so the term re-derivation branch is
		// skipped and the current-user fallback is exercised.
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->author1,
				'post_type'   => 'attachment',
			)
		);

		$post = get_post( $post_id );

		$post_array = array(
			'ID'          => $post->ID,
			'post_type'   => 'post',
			'post_author' => $post->post_author,
		);
		$data       = $post_array;

		unset( $data['post_author'] );

		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $post_array );

		$this->assertEquals( $this->author1, $new_data['post_author'] );
	}
}
