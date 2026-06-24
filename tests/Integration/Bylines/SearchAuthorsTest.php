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
}
