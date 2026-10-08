<?php
/**
 * Reads and writes which co-authors are assigned to a post.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Services;

use CoAuthors_Plus;

/**
 * Reads and writes a post's co-author assignments.
 *
 * Every write path in the plugin — the classic metabox, both block editor
 * routes, bulk edit, user-deletion reassignment and the CLI — already funnels
 * into CoAuthors_Plus::add_coauthors(), so this wraps that rather than
 * reimplementing any of it.
 *
 * The reads deliberately do not use get_coauthors(), which falls back to the
 * post_author user when a post carries no author terms. That fallback is right
 * for display but wrong when rebuilding an assignment: it splices a user into
 * the list who was never assigned. This reads the taxonomy directly instead.
 */
class Coauthor_Assignment_Service {

	/**
	 * The plugin instance the assignments are read from and written through.
	 *
	 * @var CoAuthors_Plus
	 */
	private $coauthors_plus;

	/**
	 * Constructor.
	 *
	 * @param CoAuthors_Plus $coauthors_plus Plugin instance.
	 */
	public function __construct( CoAuthors_Plus $coauthors_plus ) {
		$this->coauthors_plus = $coauthors_plus;
	}

	/**
	 * Get a post's assigned co-authors, in byline order.
	 *
	 * Author term slugs are `cap-{user_nicename}`, and add_coauthors() matches
	 * on user_nicename by default, so nicenames are what both sides of this
	 * class speak. Mixing them with user_login values is the mistake this
	 * naming exists to prevent: the two differ whenever a login contains
	 * characters sanitize_title() rewrites.
	 *
	 * @param int $post_id Post ID.
	 * @return string[] user_nicename values, in byline order.
	 */
	public function nicenames_for_post( int $post_id ): array {
		$terms = wp_get_object_terms(
			$post_id,
			$this->coauthors_plus->coauthor_taxonomy,
			array( 'orderby' => 'term_order' )
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$nicenames = array();

		foreach ( $terms as $term ) {
			// Only the prefix the plugin adds comes off: a nicename may itself
			// begin with 'cap-', giving a doubly prefixed slug. Matches the
			// str_starts_with idiom used for the other prefix checks, and is
			// deliberately case sensitive, as the stored slug is lower case.
			$nicenames[] = str_starts_with( $term->slug, 'cap-' )
				? substr( $term->slug, strlen( 'cap-' ) )
				: $term->slug;
		}

		return $nicenames;
	}

	/**
	 * Replace a post's co-authors with the given ordered list.
	 *
	 * @param int      $post_id   Post ID.
	 * @param string[] $nicenames user_nicename values, in the desired order.
	 * @return bool Whether the assignment succeeded.
	 */
	public function assign( int $post_id, array $nicenames ): bool {
		return $this->coauthors_plus->add_coauthors( $post_id, $nicenames, false );
	}

	/**
	 * Add a co-author to a post at a given byline position.
	 *
	 * Positions beyond the end of the current byline append. Re-adding a
	 * co-author already on the post is a no-op, which is what makes an import
	 * safe to run twice.
	 *
	 * Whether the byline changed is decided here rather than taken from
	 * add_coauthors(), whose return value does not mean what it appears to: it
	 * returns false when replacing a byline that resolves to no WordPress user,
	 * which is every byline made up only of guest authors without linked
	 * accounts, having written the terms perfectly well. A false is therefore
	 * ambiguous, so it is settled by re-reading the terms: if the co-author is
	 * now on the post the write landed, and if not it genuinely failed.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $nicename user_nicename to add.
	 * @param int    $position Zero-based byline position.
	 * @return bool Whether the byline was changed.
	 */
	public function add_at_position( int $post_id, string $nicename, int $position ): bool {
		$nicenames = $this->nicenames_for_post( $post_id );

		if ( in_array( $nicename, $nicenames, true ) ) {
			return false;
		}

		array_splice( $nicenames, min( $position, count( $nicenames ) ), 0, array( $nicename ) );

		if ( $this->assign( $post_id, $nicenames ) ) {
			return true;
		}

		return in_array( $nicename, $this->nicenames_for_post( $post_id ), true );
	}
}
