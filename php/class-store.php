<?php
/**
 * Cache infrastructure for Co-Authors Plus.
 *
 * Centralises the object-cache group string and per-purpose cache key
 * templates so that reads, writes, and invalidation cannot drift apart.
 * The lack of a shared layer is the structural root cause of past
 * invalidation bugs (see issue #1313).
 *
 * @package CoAuthors
 * @since 4.2.0
 */

declare( strict_types=1 );

namespace CoAuthors\Cache;

/**
 * Object-cache helper.
 *
 * All methods are static. The class holds no instance state. The single
 * {@see self::GROUP} constant is the only place the cache group string
 * lives; the per-purpose key builders are the only place each key
 * template lives.
 */
class Store {

	/**
	 * Object-cache group for every value Co-Authors Plus caches.
	 *
	 * Site-local. Not registered as a global group: the keys are not
	 * intended to be shared across sites, and the object cache adds the
	 * per-blog salt for us.
	 */
	public const GROUP = 'co-authors-plus';

	/**
	 * Cache key for the coauthor term associated with a user.
	 *
	 * Keyed on the stable `user_id` (not `user_nicename`) so a nicename
	 * change cannot orphan the entry.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	public static function author_term_key( int $user_id ): string {
		return 'author-term-' . $user_id;
	}

	/**
	 * Cache key for the ordered coauthor terms attached to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function coauthors_post_key( int $post_id ): string {
		return 'coauthors_post_' . $post_id;
	}

	/**
	 * Cache key for the cached list of WP user accounts linked to any
	 * guest author.
	 *
	 * @return string
	 */
	public static function all_linked_accounts_key(): string {
		return 'all-linked-accounts';
	}

	/**
	 * Cache key for a guest author lookup by a field/value pair.
	 *
	 * Field/value normalisation matches the legacy
	 * {@see \CoAuthors_Guest_Authors::get_cache_key()} switch:
	 *  - `post_name` is re-keyed to `user_nicename` and the optional
	 *    `cap-` prefix is stripped from the value, since the guest
	 *    author CPT stores the bare nicename as the post slug.
	 *  - `login` is re-keyed to `user_login`.
	 *
	 * @param string $field Guest author field.
	 * @param mixed  $value Field value.
	 * @return string
	 */
	public static function guest_author_key( string $field, $value ): string {
		switch ( $field ) {
			case 'post_name':
				$field = 'user_nicename';
				if ( 0 === strpos( (string) $value, 'cap-' ) ) {
					$value = substr( (string) $value, 4 );
				}
				break;

			case 'login':
				$field = 'user_login';
				break;
		}

		return md5( 'guest-author-' . $field . '-' . $value );
	}

	/**
	 * Wraps `wp_cache_get`.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group. Defaults to {@see self::GROUP}.
	 * @return mixed Cached value, or `false` on miss.
	 */
	public static function get( string $key, string $group = self::GROUP ) {
		return wp_cache_get( $key, $group );
	}

	/**
	 * Wraps `wp_cache_set`.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to cache.
	 * @param string $group Cache group. Defaults to {@see self::GROUP}.
	 * @return bool True on success, false on failure.
	 */
	public static function set( string $key, $value, string $group = self::GROUP ): bool {
		return wp_cache_set( $key, $value, $group );
	}

	/**
	 * Wraps `wp_cache_delete`.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group. Defaults to {@see self::GROUP}.
	 * @return bool True on success, false on failure.
	 */
	public static function delete( string $key, string $group = self::GROUP ): bool {
		return wp_cache_delete( $key, $group );
	}
}
