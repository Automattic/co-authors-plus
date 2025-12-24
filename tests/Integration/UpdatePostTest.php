<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use WP_User;

/**
 * Test class for the coauthors_update_post method, specifically testing
 * the fix for posts created via REST API and CLI without proper coauthor terms.
 *
 * @covers CoAuthors_Plus::coauthors_update_post()
 */
class UpdatePostTest extends TestCase {

	private $admin_user;
	private $editor_user;
	private $author_user;

	public function set_up() {
		parent::set_up();

		$this->admin_user = $this->factory()->user->create_and_get(
			array(
				'role'       => 'administrator',
				'user_login' => 'admin_user',
			)
		);

		$this->editor_user = $this->factory()->user->create_and_get(
			array(
				'role'       => 'editor',
				'user_login' => 'editor_user',
			)
		);

		$this->author_user = $this->factory()->user->create_and_get(
			array(
				'role'       => 'author',
				'user_login' => 'author_user',
			)
		);
	}

	/**
	 * Test that posts created via wp_insert_post() by admin users get proper coauthor terms.
	 *
	 * This test covers the scenario described in PR #1137 where posts created
	 * programmatically (CLI/REST) by users who can assign authors were not
	 * getting proper coauthor terms.
	 *
	 * @covers CoAuthors_Plus::coauthors_update_post()
	 */
	public function test_admin_creates_post_via_wp_insert_post_gets_coauthor_terms(): void {
		global $coauthors_plus;

		// Set current user to admin
		wp_set_current_user( $this->admin_user->ID );

		// Create post via wp_insert_post() (simulating CLI/REST creation)
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Test Post by Admin',
				'post_content' => 'Test content',
				'post_status'  => 'publish',
				'post_author'  => $this->admin_user->ID,
				'post_type'    => 'post',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Verify the post has coauthor terms
		$this->assertTrue( $coauthors_plus->has_author_terms( $post_id ), 'Post should have coauthor terms after creation' );

		// Get the coauthor terms
		$coauthor_terms = wp_get_post_terms( $post_id, $coauthors_plus->coauthor_taxonomy );
		$this->assertIsArray( $coauthor_terms );
		$this->assertCount( 1, $coauthor_terms, 'Post should have exactly one coauthor term' );

		// Verify the term corresponds to the admin user
		$expected_term_slug = 'cap-' . $this->admin_user->user_login;
		$this->assertEquals( $expected_term_slug, $coauthor_terms[0]->slug, 'Coauthor term slug should match admin user' );
	}

	/**
	 * Test that posts created via wp_insert_post() by editor users get proper coauthor terms.
	 *
	 * @covers CoAuthors_Plus::coauthors_update_post()
	 */
	public function test_editor_creates_post_via_wp_insert_post_gets_coauthor_terms(): void {
		global $coauthors_plus;

		// Set current user to editor
		wp_set_current_user( $this->editor_user->ID );

		// Create post via wp_insert_post() (simulating CLI/REST creation)
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Test Post by Editor',
				'post_content' => 'Test content',
				'post_status'  => 'publish',
				'post_author'  => $this->editor_user->ID,
				'post_type'    => 'post',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Verify the post has coauthor terms
		$this->assertTrue( $coauthors_plus->has_author_terms( $post_id ), 'Post should have coauthor terms after creation' );

		// Get the coauthor terms
		$coauthor_terms = wp_get_post_terms( $post_id, $coauthors_plus->coauthor_taxonomy );
		$this->assertIsArray( $coauthor_terms );
		$this->assertCount( 1, $coauthor_terms, 'Post should have exactly one coauthor term' );

		// Verify the term corresponds to the editor user
		$expected_term_slug = 'cap-' . $this->editor_user->user_login;
		$this->assertEquals( $expected_term_slug, $coauthor_terms[0]->slug, 'Coauthor term slug should match editor user' );
	}

	/**
	 * Test that posts created via wp_insert_post() by author users get proper coauthor terms.
	 *
	 * This test ensures that the fix doesn't break the existing behavior for author users.
	 *
	 * @covers CoAuthors_Plus::coauthors_update_post()
	 */
	public function test_author_creates_post_via_wp_insert_post_gets_coauthor_terms(): void {
		global $coauthors_plus;

		// Set current user to author
		wp_set_current_user( $this->author_user->ID );

		// Create post via wp_insert_post() (simulating CLI/REST creation)
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Test Post by Author',
				'post_content' => 'Test content',
				'post_status'  => 'publish',
				'post_author'  => $this->author_user->ID,
				'post_type'    => 'post',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Verify the post has coauthor terms
		$this->assertTrue( $coauthors_plus->has_author_terms( $post_id ), 'Post should have coauthor terms after creation' );

		// Get the coauthor terms
		$coauthor_terms = wp_get_post_terms( $post_id, $coauthors_plus->coauthor_taxonomy );
		$this->assertIsArray( $coauthor_terms );
		$this->assertCount( 1, $coauthor_terms, 'Post should have exactly one coauthor term' );

		// Verify the term corresponds to the author user
		$expected_term_slug = 'cap-' . $this->author_user->user_login;
		$this->assertEquals( $expected_term_slug, $coauthor_terms[0]->slug, 'Coauthor term slug should match author user' );
	}

