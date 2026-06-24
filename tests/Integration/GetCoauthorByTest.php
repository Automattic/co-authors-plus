<?php
/**
 * Tests for resolving a co-author object from various identifiers.
 *
 * Covers CoAuthors_Plus::get_coauthor_by() across guest authors, WP_User
 * accounts, linked accounts and the guest-authors-disabled fallback, plus the
 * is_guest_authors_enabled() toggle that gates that behaviour.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use WP_User;

/**
 * @coversDefaultClass \CoAuthors_Plus
 */
class GetCoauthorByTest extends TestCase {

	private $author1;

	private $editor1;

	public function set_up() {
		parent::set_up();

		$this->author1 = $this->create_author( 'author1' );
		$this->editor1 = $this->create_editor( 'editor1' );
	}

	/**
	 * Checks whether the guest authors functionality is enabled or not.
	 *
	 * @covers ::is_guest_authors_enabled
	 */
	public function test_is_guest_authors_enabled(): void {

		global $coauthors_plus;

		$this->assertTrue( $coauthors_plus->is_guest_authors_enabled() );

		add_filter( 'coauthors_guest_authors_enabled', '__return_false' );

		$this->assertFalse( $coauthors_plus->is_guest_authors_enabled() );

		remove_filter( 'coauthors_guest_authors_enabled', '__return_false' );

		$this->assertTrue( $coauthors_plus->is_guest_authors_enabled() );
	}

