<?php
/**
 * The 'cap-' prefix, and the two different jobs it does.
 *
 * @package Automattic\CoAuthorsPlus
 */

namespace CoAuthors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds, strips and detects the 'cap-' prefix.
 *
 * The plugin puts the same four characters in front of two unrelated kinds of
 * string, and the rules differ because the strings do.
 *
 * **Slugs** — author term slugs and guest author `post_name` values. Both pass
 * through `sanitize_title()`, which ends in `strtolower()`, so a slug can never
 * arrive upper-cased and matching is case-sensitive. Prefixing is
 * *unconditional*: see prefix_slug() for why that matters.
 *
 * **Postmeta keys** — `cap-first_name` and friends. WordPress does not sanitise
 * meta keys, so a `coauthors_guest_author_fields` filter can supply
 * `CAP-first_name`; matching is case-insensitive and prefixing is idempotent,
 * because prefixing such a key again would read a key nothing ever writes.
 *
 * The method names carry that difference deliberately: prefix_slug() always
 * prefixes, ensure_meta_key_prefix() only prefixes what is not prefixed
 * already. Before this class those rules had to be inferred from a strpos(),
 * a stripos(), a preg_match() and a str_replace() scattered across three
 * files, and two of them were wrong.
 *
 * @since 4.2.0
 */
final class Prefix {

	/**
	 * The prefix itself.
	 */
	public const VALUE = 'cap-';

	/**
	 * Prefix a user_nicename to get its author term slug or post_name.
	 *
	 * Deliberately unconditional, rather than "prefix unless already
	 * prefixed". A guest author named "Cap Ri" has the user_nicename `cap-ri`
	 * and the term slug `cap-cap-ri`; skipping the prefix for a nicename that
	 * happens to start with `cap-` would point it at a different author's
	 * term. CoAuthors_Plus::search_authors() carries the matching special case
	 * for reading those doubled slugs back.
	 *
	 * @param string $user_nicename Co-author user_nicename or user_login.
	 * @return string
	 */
	public static function prefix_slug( string $user_nicename ): string {
		return self::VALUE . $user_nicename;
	}

	/**
	 * Whether a slug starts with the prefix.
	 *
	 * Case-sensitive: slugs are lower-cased by sanitize_title().
	 *
	 * @param string $slug Term slug or guest author post_name.
	 * @return bool
	 */
	public static function slug_has_prefix( string $slug ): bool {
		return str_starts_with( $slug, self::VALUE );
	}

	/**
	 * Remove one leading prefix from a slug, if it has one.
	 *
	 * Only the leading occurrence, and only one: a guest author called
	 * "Recap Caption" has the slug `recap-caption`, which must come back
	 * untouched, and `cap-cap-ri` must yield `cap-ri` rather than `ri`.
	 *
	 * @param string $slug Term slug or guest author post_name.
	 * @return string
	 */
	public static function strip_slug_prefix( string $slug ): string {
		return self::slug_has_prefix( $slug )
			? substr( $slug, strlen( self::VALUE ) )
			: $slug;
	}

	/**
	 * Prefix a postmeta key, unless it already carries the prefix.
	 *
	 * @param string $key Guest author field name.
	 * @return string
	 */
	public static function ensure_meta_key_prefix( string $key ): string {
		return self::meta_key_has_prefix( $key ) ? $key : self::VALUE . $key;
	}

	/**
	 * Whether a postmeta key starts with the prefix, in any case.
	 *
	 * Case-insensitive: meta keys are not sanitised, so a filter may supply
	 * `CAP-first_name`.
	 *
	 * @param string $key Postmeta key.
	 * @return bool
	 */
	public static function meta_key_has_prefix( string $key ): bool {
		return str_starts_with( strtolower( $key ), self::VALUE );
	}
}
