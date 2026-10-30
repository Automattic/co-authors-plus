<?php
/**
 * Tests for the 'cap-' prefix handling on guest author keys.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Guest author postmeta keys and cache keys both hinge on a 'cap-' prefix.
 *
 * The get_post_meta_key() method adds the prefix unless it is already there,
 * matching case-insensitively; get_cache_key() strips it from a post_name
 * before hashing, matching case-sensitively. Those two are deliberately different,
 * and the difference had no test, which made rewriting either check a
 * guessing game.
 *
 * @covers \CoAuthors_Guest_Authors::get_post_meta_key
 * @covers \CoAuthors_Guest_Authors::get_cache_key
 */
class GuestAuthorKeyPrefixTest extends TestCase {

	/**
	 * @var \CoAuthors_Guest_Authors
	 */
	private $guest_authors;

	public function set_up() {
		parent::set_up();

		$this->guest_authors = $this->_cap->guest_authors;
	}

	/**
	 * Keys and the prefixed form each is expected to produce.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function data_meta_keys(): array {
		return array(
			'unprefixed key gains the prefix'      => array( 'first_name', 'cap-first_name' ),
			'prefixed key is left alone'           => array( 'cap-first_name', 'cap-first_name' ),
			'upper-case prefix is left alone'      => array( 'CAP-first_name', 'CAP-first_name' ),
			'mixed-case prefix is left alone'      => array( 'Cap-first_name', 'Cap-first_name' ),
			'prefix elsewhere still gains one'     => array( 'linked_cap-account', 'cap-linked_cap-account' ),
			'a key that merely starts with "cap"'  => array( 'capital', 'cap-capital' ),
			'empty key gains the bare prefix'      => array( '', 'cap-' ),
		);
	}

	/**
	 * The prefix is added exactly once, whatever case it arrives in.
	 *
	 * The case-insensitivity is the point: 'CAP-first_name' must not become
	 * 'cap-CAP-first_name' and start reading a postmeta key nothing writes.
	 *
	 * @dataProvider data_meta_keys
	 *
	 * @param string $key      Key as supplied.
	 * @param string $expected Key as it should come back.
	 */
	public function test_prefixes_a_meta_key_once( string $key, string $expected ): void {
		$this->assertSame( $expected, $this->guest_authors->get_post_meta_key( $key ) );
	}

	/**
	 * Prefixing is idempotent.
	 *
	 * @dataProvider data_meta_keys
	 *
	 * @param string $key      Key as supplied.
	 * @param string $expected Key as it should come back.
	 */
	public function test_prefixing_a_meta_key_twice_changes_nothing( string $key, string $expected ): void {
		$once = $this->guest_authors->get_post_meta_key( $key );

		$this->assertSame( $once, $this->guest_authors->get_post_meta_key( $once ) );
		$this->assertSame( $expected, $once );
	}

	/**
	 * A post_name loses its 'cap-' prefix before the cache key is hashed.
	 */
	public function test_cache_key_strips_the_prefix_from_a_post_name(): void {
		$this->assertSame(
			$this->guest_authors->get_cache_key( 'user_nicename', 'ada' ),
			$this->guest_authors->get_cache_key( 'post_name', 'cap-ada' )
		);
	}

	/**
	 * That strip is case-sensitive, unlike the meta key prefix.
	 *
	 * 'CAP-ada' is not a slug WordPress would produce, so it is treated as an
	 * ordinary nicename rather than a prefixed one.
	 */
	public function test_cache_key_prefix_strip_is_case_sensitive(): void {
		$this->assertSame(
			$this->guest_authors->get_cache_key( 'user_nicename', 'CAP-ada' ),
			$this->guest_authors->get_cache_key( 'post_name', 'CAP-ada' )
		);
	}

	/**
	 * An unprefixed post_name is hashed as-is.
	 */
	public function test_cache_key_leaves_an_unprefixed_post_name_alone(): void {
		$this->assertSame(
			$this->guest_authors->get_cache_key( 'user_nicename', 'ada' ),
			$this->guest_authors->get_cache_key( 'post_name', 'ada' )
		);
	}

	/**
	 * Only post_name is treated as possibly prefixed.
	 */
	public function test_cache_key_does_not_strip_the_prefix_from_other_keys(): void {
		$this->assertNotSame(
			$this->guest_authors->get_cache_key( 'login', 'ada' ),
			$this->guest_authors->get_cache_key( 'login', 'cap-ada' )
		);
	}
}
