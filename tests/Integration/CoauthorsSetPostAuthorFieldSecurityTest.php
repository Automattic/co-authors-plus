<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

/**
 * Regression tests for the nonce and capability checks on
 * CoAuthors_Plus::coauthors_set_post_author_field().
 *
 * The filter previously accepted any request that merely carried a
 * coauthors-nonce key, with no nonce verification or capability check,
 * allowing an Author-level user to rewrite wp_posts.post_author when
 * saving their own post. These tests pin the corrected behaviour:
 * the filter must only override post_author when the current user can
 * set authors and a valid coauthors-edit nonce is present.
 *
 * @covers \CoAuthors_Plus::coauthors_set_post_author_field
 */
class CoauthorsSetPostAuthorFieldSecurityTest extends TestCase {

	/** @var \WP_User */
	private $admin;

	/** @var \WP_User */
	private $editor;

	/** @var \WP_User */
	private $author;

	public function set_up() {
		parent::set_up();

		$this->admin  = $this->factory()->user->create_and_get(
			array(
				'role'       => 'administrator',
				'user_login' => 'security_admin',
			)
		);
		$this->editor = $this->factory()->user->create_and_get(
			array(
				'role'       => 'editor',
				'user_login' => 'security_editor',
			)
		);
		$this->author = $this->factory()->user->create_and_get(
			array(
				'role'       => 'author',
				'user_login' => 'security_author',
			)
		);
	}

	public function tear_down() {
		unset( $_REQUEST['coauthors-nonce'], $_POST['coauthors-nonce'], $_POST['coauthors'] );
		parent::tear_down();
	}

	/**
	 * Build the $data / $post_array pair the filter is called with.
	 *
	 * @param int $post_author Author ID to seed into the simulated post data.
	 */
	private function make_post_data( int $post_author ): array {
		return array(
			'ID'          => 0,
			'post_type'   => 'post',
			'post_author' => $post_author,
		);
	}

	/**
	 * An Author-level user cannot rewrite post_author by crafting a payload,
	 * even with a valid nonce generated in their own session.
	 */
	public function test_author_cannot_rewrite_post_author_via_crafted_payload(): void {
		global $coauthors_plus;

		wp_set_current_user( $this->author->ID );

		$_REQUEST['coauthors-nonce'] = wp_create_nonce( 'coauthors-edit' );
		$_POST['coauthors']          = array( $this->admin->user_nicename );

		$data     = $this->make_post_data( $this->author->ID );
		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $data );

		$this->assertSame(
			$this->author->ID,
			$new_data['post_author'],
			'Author must not be able to reassign post_author to another user.'
		);
	}

	/**
	 * A request with no coauthors-nonce key is a no-op.
	 */
	public function test_missing_nonce_does_not_rewrite_post_author(): void {
		global $coauthors_plus;

		wp_set_current_user( $this->editor->ID );

		unset( $_REQUEST['coauthors-nonce'], $_POST['coauthors-nonce'] );
		$_POST['coauthors'] = array( $this->admin->user_nicename );

		$data     = $this->make_post_data( $this->editor->ID );
		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $data );

		$this->assertSame(
			$this->editor->ID,
			$new_data['post_author'],
			'Missing nonce must skip the post_author override.'
		);
	}

	/**
	 * A request with a garbage coauthors-nonce value is a no-op.
	 */
	public function test_invalid_nonce_does_not_rewrite_post_author(): void {
		global $coauthors_plus;

		wp_set_current_user( $this->editor->ID );

		$_REQUEST['coauthors-nonce'] = 'not-a-real-nonce';
		$_POST['coauthors']          = array( $this->admin->user_nicename );

		$data     = $this->make_post_data( $this->editor->ID );
		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $data );

		$this->assertSame(
			$this->editor->ID,
			$new_data['post_author'],
			'Invalid nonce must skip the post_author override.'
		);
	}

	/**
	 * An Editor with a valid nonce can reassign post_author to another WP user.
	 * Pins the positive path so future refactors don't silently break the
	 * feature for users who are authorised to use it.
	 */
	public function test_editor_with_valid_nonce_reassigns_post_author_to_wp_user(): void {
		global $coauthors_plus;

		wp_set_current_user( $this->editor->ID );

		$_REQUEST['coauthors-nonce'] = wp_create_nonce( 'coauthors-edit' );
		$_POST['coauthors']          = array( $this->admin->user_nicename );

		$data     = $this->make_post_data( $this->editor->ID );
		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $data );

		$this->assertSame(
			$this->admin->ID,
			$new_data['post_author'],
			'Editor with a valid nonce should reassign post_author to the selected WP user.'
		);
	}

	/**
	 * An Editor with a valid nonce selecting a guest author linked to a WP
	 * user results in post_author being set to the linked user's ID.
	 */
	public function test_editor_with_valid_nonce_maps_linked_guest_author_to_wp_user(): void {
		global $coauthors_plus;

		// Create a guest author linked to the admin's user account.
		$coauthors_plus->guest_authors->create_guest_author_from_user_id( $this->admin->ID );

		$guest_author = $coauthors_plus->guest_authors->get_guest_author_by(
			'linked_account',
			$this->admin->user_login
		);

		$this->assertIsObject( $guest_author, 'Fixture: linked guest author must be creatable.' );

		wp_set_current_user( $this->editor->ID );

		$_REQUEST['coauthors-nonce'] = wp_create_nonce( 'coauthors-edit' );
		$_POST['coauthors']          = array( $guest_author->user_nicename );

		$data     = $this->make_post_data( $this->editor->ID );
		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $data );

		$this->assertSame(
			$this->admin->ID,
			$new_data['post_author'],
			'Selecting a linked guest author should map post_author to the linked WP user ID.'
		);
	}
}
