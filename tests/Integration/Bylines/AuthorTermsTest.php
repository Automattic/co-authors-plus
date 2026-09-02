<?php
/**
 * Tests for reading and updating a co-author's taxonomy term.
 *
 * Covers CoAuthors_Plus::get_author_term() and update_author_term(), including
 * the non-object guard, the caching behaviour, linked/unlinked accounts and the
 * term-description rebuild.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Cache\Keys;
use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Plus
 */
class AuthorTermsTest extends TestCase {

	private $author1;

	private $editor1;

	public function set_up() {
		parent::set_up();

		$this->author1 = $this->create_author( 'author1' );
		$this->editor1 = $this->create_editor( 'editor1' );

		// Authoring a post assigns author1 as a co-author, which creates their author
		// term. get_author_term() looks up an existing term; it does not create one.
		$this->create_post( $this->author1 );
	}

	/**
	 * Checks the author term for a given co-author when passed co-author is not an object.
	 *
	 * @covers ::get_author_term
	 */
	public function test_get_author_term_when_coauthor_is_not_object(): void {

		global $coauthors_plus;

		$this->assertEmpty( $coauthors_plus->get_author_term( '' ) );
		$this->assertEmpty( $coauthors_plus->get_author_term( $this->author1->ID ) );
		$this->assertEmpty( $coauthors_plus->get_author_term( (array) $this->author1 ) );
		$this->assertEmpty( $coauthors_plus->get_author_term( new \stdClass() ) );
	}

	/**
	 * Checks the author term for a given co-author using cache.
	 *
	 * @covers ::get_author_term
	 */
	public function test_get_author_term_using_caching(): void {

		global $coauthors_plus;

		$cache_key = Keys::author_term_key( (string) $this->author1->user_nicename );

		// Checks when term does not exist in cache.
		$this->assertFalse( wp_cache_get( $cache_key, Keys::GROUP ) );

		// Checks when term exists in cache.
		$author_term        = $coauthors_plus->get_author_term( $this->author1 );
		$author_term_cached = wp_cache_get( $cache_key, Keys::GROUP );

		$this->assertInstanceOf( \WP_Term::class, $author_term );
		$this->assertEquals( $author_term, $author_term_cached );
	}

	/**
	 * Checks the author term for a given co-author with having linked account.
	 *
	 * @covers ::get_author_term
	 */
	public function test_get_author_term_when_author_has_linked_account(): void {

		global $coauthors_plus;

		// Checks when term exists using linked account.
		$coauthor_id = $coauthors_plus->guest_authors->create_guest_author_from_user_id( $this->editor1->ID );
		$coauthor    = $coauthors_plus->get_coauthor_by( 'id', $coauthor_id );

		$author_term = $coauthors_plus->get_author_term( $coauthor );

		$this->assertInstanceOf( \WP_Term::class, $author_term );

		// Checks when term does not exist or deleted somehow.
		wp_delete_term( $author_term->term_id, $author_term->taxonomy );

		$this->assertFalse( $coauthors_plus->get_author_term( $coauthor ) );
	}

