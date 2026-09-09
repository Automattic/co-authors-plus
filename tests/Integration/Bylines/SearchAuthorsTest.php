<?php
/**
 * Tests for searching matching co-authors.
 *
 * Covers CoAuthors_Plus::search_authors() with no arguments, a search keyword,
 * an ignored-authors list, and the combination of the two.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Plus
 */
class SearchAuthorsTest extends TestCase {

	private $author1;

	private $editor1;

	public function set_up() {
		parent::set_up();

		$this->author1 = $this->create_author( 'author1' );
		$this->editor1 = $this->create_editor( 'editor1' );
	}

	/**
	 * Checks matching co-authors based on a search value when no arguments provided.
	 *
	 * @covers ::search_authors
	 */
	public function test_search_authors_no_args(): void {

		global $coauthors_plus;

		// Checks when search term is empty.
		$authors = $coauthors_plus->search_authors();

		$this->assertNotEmpty( $authors );
		$this->assertArrayHasKey( 'admin', $authors );
		$this->assertArrayHasKey( $this->author1->user_login, $authors );
		$this->assertArrayHasKey( $this->editor1->user_login, $authors );

		// Checks when search term is empty and any subscriber exists.
		$subscriber1 = $this->create_subscriber( 'subscriber1' );

		$authors = $coauthors_plus->search_authors();

		$this->assertNotEmpty( $authors );
		$this->assertArrayNotHasKey( $subscriber1->user_login, $authors );

		// Checks when search term is empty and any contributor exists.
		$contributor1 = $this->create_contributor( 'contributor1' );

		$authors = $coauthors_plus->search_authors();

		$this->assertNotEmpty( $authors );
		$this->assertArrayHasKey( $contributor1->user_login, $authors );
	}

	/**
	 * Checks matching co-authors based on a search value when only search keyword is provided.
	 *
	 * @covers ::search_authors
	 */
	public function test_search_authors_when_search_keyword_provided(): void {

		global $coauthors_plus;

		// Checks when author does not exist with searched term.
		$this->assertEmpty( $coauthors_plus->search_authors( 'test' ) );

		// Checks when author searched using ID.
		$authors = $coauthors_plus->search_authors( $this->author1->ID );

		$this->assertNotEmpty( $authors );
		$this->assertArrayHasKey( $this->author1->user_login, $authors );
		$this->assertArrayNotHasKey( $this->editor1->user_login, $authors );
		$this->assertArrayNotHasKey( 'admin', $authors );

		// Checks when author searched using display_name.
		$authors = $coauthors_plus->search_authors( $this->author1->display_name );

		$this->assertNotEmpty( $authors );
		$this->assertArrayHasKey( $this->author1->user_login, $authors );
		$this->assertArrayNotHasKey( $this->editor1->user_login, $authors );
		$this->assertArrayNotHasKey( 'admin', $authors );

		// Checks when author searched using user_email.
		$authors = $coauthors_plus->search_authors( $this->author1->user_email );

		$this->assertNotEmpty( $authors );
		$this->assertArrayHasKey( $this->author1->user_login, $authors );
		$this->assertArrayNotHasKey( $this->editor1->user_login, $authors );
		$this->assertArrayNotHasKey( 'admin', $authors );

		// Checks when author searched using user_login.
		$authors = $coauthors_plus->search_authors( $this->author1->user_login );

		$this->assertNotEmpty( $authors );
		$this->assertArrayHasKey( $this->author1->user_login, $authors );
		$this->assertArrayNotHasKey( $this->editor1->user_login, $authors );
		$this->assertArrayNotHasKey( 'admin', $authors );

		// Checks when any subscriber exists using ID but not author.
		$subscriber1 = $this->create_subscriber( 'subscriber_keyword' );

		$this->assertEmpty( $coauthors_plus->search_authors( $subscriber1->ID ) );
	}

	/**
	 * Checks matching co-authors based on a search value when only ignore authors are provided.
	 *
	 * @covers ::search_authors
	 */
	public function test_search_authors_when_ignored_authors_provided(): void {

		global $coauthors_plus;

		// Ignoring single author.
		$ignored_authors = array( $this->author1->user_nicename );

		$authors = $coauthors_plus->search_authors( '', $ignored_authors );

		$this->assertNotEmpty( $authors );
		$this->assertArrayNotHasKey( $this->author1->user_login, $authors );

		// Checks when ignoring author1 but also exists one more author with similar kind of data.
		$author2 = $this->create_author( 'author2' );

		$authors = $coauthors_plus->search_authors( '', $ignored_authors );

		$this->assertNotEmpty( $authors );
		$this->assertArrayNotHasKey( $this->author1->user_login, $authors );
		$this->assertArrayHasKey( $author2->user_login, $authors );

		// Ignoring multiple authors.
		$authors = $coauthors_plus->search_authors( '', array( $this->author1->user_nicename, $author2->user_nicename ) );

		$this->assertNotEmpty( $authors );
		$this->assertArrayNotHasKey( $this->author1->user_login, $authors );
		$this->assertArrayNotHasKey( $author2->user_login, $authors );
	}

