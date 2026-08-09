<?php
/**
 * Tests for the CoAuthors\Cache\Store helper.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Cache;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use CoAuthors\Cache\Store;

/**
 * Cache\Store helper tests.
 */
class StoreTest extends TestCase {

	/**
	 * The unified cache group should be 'co-authors-plus' and the
	 * deprecated guest-authors static should resolve to the same value.
	 */
	public function test_group_is_unified(): void {
		$this->assertSame( 'co-authors-plus', Store::GROUP );
		$this->assertSame( Store::GROUP, \CoAuthors_Guest_Authors::$cache_group );
	}

	/**
	 * Basic CRUD roundtrip via the wrapper helpers.
	 */
	public function test_get_set_delete_roundtrip(): void {
		$key = 'store-test-roundtrip';

		$this->assertFalse( Store::get( $key ) );

		$this->assertTrue( Store::set( $key, 'value' ) );
		$this->assertSame( 'value', Store::get( $key ) );

		$this->assertTrue( Store::delete( $key ) );
		$this->assertFalse( Store::get( $key ) );
	}

	/**
	 * Author term key must use the user_id, not the nicename.
	 */
	public function test_author_term_key_format(): void {
		$this->assertSame( 'author-term-7', Store::author_term_key( 7 ) );
	}

	/**
	 * Coauthors post key must include the post id.
	 */
	public function test_coauthors_post_key_format(): void {
		$this->assertSame( 'coauthors_post_42', Store::coauthors_post_key( 42 ) );
	}

	/**
	 * All linked accounts key returns a single shared key.
	 */
	public function test_all_linked_accounts_key_format(): void {
		$this->assertSame( 'all-linked-accounts', Store::all_linked_accounts_key() );
	}

	/**
	 * Guest author key with post_name must strip the cap- prefix and
	 * re-key on user_nicename. This is the structural reason the helper
	 * was extracted from CoAuthors_Guest_Authors::get_cache_key().
	 */
	public function test_guest_author_key_strips_cap_prefix(): void {
		$with_prefix    = Store::guest_author_key( 'post_name', 'cap-jane' );
		$without_prefix = Store::guest_author_key( 'post_name', 'jane' );

		// Same lookup regardless of whether caller passed the cap- prefix.
		$this->assertSame( $without_prefix, $with_prefix );

		// And the underlying normalisation re-keys on user_nicename.
		$expected_nicename = Store::guest_author_key( 'user_nicename', 'jane' );
		$this->assertSame( $expected_nicename, $with_prefix );
	}

	/**
	 * Guest author key with login must re-key on user_login.
	 */
	public function test_guest_author_key_normalizes_login_to_user_login(): void {
		$login_key     = Store::guest_author_key( 'login', 'jane' );
		$user_login    = Store::guest_author_key( 'user_login', 'jane' );
		$expected      = md5( 'guest-author-user_login-jane' );

		$this->assertSame( $expected, $login_key );
		$this->assertSame( $user_login, $login_key );
	}

	/**
	 * The deprecated BC wrappers on CoAuthors_Guest_Authors must still
	 * produce the same key as the helper.
	 */
	public function test_guest_authors_get_cache_key_matches_helper(): void {
		global $coauthors_plus;
		$ga = $coauthors_plus->guest_authors;

		$this->assertSame(
			Store::guest_author_key( 'post_name', 'cap-jane' ),
			$ga->get_cache_key( 'post_name', 'cap-jane' )
		);
		$this->assertSame(
			Store::guest_author_key( 'login', 'jane' ),
			$ga->get_cache_key( 'login', 'jane' )
		);
	}
}