	/**
	 * Checks the author term for a given co-author without having linked account.
	 *
	 * @covers ::get_author_term
	 */
	public function test_get_author_term_when_author_has_not_linked_account(): void {

		global $coauthors_plus;

		// Checks when term exists without linked account.
		$coauthor_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'guest',
				'user_login'   => 'guest',
			)
		);
		$coauthor    = $coauthors_plus->get_coauthor_by( 'id', $coauthor_id );

		$author_term = $coauthors_plus->get_author_term( $coauthor );

		$this->assertInstanceOf( \WP_Term::class, $author_term );

		// Checks when term does not exist or deleted somehow.
		wp_delete_term( $author_term->term_id, $author_term->taxonomy );

		$this->assertFalse( $coauthors_plus->get_author_term( $coauthor ) );
	}

	/**
	 * Checks update author term when passed coauthor is not an object.
	 *
	 * @covers ::update_author_term
	 */
	public function test_update_author_term_when_coauthor_is_not_object(): void {

		global $coauthors_plus;

		$this->assertEmpty( $coauthors_plus->update_author_term( '' ) );
		$this->assertEmpty( $coauthors_plus->update_author_term( $this->author1->ID ) );
		$this->assertEmpty( $coauthors_plus->update_author_term( (array) $this->author1 ) );
	}

	/**
	 * Checks update author term when author term exists for passed coauthor.
	 *
	 * @covers ::update_author_term
	 */
	public function test_update_author_term_when_author_term_exists(): void {

		global $coauthors_plus;

		// Checks term description.
		$author_term = $coauthors_plus->update_author_term( $this->author1 );

		// In "update_author_term()", only description is being updated, so asserting that only ( here and everywhere ).
		$this->assertEquals( $this->author1->display_name . ' ' . $this->author1->first_name . ' ' . $this->author1->last_name . ' ' . $this->author1->user_login . ' ' . $this->author1->ID . ' ' . $this->author1->user_email, $author_term->description );

		// Checks term description after updating user.
		wp_update_user(
			array(
				'ID'         => $this->author1->ID,
				'first_name' => 'author1',
			)
		);

		$author_term = $coauthors_plus->update_author_term( $this->author1 );

		$this->assertEquals( $this->author1->display_name . ' ' . $this->author1->first_name . ' ' . $this->author1->last_name . ' ' . $this->author1->user_login . ' ' . $this->author1->ID . ' ' . $this->author1->user_email, $author_term->description );

		// Backup coauthor taxonomy.
		$taxonomy_backup = $coauthors_plus->coauthor_taxonomy;

		wp_update_user(
			array(
				'ID'        => $this->author1->ID,
				'last_name' => 'author1',
			)
		);

		// Checks with different taxonomy.
		$coauthors_plus->coauthor_taxonomy = 'abcd';

		$this->assertFalse( $coauthors_plus->update_author_term( $this->author1 ) );

		// Restore coauthor taxonomy from backup.
		$coauthors_plus->coauthor_taxonomy = $taxonomy_backup;
	}

	/**
	 * Checks update author term when author term does not exist for passed coauthor.
	 *
	 * @covers ::update_author_term
	 */
	public function test_update_author_term_when_author_term_not_exist(): void {

		global $coauthors_plus;

		// Checks term description.
		$author_term = $coauthors_plus->update_author_term( $this->editor1 );

		$this->assertEquals( $this->editor1->display_name . ' ' . $this->editor1->first_name . ' ' . $this->editor1->last_name . ' ' . $this->editor1->user_login . ' ' . $this->editor1->ID . ' ' . $this->editor1->user_email, $author_term->description );

		// Checks term description after updating user.
		wp_update_user(
			array(
				'ID'         => $this->editor1->ID,
				'first_name' => 'editor1',
			)
		);

		$author_term = $coauthors_plus->update_author_term( $this->editor1 );

		$this->assertEquals( $this->editor1->display_name . ' ' . $this->editor1->first_name . ' ' . $this->editor1->last_name . ' ' . $this->editor1->user_login . ' ' . $this->editor1->ID . ' ' . $this->editor1->user_email, $author_term->description );

		// Backup coauthor taxonomy.
		$taxonomy_backup = $coauthors_plus->coauthor_taxonomy;

		wp_update_user(
			array(
				'ID'        => $this->editor1->ID,
				'last_name' => 'editor1',
			)
		);

		// Checks with different taxonomy.
		$coauthors_plus->coauthor_taxonomy = 'abcd';

		$this->assertFalse( $coauthors_plus->update_author_term( $this->editor1 ) );

		// Restore coauthor taxonomy from backup.
		$coauthors_plus->coauthor_taxonomy = $taxonomy_backup;
	}
}