	/**
	 * Checks coauthor object when he/she is a guest author.
	 *
	 * @covers ::get_coauthor_by
	 */
	public function test_get_coauthor_by_when_guest_author(): void {

		global $coauthors_plus;

		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'user_login'   => 'author2',
				'display_name' => 'author2',
			)
		);

		$coauthor = $coauthors_plus->get_coauthor_by( 'id', $guest_author_id );

		$this->assertInstanceOf( \stdClass::class, $coauthor );
		$this->assertObjectHasProperty( 'ID', $coauthor );
		$this->assertEquals( $guest_author_id, $coauthor->ID );
		$this->assertEquals( 'guest-author', $coauthor->type );
	}

	/**
	 * Checks coauthor object when he/she is a guest author with unicode user_login
	 *
	 * @covers ::get_coauthor_by
	 */
	public function test_get_coauthor_by_when_guest_author_has_unicode_username(): void {

		global $coauthors_plus;

		$user_login      = 'محمود-الحسيني';
		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'user_login'   => $user_login,
				'display_name' => 'محمود الحسيني',
			)
		);

		$coauthor = $coauthors_plus->get_coauthor_by( 'user_login', $user_login );

		$this->assertInstanceOf( \stdClass::class, $coauthor );
		$this->assertObjectHasProperty( 'ID', $coauthor );
		$this->assertEquals( $guest_author_id, $coauthor->ID );
		$this->assertEquals( 'guest-author', $coauthor->type );
	}

	/**
	 * Checks coauthor object when he/she is a wp author.
	 *
	 * @covers ::get_coauthor_by
	 */
	public function test_get_coauthor_by_when_guest_authors_not_enabled(): void {

		global $coauthors_plus;

		add_filter( 'coauthors_guest_authors_enabled', '__return_false' );

		$this->assertFalse( $coauthors_plus->get_coauthor_by( '', '' ) );

		$coauthor = $coauthors_plus->get_coauthor_by( 'id', $this->author1->ID );

		$this->assertInstanceOf( WP_User::class, $coauthor );
		$this->assertObjectHasProperty( 'ID', $coauthor );
		$this->assertEquals( $this->author1->ID, $coauthor->ID );
		$this->assertEquals( 'wpuser', $coauthor->type );

		$coauthor = $coauthors_plus->get_coauthor_by( 'user_login', $this->author1->user_login );

		$this->assertInstanceOf( WP_User::class, $coauthor );
		$this->assertObjectHasProperty( 'user_login', $coauthor->data );
		$this->assertEquals( $this->author1->user_login, $coauthor->user_login );

		$coauthor = $coauthors_plus->get_coauthor_by( 'user_nicename', $this->author1->user_nicename );

		$this->assertInstanceOf( WP_User::class, $coauthor );
		$this->assertObjectHasProperty( 'user_nicename', $coauthor->data );
		$this->assertEquals( $this->author1->user_nicename, $coauthor->user_nicename );

		$coauthor = $coauthors_plus->get_coauthor_by( 'user_email', $this->author1->user_email );

		$this->assertInstanceOf( WP_User::class, $coauthor );
		$this->assertObjectHasProperty( 'user_email', $coauthor->data );
		$this->assertEquals( $this->author1->user_email, $coauthor->user_email );

		remove_filter( 'coauthors_guest_authors_enabled', '__return_false' );

		$coauthors_plus->guest_authors->create_guest_author_from_user_id( $this->editor1->ID );

		$coauthor = $coauthors_plus->get_coauthor_by( 'id', $this->editor1->ID );

		$this->assertInstanceOf( \stdClass::class, $coauthor );
		$this->assertObjectHasProperty( 'linked_account', $coauthor );
		$this->assertEquals( $this->editor1->user_login, $coauthor->linked_account );
	}

	/**
	 * This test fully validates the expected behavior of the
	 * CoAuthors_Plus::get_coauthor_by function.
	 *
	 * @covers ::get_coauthor_by
	 */
	public function test_get_coauthor_by() {
		$author = $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => 'i_am_batman',
				'display_name' => 'Bruce Wayne',
				'first_name'   => 'Bruce',
				'last_name'    => 'Wayne',
			)
		);

		$first_author_retrieval = $this->_cap->get_coauthor_by( 'user_nicename', $author->user_nicename );
		$this->assertInstanceOf( WP_User::class, $first_author_retrieval );
		$this->assertEquals(
			$author->ID,
			$first_author_retrieval->ID
		);

		$maybe_guest_author_id = $this->_cap->guest_authors->create_guest_author_from_user_id( $author->ID );

		$this->assertIsInt( $maybe_guest_author_id );

		$guest_author = $this->_cap->guest_authors->get_guest_author_by( 'id', $maybe_guest_author_id, true );

		$this->assertIsGuestAuthorNotWpUser( $guest_author );
		$this->assertNotSame( $author->user_login, $guest_author->user_login );
		$this->assertNotSame( $author->user_nicename, $guest_author->user_nicename );
		$this->assertObjectHasProperty( 'type', $guest_author );
		$this->assertEquals( 'guest-author', $guest_author->type );

		$third_author_retrieval = $this->_cap->get_coauthor_by( 'user_nicename', $guest_author->user_nicename );
		$this->assertIsGuestAuthorNotWpUser( $third_author_retrieval );
		$this->assertObjectHasProperty( 'wp_user', $third_author_retrieval );
		$this->assertInstanceOf( WP_User::class, $third_author_retrieval->wp_user );
		$this->assertEquals( $author->ID, $third_author_retrieval->wp_user->ID );

		$fourth_author_retrieval = $this->_cap->get_coauthor_by( 'user_nicename', $author->user_nicename );
		$this->assertIsGuestAuthorNotWpUser( $fourth_author_retrieval );
		$this->assertEquals( 'guest-author', $fourth_author_retrieval->type );
		$this->assertObjectHasProperty( 'wp_user', $fourth_author_retrieval );
		$this->assertInstanceOf( WP_User::class, $fourth_author_retrieval->wp_user );
		$this->assertEquals( $author->data->ID, $fourth_author_retrieval->wp_user->data->ID );
		$this->assertEquals( $author->data->user_login, $fourth_author_retrieval->wp_user->data->user_login );
		$this->assertEquals( $author->data->user_nicename, $fourth_author_retrieval->wp_user->data->user_nicename );

		$random_username = 'random_user_' . wp_rand( 1, 1000 );
		$display_name    = str_replace( '_', ' ', $random_username );

		$this->_cap->guest_authors->create(
			array(
				'user_login'   => $random_username,
				'display_name' => $display_name,
			)
		);
		$fifth_author_retrieval = $this->_cap->get_coauthor_by( 'user_login', $random_username );
		$this->assertIsGuestAuthorNotWpUser( $fifth_author_retrieval );
		$this->assertObjectNotHasProperty( 'wp_user', $fifth_author_retrieval );

		// Simulating a broken linked_account relationship.
		$random_login = 'random_user_' . wp_rand( 1001, 2000 );
		update_post_meta( $fifth_author_retrieval->ID, 'cap-linked_account', $random_login );
		$fifth_author_retrieval = $this->_cap->get_coauthor_by( 'user_login', $random_username );
		$this->assertObjectHasProperty( 'linked_account', $fifth_author_retrieval );
		$this->assertEquals( $random_login, $fifth_author_retrieval->linked_account );
		$this->assertObjectNotHasProperty( 'wp_user', $fifth_author_retrieval );

		add_filter( 'coauthors_guest_authors_enabled', '__return_false' );

		$sixth_author_retrieval = $this->_cap->get_coauthor_by( 'user_nicename', $guest_author->user_nicename );

		$this->assertFalse( $sixth_author_retrieval );

		$seventh_author_retrieval = $this->_cap->get_coauthor_by( 'user_nicename', $author->user_nicename );

		$this->assertInstanceOf( WP_User::class, $seventh_author_retrieval );
		$this->assertEquals( $author->data->ID, $seventh_author_retrieval->data->ID );
		$this->assertEquals( $author->data->user_login, $seventh_author_retrieval->data->user_login );
		$this->assertEquals( $author->data->user_nicename, $seventh_author_retrieval->data->user_nicename );

		$eigth_author_retrieval = $this->_cap->get_coauthor_by( 'user_login', $random_username );

		$this->assertFalse( $eigth_author_retrieval );
	}
}
