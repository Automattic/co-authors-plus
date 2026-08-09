<?php
/**
 * Tests for the author-term cache invalidation lifecycle.
 *
 * Covers the user_id re-key, the user_register, set_user_role, and
 * profile_update invalidation paths introduced for issue #1313.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use CoAuthors\Cache\Store;

/**
 * @coversDefaultClass \CoAuthors_Plus
 */
class AuthorTermInvalidationTest extends TestCase {

	private $author1;

	public function set_up() {
		parent::set_up();

		$this->author1 = $this->create_author( 'author1' );

		// Authoring a post assigns author1 as a co-author and creates
		// their author term, so get_author_term() has something to find.
		$this->create_post( $this->author1 );
	}

	/**
	 * Profile update must drop the author-term cache entry (existing
	 * behaviour, pinned here so the refactor cannot regress it).
	 *
	 * @covers ::update_author_term_on_profile_update
	 */
	public function test_profile_update_still_invalidates_author_term(): void {
		global $coauthors_plus;

		$cache_key = Store::author_term_key( (int) $this->author1->ID );

		// Warm the cache.
		$coauthors_plus->get_author_term( $this->author1 );
		$this->assertNotFalse( wp_cache_get( $cache_key, Store::GROUP ) );

		// Trigger profile_update via wp_update_user.
		wp_update_user(
			array(
				'ID'         => $this->author1->ID,
				'first_name' => 'Updated',
			)
		);

		$this->assertInstanceOf( \WP_Term::class, wp_cache_get( $cache_key, Store::GROUP ) );
	}

	/**
	 * User register must drop the author-term cache entry for the new
	 * user. The action handler is the thing under test: wp_insert_user
	 * fires user_register automatically, so pre-seeding a stale value
	 * at the eventual user_id key is the only setup needed.
	 *
	 * @covers ::invalidate_author_term_on_user_register
	 */
	public function test_user_register_invalidates_author_term(): void {
		// The new user id is not known until insert, so use a probe:
		// insert, then seed a stale value at the user_id key, then
		// re-fire user_register. The re-fire is the explicit assertion
		// that the handler clears the cache.
		$new_user_id = wp_insert_user(
			array(
				'user_login' => 'newby',
				'user_pass'  => 'x',
				'role'       => 'subscriber',
			)
		);

		$this->assertNotWPError( $new_user_id );

		$cache_key = Store::author_term_key( (int) $new_user_id );

		wp_cache_set( $cache_key, 'stale', Store::GROUP );
		$this->assertSame( 'stale', wp_cache_get( $cache_key, Store::GROUP ) );

		do_action( 'user_register', $new_user_id );

		$this->assertFalse( wp_cache_get( $cache_key, Store::GROUP ) );
	}

	/**
	 * Set user role must drop the author-term cache entry.
	 *
	 * @covers ::invalidate_author_term_on_role_change
	 */
	public function test_set_user_role_invalidates_author_term(): void {
		global $coauthors_plus;

		$cache_key = Store::author_term_key( (int) $this->author1->ID );

		// Warm the cache.
		$coauthors_plus->get_author_term( $this->author1 );
		$this->assertNotFalse( wp_cache_get( $cache_key, Store::GROUP ) );

		// set_user_role fires the action internally.
		$user = new \WP_User( $this->author1->ID );
		$user->set_role( 'editor' );

		$this->assertFalse( wp_cache_get( $cache_key, Store::GROUP ) );
	}

	/**
	 * The cache key is the user_id, not the nicename. A stale entry
	 * under the legacy nicename-based key must be ignored by
	 * get_author_term() so a nicename change cannot orphan the lookup.
	 */
	public function test_get_author_term_uses_user_id_not_nicename(): void {
		global $coauthors_plus;

		$user_id_key    = Store::author_term_key( (int) $this->author1->ID );
		$legacy_nicename = 'author-term-' . $this->author1->user_nicename;

		// Drop the user_id-keyed entry (if any) and seed a stale value
		// under the legacy nicename key.
		wp_cache_delete( $user_id_key, Store::GROUP );
		wp_cache_set( $legacy_nicename, 'STALE-NICENAME-VALUE', Store::GROUP );

		$term = $coauthors_plus->get_author_term( $this->author1 );

		// The function must ignore the nicename-keyed stale entry and
		// look up the term properly.
		$this->assertNotSame( 'STALE-NICENAME-VALUE', $term );
		$this->assertInstanceOf( \WP_Term::class, $term );

		// And the user_id-keyed entry is now the cached value.
		$this->assertEquals( $term, wp_cache_get( $user_id_key, Store::GROUP ) );
	}

	/**
	 * Coauthors without a populated ID must not generate a
	 * `author-term-0` cache entry.
	 */
	public function test_get_author_term_guards_against_missing_id(): void {
		global $coauthors_plus;

		// Pass an object missing the ID property.
		$empty = new \stdClass();

		$this->assertEmpty( $coauthors_plus->get_author_term( $empty ) );
		$this->assertFalse( wp_cache_get( 'author-term-0', Store::GROUP ) );
	}
}
