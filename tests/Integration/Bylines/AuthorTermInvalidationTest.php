<?php
/**
 * Tests for the author-term cache invalidation lifecycle.
 *
 * Covers the profile_update, user_register, and set_user_role invalidation
 * paths introduced for issue #1313.
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
	 * Profile update must drop and rebuild the nicename-keyed cache entry.
	 *
	 * @covers ::update_author_term_on_profile_update
	 */
	public function test_profile_update_still_invalidates_author_term(): void {
		global $coauthors_plus;

		$cache_key = Keys::author_term_key( (string) $this->author1->user_nicename );

		// Warm the cache.
		$coauthors_plus->get_author_term( $this->author1 );
		$this->assertNotFalse( wp_cache_get( $cache_key, Keys::GROUP ) );

		// Trigger profile_update via wp_update_user.
		wp_update_user(
			array(
				'ID'         => $this->author1->ID,
				'first_name' => 'Updated',
			)
		);

		$this->assertInstanceOf( \WP_Term::class, wp_cache_get( $cache_key, Keys::GROUP ) );
	}

	/**
	 * User register must drop the nicename-keyed author-term entry.
	 *
	 * @covers ::invalidate_author_term_on_user_register
	 */
	public function test_user_register_invalidates_author_term(): void {
		$new_user_id = wp_insert_user(
			array(
				'user_login' => 'newby',
				'user_pass'  => 'x',
				'role'       => 'subscriber',
			)
		);

		$this->assertNotWPError( $new_user_id );
		$user      = get_userdata( $new_user_id );
		$cache_key = Keys::author_term_key( (string) $user->user_nicename );

		wp_cache_set( $cache_key, 'stale', Keys::GROUP );
		$this->assertSame( 'stale', wp_cache_get( $cache_key, Keys::GROUP ) );

		do_action( 'user_register', $new_user_id );

		$this->assertFalse( wp_cache_get( $cache_key, Keys::GROUP ) );
	}

	/**
	 * Set user role must drop the nicename-keyed author-term entry.
	 *
	 * @covers ::invalidate_author_term_on_role_change
	 */
	public function test_set_user_role_invalidates_author_term(): void {
		$cache_key = Keys::author_term_key( (string) $this->author1->user_nicename );

		wp_cache_set( $cache_key, 'stale', Keys::GROUP );
		$this->assertSame( 'stale', wp_cache_get( $cache_key, Keys::GROUP ) );

		$user = new \WP_User( $this->author1->ID );
		$user->set_role( 'editor' );

		$this->assertFalse( wp_cache_get( $cache_key, Keys::GROUP ) );
	}
}
