<?php
/**
 * Cache key infrastructure for Co-Authors Plus.
 *
 * Centralises the object-cache group string and per-purpose cache key
 * templates so reads, writes, and invalidation cannot drift apart. The
 * key basis for the author-term lookup is `user_nicename` (not
 * `user_id`) because term identity is the `cap-{nicename}` slug and
 * guest-author CPT post IDs collide with WP user IDs.
 *
 * @package CoAuthorsPlus
 * @since 4.2.0
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Cache;

/**
 * Object-cache key helper.
 *
 * Constants and pure functions only. No instance state, no wrappers
 * around `wp_cache_*` (the object cache drop-in already provides the
 * swappable layer).
 */
final class Keys {

	/**
	 * Object-cache group for every value Co-Authors Plus caches.
	 */
	public const GROUP = 'co-authors-plus';

	/**
	 * Private constructor: this class holds no instance state and is
	 * not designed to be subclassed.
	 */
	private function __construct() {}

	/**
	 * Cache key for the coauthor term associated with a coauthor.
	 *
	 * Keyed on `user_nicename` because the term slug is
	 * `cap-{user_nicename}`. Keying on the user_id (a WP user ID for
	 * `WP_User` and a CPT post ID for a guest author) collides:
	 * `wp_users` and `wp_posts` use independent auto-increment
	 * sequences, so equal integers occur naturally.
	 *
	 * @param string $user_nicename The coauthor nicename.
	 * @return string
	 */
	public static function author_term_key( string $user_nicename ): string {
		return 'author-term-' . $user_nicename;
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
	 * `CoAuthors_Guest_Authors::get_cache_key()` switch:
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
}
