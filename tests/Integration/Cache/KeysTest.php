<?php
/**
 * Tests for the Automattic\CoAuthorsPlus\Cache\Keys helper.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Cache;

use Automattic\CoAuthorsPlus\Cache\Keys;
use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Cache\Keys helper tests.
 */
class KeysTest extends TestCase {

	/**
	 * The unified cache group should be 'co-authors-plus' and the
	 * deprecated guest-authors static should resolve to the same value.
	 */
	public function test_group_is_unified(): void {
		$this->assertSame( 'co-authors-plus', Keys::GROUP );
		$this->assertSame( Keys::GROUP, \CoAuthors_Guest_Authors::$cache_group );
	}

	/**
	 * Author term key must use the user_nicename, not the user_id.
	 *
	 * Numeric IDs collide across wp_users and the guest-author CPT
	 * (independent auto-increment sequences), so any future re-key to
	 * user_id must fail loud here.
	 */
	public function test_author_term_key_uses_nicename(): void {
		$this->assertSame( 'author-term-jane', Keys::author_term_key( 'jane' ) );
	}

	/**
	 * Coauthors post key must include the post id.
	 */
	public function test_coauthors_post_key_format(): void {
		$this->assertSame( 'coauthors_post_42', Keys::coauthors_post_key( 42 ) );
	}

	/**
	 * All linked accounts key returns a single shared key.
	 */
	public function test_all_linked_accounts_key_format(): void {
		$this->assertSame( 'all-linked-accounts', Keys::all_linked_accounts_key() );
	}

	/**
	 * Guest author key with post_name must strip the cap- prefix and
	 * re-key on user_nicename. This is the structural reason the helper
	 * was extracted from CoAuthors_Guest_Authors::get_cache_key().
	 */
	public function test_guest_author_key_strips_cap_prefix(): void {
		$with_prefix    = Keys::guest_author_key( 'post_name', 'cap-jane' );
		$without_prefix = Keys::guest_author_key( 'post_name', 'jane' );

		// Same lookup regardless of whether caller passed the cap- prefix.
		$this->assertSame( $without_prefix, $with_prefix );

		// And the underlying normalisation re-keys on user_nicename.
		$expected_nicename = Keys::guest_author_key( 'user_nicename', 'jane' );
		$this->assertSame( $expected_nicename, $with_prefix );
	}

	/**
	 * Guest author key with login must re-key on user_login.
	 */
	public function test_guest_author_key_normalizes_login_to_user_login(): void {
		$login_key  = Keys::guest_author_key( 'login', 'jane' );
		$user_login = Keys::guest_author_key( 'user_login', 'jane' );
		$expected   = md5( 'guest-author-user_login-jane' );

		$this->assertSame( $expected, $login_key );
		$this->assertSame( $user_login, $login_key );
	}

	/**
	 * The deprecated BC wrappers on CoAuthors_Guest_Authors must still
	 * produce the same key as the helper.
	 */
	public function test_guest_authors_get_cache_key_matches_helper(): void {
		global $coauthors_plus;
		$this->assertNotNull( $coauthors_plus );
		$ga = $coauthors_plus->guest_authors;

		$this->assertSame(
			Keys::guest_author_key( 'post_name', 'cap-jane' ),
			$ga->get_cache_key( 'post_name', 'cap-jane' )
		);
		$this->assertSame(
			Keys::guest_author_key( 'login', 'jane' ),
			$ga->get_cache_key( 'login', 'jane' )
		);
	}

	/**
	 * Author-term keys must remain distinct when a WP user and guest author
	 * have equal numeric IDs but different nicenames.
	 */
	public function test_author_term_key_avoids_cross_table_id_collision(): void {
		$wp_user_id       = 7;
		$guest_author_id  = 7;
		$wp_user_key      = Keys::author_term_key( 'jane' );
		$guest_author_key = Keys::author_term_key( 'john' );

		$this->assertSame( $wp_user_id, $guest_author_id );
		$this->assertNotSame( $wp_user_key, $guest_author_key );
	}
}
