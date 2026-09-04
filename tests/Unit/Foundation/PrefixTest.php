<?php
/**
 * Unit tests for the 'cap-' prefix helper.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Foundation;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use CoAuthors\Prefix;

/**
 * The two rules the prefix follows, and the difference between them.
 *
 * Slug prefixing is unconditional and matches case-sensitively; meta key
 * prefixing is idempotent and matches case-insensitively. Getting either
 * backwards silently points a byline at the wrong author or reads a postmeta
 * key nothing writes, so both are pinned here rather than left to the reader.
 *
 * @covers \CoAuthors\Prefix
 */
final class PrefixTest extends TestCase {

	/**
	 * A slug is always prefixed, even when it already looks prefixed.
	 *
	 * This is the case that makes prefix_slug() deliberately not idempotent:
	 * a guest author called "Cap Ri" has the user_nicename `cap-ri`, and its
	 * term slug must be `cap-cap-ri`. Collapsing that to `cap-ri` would point
	 * the byline at whatever author owns that term.
	 *
	 * @dataProvider data_slugs_to_prefix
	 *
	 * @param string $nicename Input user_nicename.
	 * @param string $expected Expected term slug.
	 */
	public function test_prefix_slug_always_prefixes( string $nicename, string $expected ): void {
		$this->assertSame( $expected, Prefix::prefix_slug( $nicename ) );
	}

	/**
	 * Nicenames and the term slug each should produce.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function data_slugs_to_prefix(): array {
		return array(
			'ordinary nicename'    => array( 'ada-lovelace', 'cap-ada-lovelace' ),
			'nicename starting cap' => array( 'cap-ri', 'cap-cap-ri' ),
			'nicename containing cap' => array( 'recap-caption', 'cap-recap-caption' ),
			'empty nicename'       => array( '', 'cap-' ),
		);
	}

	/**
	 * Slug detection is case-sensitive.
	 *
	 * Slugs are lower-cased by sanitize_title(), so an upper-case slug cannot
	 * come from WordPress and is not treated as prefixed.
	 *
	 * @dataProvider data_slug_prefix_detection
	 *
	 * @param string $slug     Slug under test.
	 * @param bool   $expected Whether it should count as prefixed.
	 */
	public function test_slug_prefix_detection_is_case_sensitive( string $slug, bool $expected ): void {
		$this->assertSame( $expected, Prefix::slug_has_prefix( $slug ) );
	}

	/**
	 * Slugs and whether they count as prefixed.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function data_slug_prefix_detection(): array {
		return array(
			'prefixed'            => array( 'cap-ada', true ),
			'doubly prefixed'     => array( 'cap-cap-ri', true ),
			'bare prefix'         => array( 'cap-', true ),
			'unprefixed'          => array( 'ada-lovelace', false ),
			'prefix in the middle' => array( 'recap-caption', false ),
			'upper-case prefix'   => array( 'CAP-ada', false ),
			'no dash'             => array( 'capital', false ),
			'empty'               => array( '', false ),
		);
	}

	/**
	 * Stripping removes one leading prefix and nothing else.
	 *
	 * The `recap-caption` row is the bug this replaced: a str_replace() there
	 * returned `recaption`, and the duplicate-username guard that consumed it
	 * then looked up the wrong nicename.
	 *
	 * @dataProvider data_slugs_to_strip
	 *
	 * @param string $slug     Slug under test.
	 * @param string $expected Expected result.
	 */
	public function test_strip_slug_prefix_removes_only_a_leading_prefix( string $slug, string $expected ): void {
		$this->assertSame( $expected, Prefix::strip_slug_prefix( $slug ) );
	}

	/**
	 * Slugs and their stripped form.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function data_slugs_to_strip(): array {
		return array(
			'prefixed'             => array( 'cap-ada', 'ada' ),
			'doubly prefixed loses one' => array( 'cap-cap-ri', 'cap-ri' ),
			'prefix in the middle survives' => array( 'recap-caption', 'recap-caption' ),
			'trailing prefix survives' => array( 'cap-ri-cap', 'ri-cap' ),
			'unprefixed'           => array( 'ada-lovelace', 'ada-lovelace' ),
			'upper-case prefix survives' => array( 'CAP-ada', 'CAP-ada' ),
			'bare prefix'          => array( 'cap-', '' ),
			'empty'                => array( '', '' ),
		);
	}

	/**
	 * Prefixing a slug then stripping it returns the original.
	 *
	 * @dataProvider data_slugs_to_prefix
	 *
	 * @param string $nicename Input user_nicename.
	 */
	public function test_prefixing_then_stripping_a_slug_round_trips( string $nicename ): void {
		$this->assertSame(
			$nicename,
			Prefix::strip_slug_prefix( Prefix::prefix_slug( $nicename ) )
		);
	}

	/**
	 * Meta key prefixing is idempotent and case-insensitive.
	 *
	 * Meta keys are not sanitised, so a coauthors_guest_author_fields filter
	 * can supply `CAP-first_name`. Prefixing that again would read
	 * `cap-CAP-first_name`, which nothing writes.
	 *
	 * @dataProvider data_meta_keys
	 *
	 * @param string $key      Input key.
	 * @param string $expected Expected key.
	 */
	public function test_ensure_meta_key_prefix( string $key, string $expected ): void {
		$this->assertSame( $expected, Prefix::ensure_meta_key_prefix( $key ) );
	}

	/**
	 * Applying the meta key prefix twice changes nothing.
	 *
	 * @dataProvider data_meta_keys
	 *
	 * @param string $key Input key.
	 */
	public function test_ensure_meta_key_prefix_is_idempotent( string $key ): void {
		$once = Prefix::ensure_meta_key_prefix( $key );

		$this->assertSame( $once, Prefix::ensure_meta_key_prefix( $once ) );
	}

	/**
	 * Meta keys and their prefixed form.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function data_meta_keys(): array {
		return array(
			'unprefixed gains the prefix' => array( 'first_name', 'cap-first_name' ),
			'prefixed is left alone'      => array( 'cap-first_name', 'cap-first_name' ),
			'upper-case prefix is left alone' => array( 'CAP-first_name', 'CAP-first_name' ),
			'mixed-case prefix is left alone' => array( 'Cap-first_name', 'Cap-first_name' ),
			'prefix elsewhere gains one'  => array( 'linked_cap-account', 'cap-linked_cap-account' ),
			'merely starts with cap'      => array( 'capital', 'cap-capital' ),
			'empty gains the bare prefix' => array( '', 'cap-' ),
		);
	}

	/**
	 * The two rules genuinely differ, and the names say which is which.
	 *
	 * If someone ever "tidies" prefix_slug() into being idempotent, or drops
	 * the strtolower() from the meta key rule, this is what fails.
	 */
	public function test_the_two_rules_are_not_interchangeable(): void {
		// Slug prefixing is unconditional; meta key prefixing is not.
		$this->assertSame( 'cap-cap-ri', Prefix::prefix_slug( 'cap-ri' ) );
		$this->assertSame( 'cap-ri', Prefix::ensure_meta_key_prefix( 'cap-ri' ) );

		// Slug detection is case-sensitive; meta key detection is not.
		$this->assertFalse( Prefix::slug_has_prefix( 'CAP-ada' ) );
		$this->assertTrue( Prefix::meta_key_has_prefix( 'CAP-ada' ) );
	}
}
