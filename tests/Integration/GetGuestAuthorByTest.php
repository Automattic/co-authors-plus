<?php
/**
 * Tests for reading guest author data: get_guest_author_by(), the thumbnail
 * helper and the linked-accounts lookups.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration;

/**
 * @coversDefaultClass \CoAuthors_Guest_Authors
 */
class GetGuestAuthorByTest extends TestCase {

	use \Yoast\PHPUnitPolyfills\Polyfills\AssertStringContains;

	private $author1;
	private $editor1;

	public function set_up() {
		parent::set_up();

		$this->author1 = $this->create_author( 'author1' );
		$this->editor1 = $this->create_editor( 'editor1' );
	}

	/**
	 * Checks a simulated WP_User object based on the post ID when key or value is empty.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_by()
	 */
	public function test_get_guest_author_by_with_empty_key_or_value(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Fetch guest author without forcefully.
		$this->assertFalse( $guest_author_obj->get_guest_author_by( '', '' ) );
		$this->assertFalse( $guest_author_obj->get_guest_author_by( 'ID', '' ) );
		$this->assertFalse( $guest_author_obj->get_guest_author_by( '', $this->author1->ID ) );

		// Fetch guest author forcefully.
		$this->assertFalse( $guest_author_obj->get_guest_author_by( '', '', true ) );
		$this->assertFalse( $guest_author_obj->get_guest_author_by( 'ID', '', true ) );
		$this->assertFalse( $guest_author_obj->get_guest_author_by( '', $this->author1->ID, true ) );
	}

	/**
	 * Checks a simulated WP_User object based on the post ID using cache.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_by()
	 */
	public function test_get_guest_author_by_using_cache(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$guest_author_id = $guest_author_obj->create_guest_author_from_user_id( $this->editor1->ID );

		$cache_key = $guest_author_obj->get_cache_key( 'ID', $guest_author_id );

		// Checks when guest author does not exist in cache.
		$this->assertFalse( wp_cache_get( $cache_key, $guest_author_obj::$cache_group ) );

		// Checks when guest author exists in cache.
		$guest_author        = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );
		$guest_author_cached = wp_cache_get( $cache_key, $guest_author_obj::$cache_group );

		$this->assertInstanceOf( \stdClass::class, $guest_author );
		$this->assertEquals( $guest_author, $guest_author_cached );
	}

	/**
	 * Checks a simulated WP_User object based on the post ID using different key/value.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_by()
	 */
	public function test_get_guest_author_by_with_different_keys(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Checks when user is not a guest author.
		$this->assertFalse( $guest_author_obj->get_guest_author_by( 'ID', $this->author1->ID ) );
		$this->assertFalse( $guest_author_obj->get_guest_author_by( 'ID', $this->author1->ID, true ) );

		$guest_author_id = $guest_author_obj->create_guest_author_from_user_id( $this->editor1->ID );

		// Checks guest author using ID.
		$guest_author = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );

		$this->assertInstanceOf( \stdClass::class, $guest_author );
		$this->assertEquals( $guest_author_id, $guest_author->ID );
		$this->assertEquals( $guest_author_obj->post_type, $guest_author->type );

		// Checks guest author using user_nicename.
		$guest_author = $guest_author_obj->get_guest_author_by( 'user_nicename', $this->editor1->user_nicename );

		$this->assertInstanceOf( \stdClass::class, $guest_author );
		$this->assertEquals( $guest_author_obj->post_type, $guest_author->type );

		// Checks guest author using linked_account.
		$guest_author = $guest_author_obj->get_guest_author_by( 'linked_account', $this->editor1->user_login );

		$this->assertInstanceOf( \stdClass::class, $guest_author );
		$this->assertEquals( $guest_author_obj->post_type, $guest_author->type );
	}

	/**
	 * Checks thumbnail for a guest author object.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_thumbnail()
	 */
	public function test_get_guest_author_thumbnail(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Checks when guest author does not have any thumbnail.
		$guest_author_id = $guest_author_obj->create(
			array(
				'user_login'   => 'author2',
				'display_name' => 'author2',
			)
		);
		$guest_author    = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );

		$this->assertNull( $guest_author_obj->get_guest_author_thumbnail( $guest_author, 0 ) );

		$attachment_id = $this->factory()->attachment->create_upload_object( __DIR__ . '/fixtures/dummy-attachment.png' );

		set_post_thumbnail( $guest_author->ID, $attachment_id );

		$thumbnail = $guest_author_obj->get_guest_author_thumbnail( $guest_author, 0 );

		$this->assertStringContainsString( 'avatar-0', $thumbnail );
		// Checking for dummy-attachment instead of dummy-attachment.png, as filename might change to
		// dummy-attachment-1.png, dummy-attachment-2.png, etc. when running multiple tests.
		$this->assertStringContainsString( 'dummy-attachment', $thumbnail );
		$this->assertStringContainsString( wp_get_attachment_url( $attachment_id ), $thumbnail );
	}

	/**
	 * Checks all the user accounts that have been linked.
	 *
	 * @covers CoAuthors_Guest_Authors::get_all_linked_accounts()
	 */
	public function test_get_all_linked_accounts(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$this->assertEmpty( $guest_author_obj->get_all_linked_accounts() );

		// Checks when guest author ( not linked account ) exists.
		$guest_author_obj->create(
			array(
				'user_login'   => 'author2',
				'display_name' => 'author2',
			)
		);

		$this->assertEmpty( $guest_author_obj->get_all_linked_accounts() );

		// Create guest author from existing user and check.
		$guest_author_obj->create_guest_author_from_user_id( $this->editor1->ID );

		$linked_accounts    = $guest_author_obj->get_all_linked_accounts();
		$linked_account_ids = wp_list_pluck( $linked_accounts, 'ID' );

		$this->assertNotEmpty( $linked_accounts );
		$this->assertIsArray( $linked_accounts );
		$this->assertContains( $this->editor1->ID, $linked_account_ids );
	}

	/**
	 * Checks all the user accounts that have been linked using cache.
	 *
	 * @covers CoAuthors_Guest_Authors::get_all_linked_accounts()
	 */
	public function test_get_all_linked_accounts_with_cache(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$cache_key = 'all-linked-accounts';

		// Checks when guest author does not exist in cache.
		$this->assertFalse( wp_cache_get( $cache_key, $guest_author_obj::$cache_group ) );

		// Checks when guest author exists in cache.
		$guest_author_obj->create_guest_author_from_user_id( $this->editor1->ID );

		$linked_accounts       = $guest_author_obj->get_all_linked_accounts();
		$linked_accounts_cache = wp_cache_get( $cache_key, $guest_author_obj::$cache_group );

		$this->assertEquals( $linked_accounts, $linked_accounts_cache );
	}
}