	/**
	 * Test that posts created via wp_insert_post() with different post_author get proper coauthor terms.
	 *
	 * This test ensures that when a post is created with a different post_author than the current user,
	 * the coauthor terms are still created correctly.
	 *
	 * @covers CoAuthors_Plus::coauthors_update_post()
	 */
	public function test_admin_creates_post_with_different_author_gets_coauthor_terms(): void {
		global $coauthors_plus;

		// Set current user to admin
		wp_set_current_user( $this->admin_user->ID );

		// Create post via wp_insert_post() with editor as post_author
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Test Post by Admin for Editor',
				'post_content' => 'Test content',
				'post_status'  => 'publish',
				'post_author'  => $this->editor_user->ID, // Different from current user
				'post_type'    => 'post',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Verify the post has coauthor terms
		$this->assertTrue( $coauthors_plus->has_author_terms( $post_id ), 'Post should have coauthor terms after creation' );

		// Get the coauthor terms
		$coauthor_terms = wp_get_post_terms( $post_id, $coauthors_plus->coauthor_taxonomy );
		$this->assertIsArray( $coauthor_terms );
		$this->assertCount( 1, $coauthor_terms, 'Post should have exactly one coauthor term' );

		// Verify the term corresponds to the editor user (the post_author)
		$expected_term_slug = 'cap-' . $this->editor_user->user_login;
		$this->assertEquals( $expected_term_slug, $coauthor_terms[0]->slug, 'Coauthor term slug should match post_author user' );
	}

	/**
	 * Test that posts created via web form (with $_POST data) still work correctly.
	 *
	 * This test ensures that the fix doesn't break the existing web form functionality.
	 * We test this by directly calling the add_coauthors method instead of going through
	 * the web form logic to avoid nonce verification issues in tests.
	 *
	 * @covers CoAuthors_Plus::add_coauthors()
	 */
	public function test_web_form_post_creation_still_works(): void {
		global $coauthors_plus;

		// Set current user to admin
		wp_set_current_user( $this->admin_user->ID );

		// Create post first
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Test Post via Web Form',
				'post_content' => 'Test content',
				'post_status'  => 'publish',
				'post_author'  => $this->admin_user->ID,
				'post_type'    => 'post',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Test that we can still add coauthors programmatically (simulating web form logic)
		$result = $coauthors_plus->add_coauthors( $post_id, array( $this->editor_user->user_login ) );
		$this->assertTrue( $result, 'add_coauthors should return true' );

		// Verify the post has coauthor terms
		$this->assertTrue( $coauthors_plus->has_author_terms( $post_id ), 'Post should have coauthor terms after creation' );

		// Get the coauthor terms
		$coauthor_terms = wp_get_post_terms( $post_id, $coauthors_plus->coauthor_taxonomy );
		$this->assertIsArray( $coauthor_terms );
		$this->assertCount( 1, $coauthor_terms, 'Post should have exactly one coauthor term' );

		// Verify the term corresponds to the editor user
		$expected_term_slug = 'cap-' . $this->editor_user->user_login;
		$this->assertEquals( $expected_term_slug, $coauthor_terms[0]->slug, 'Coauthor term slug should match editor user' );
	}

	/**
	 * Test that posts created without $_POST data don't trigger the web form logic.
	 *
	 * This test ensures that when no $_POST data is present, the fallback logic
	 * correctly sets the post_author as a coauthor.
	 *
	 * @covers CoAuthors_Plus::coauthors_update_post()
	 */
	public function test_no_post_data_triggers_fallback_logic(): void {
		global $coauthors_plus;

		// Ensure no $_POST data is present
		unset( $_POST['coauthors-nonce'], $_POST['coauthors'] );

		// Set current user to admin
		wp_set_current_user( $this->admin_user->ID );

		// Create post via wp_insert_post() (simulating CLI/REST creation)
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Test Post without POST data',
				'post_content' => 'Test content',
				'post_status'  => 'publish',
				'post_author'  => $this->admin_user->ID,
				'post_type'    => 'post',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Verify the post has coauthor terms
		$this->assertTrue( $coauthors_plus->has_author_terms( $post_id ), 'Post should have coauthor terms after creation' );

		// Get the coauthor terms
		$coauthor_terms = wp_get_post_terms( $post_id, $coauthors_plus->coauthor_taxonomy );
		$this->assertIsArray( $coauthor_terms );
		$this->assertCount( 1, $coauthor_terms, 'Post should have exactly one coauthor term' );

		// Verify the term corresponds to the admin user (post_author)
		$expected_term_slug = 'cap-' . $this->admin_user->user_login;
		$this->assertEquals( $expected_term_slug, $coauthor_terms[0]->slug, 'Coauthor term slug should match post_author user' );
	}

	/**
	 * Test that posts created for unsupported post types don't get coauthor terms.
	 *
	 * @covers CoAuthors_Plus::coauthors_update_post()
	 */
	public function test_unsupported_post_type_does_not_get_coauthor_terms(): void {
		global $coauthors_plus;

		// Set current user to admin
		wp_set_current_user( $this->admin_user->ID );

		// Create post with unsupported post type
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Test Attachment',
				'post_content' => 'Test content',
				'post_status'  => 'publish',
				'post_author'  => $this->admin_user->ID,
				'post_type'    => 'attachment', // Unsupported post type
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Verify the post does NOT have coauthor terms
		$this->assertFalse( $coauthors_plus->has_author_terms( $post_id ), 'Attachment post type should not have coauthor terms' );
	}
}