	/**
	 * Checks matching co-authors based on a search value when search keyword as well as ignore authors are provided.
	 *
	 * @covers ::search_authors
	 */
	public function test_search_authors_when_search_keyword_and_ignored_authors_provided(): void {

		global $coauthors_plus;

		// Checks when ignoring author1.
		$ignored_authors = array( $this->author1->user_nicename );

		$this->assertEmpty( $coauthors_plus->search_authors( $this->author1->ID, $ignored_authors ) );

		// Checks when ignoring author1 but also exists one more author with similar kind of data.
		$author2 = $this->create_author( 'author2' );

		$authors = $coauthors_plus->search_authors( 'author', $ignored_authors );

		$this->assertNotEmpty( $authors );
		$this->assertArrayNotHasKey( $this->author1->user_login, $authors );
		$this->assertArrayHasKey( $author2->user_login, $authors );
	}

	/**
	 * Checks search_authors() returns all matches when more than 10 share a prefix.
	 *
	 * @covers ::search_authors
	 */
	public function test_search_authors_returns_all_results_beyond_default_limit(): void {

		global $coauthors_plus;

		$prefix  = 'maxtest';
		$editors = array();

		for ( $i = 1; $i <= 7; $i++ ) {
			$editors[] = $this->create_editor( "{$prefix}_editor_{$i}" );
		}

		$guest_logins = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$guest_logins[] = "{$prefix}_guest_{$i}";
			$this->create_guest_author( "{$prefix}_guest_{$i}" );
		}

		$authors = $coauthors_plus->search_authors( $prefix );

		$this->assertGreaterThanOrEqual( 13, count( $authors ) );

		foreach ( $editors as $editor ) {
			$this->assertArrayHasKey( $editor->user_login, $authors );
		}
		foreach ( $guest_logins as $guest_login ) {
			$this->assertArrayHasKey( $guest_login, $authors );
		}
	}

	/**
	 * Checks search_authors() excludes a subscriber sharing a prefix with an editor.
	 *
	 * @covers ::search_authors
	 */
	public function test_search_authors_excludes_low_cap_users_from_keyword_search(): void {

		global $coauthors_plus;

		$prefix     = 'capcheck';
		$editor     = $this->create_editor( "{$prefix}_editor" );
		$subscriber = $this->create_subscriber( "{$prefix}_subscriber" );

		$authors = $coauthors_plus->search_authors( $prefix );

		$this->assertArrayHasKey( $editor->user_login, $authors );
		$this->assertArrayNotHasKey( $subscriber->user_login, $authors );
	}

	/**
	 * Checks search_authors() does not create an author term for a subscriber.
	 *
	 * @covers ::search_authors
	 */
	public function test_search_authors_does_not_backfill_terms_for_low_cap_users(): void {

		global $coauthors_plus;

		$prefix     = 'termgrowth';
		$subscriber = $this->create_subscriber( "{$prefix}_subscriber" );

		$this->assertEmpty( $coauthors_plus->get_author_term( $subscriber ) );

		$coauthors_plus->search_authors( $prefix );

		$this->assertEmpty( $coauthors_plus->get_author_term( $subscriber ) );
	}

	/**
	 * Checks search_authors() still includes a user granted the capability
	 * directly rather than through a role, now that role__in is gone.
	 *
	 * Regression test for the case GaryJones reproduced on wp-env WP 7.1:
	 * capability combined with role__in incorrectly dropped a subscriber
	 * granted edit_posts directly as a per-user capability.
	 *
	 * @covers ::search_authors
	 */
	public function test_search_authors_includes_user_with_directly_granted_capability(): void {

		global $coauthors_plus;

		$prefix     = 'directcap';
		$editor     = $this->create_editor( "{$prefix}_editor" );
		$subscriber = $this->create_subscriber( "{$prefix}_subscriber" );
		$subscriber->add_cap( 'edit_posts' );

		$authors = $coauthors_plus->search_authors( $prefix );

		$this->assertArrayHasKey( $editor->user_login, $authors );
		$this->assertArrayHasKey( $subscriber->user_login, $authors );
	}
}
