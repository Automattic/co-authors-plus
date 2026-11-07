<?php
/**
 * Finds guest authors who have no usable author term.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Services;

use CoAuthors\Prefix;
use CoAuthors_Plus;
use WP_Query;
use WP_Term;
use WP_User;

/**
 * Finds guest authors who have no usable author term.
 *
 * A term whose slug equals a co-author's cap- nicename only counts as theirs
 * when its name is their login, so a login stored with the prefix can leave a
 * guest author with no term, or with a new doubled term while their older posts
 * stay on the first one. See CoAuthors_Plus::get_author_term().
 *
 * This only reads. A login starting with cap- can be legitimate, so nothing here
 * can decide what a guest author's login should have been. It must never call
 * CoAuthors_Plus::update_author_term(), which creates the doubled term.
 */
class Guest_Author_Term_Report_Service {

	/**
	 * The guest author resolves to no term.
	 */
	public const STATUS_NO_TERM = 'no-term';

	/**
	 * The guest author resolves to a doubled term, with posts left on the other.
	 */
	public const STATUS_SPLIT = 'split';

	/**
	 * The guest author resolves to no term, and the matching one is someone else's.
	 */
	public const STATUS_TERM_OWNED_BY_OTHER = 'term-owned-by-other';

	/**
	 * Plugin instance.
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
	 * Check one page of guest authors.
	 *
	 * The caller walks the pages, so that it can free memory between them.
	 *
	 * @param int $page     Page number, starting at 1.
	 * @param int $per_page Guest authors per page.
	 * @return array{checked: int, rows: array<int, array<string, int|string>>} How many guest authors were checked, and the rows to report.
	 */
	public function get_page( int $page, int $per_page ): array {
		$query = new WP_Query(
			array(
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'order'                  => 'ASC',
				'orderby'                => 'ID',
				'paged'                  => $page,
				// Guest authors are inserted as drafts, so the default 'publish' would hide them.
				'post_status'            => 'any',
				'post_type'              => $this->coauthors_plus->guest_authors->post_type,
				'posts_per_page'         => $per_page,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);

		$rows = array();

		foreach ( $query->posts as $guest_author_id ) {
			$row = $this->inspect( (int) $guest_author_id );

			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		return array(
			'checked' => count( $query->posts ),
			'rows'    => $rows,
		);
	}

	/**
	 * Check one guest author.
	 *
	 * @param int $guest_author_id Guest author post ID.
	 * @return array<string, int|string>|null The row to report, or null when there's nothing to report.
	 */
	public function inspect( int $guest_author_id ): ?array {
		// The ID lookup only accepts a real guest author post, so cap- meta on any
		// other post type is never read.
		$guest_author = $this->coauthors_plus->guest_authors->get_guest_author_by( 'ID', $guest_author_id );

		if ( ! $guest_author ) {
			return null;
		}

		$resolved = $this->coauthors_plus->get_author_term( $guest_author );
		$matching = $this->get_matching_term( $guest_author, $resolved );
		$owner    = $matching ? $this->get_term_owner( $matching, $guest_author ) : '';
		$objects  = $matching ? $this->count_objects( $matching, $guest_author_id ) : 0;
		$status   = $this->get_status( $resolved, $matching, $owner, $objects );

		if ( null === $status ) {
			return null;
		}

		// Only a split has a doubled term. The doubled slug is tried first, so a
		// guest author with no term can't have one.
		$doubled = self::STATUS_SPLIT === $status ? $resolved : null;

		return array(
			'ID'                    => $guest_author_id,
			'user_login'            => $guest_author->user_login,
			'display_name'          => $guest_author->display_name,
			'status'                => $status,
			'linked_account'        => $guest_author->linked_account ?? '',
			'resolved_term'         => $resolved ? $resolved->slug : '',
			'matching_term'         => $matching ? $matching->slug : '',
			'matching_term_name'    => $matching ? $matching->name : '',
			'matching_term_owner'   => $owner,
			'matching_term_count'   => $matching ? (int) $matching->count : 0,
			'matching_term_objects' => $objects,
			'doubled_term'          => $doubled ? $doubled->slug : '',
			'doubled_term_count'    => $doubled ? (int) $doubled->count : 0,
			'doubled_term_objects'  => $doubled ? $this->count_objects( $doubled, $guest_author_id ) : 0,
		);
	}

	/**
	 * Get the term whose slug equals a guest author's nicename, if they don't own it.
	 *
	 * Only a cap- nicename can read as another co-author's prefixed term. A plain
	 * term that shares a clean nicename is a different matter, and isn't reported.
	 *
	 * @param object             $guest_author Guest author.
	 * @param WP_Term|false|null $resolved     The term the guest author resolves to.
	 * @return WP_Term|null
	 */
	private function get_matching_term( object $guest_author, $resolved ): ?WP_Term {
		if ( ! Prefix::slug_has_prefix( $guest_author->user_nicename ) ) {
			return null;
		}

		$term = get_term_by( 'slug', $guest_author->user_nicename, $this->coauthors_plus->coauthor_taxonomy );

		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		return $resolved && $resolved->term_id === $term->term_id ? null : $term;
	}

	/**
	 * Find another co-author who owns a term.
	 *
	 * CoAuthors_Plus::get_coauthor_by_term() can't answer this, because it returns
	 * a guest author found by the whole slug without asking whether they own it.
	 * So each co-author who could own the term is asked get_author_term() instead.
	 *
	 * @param WP_Term $term         The term.
	 * @param object  $guest_author The guest author being checked.
	 * @return string The owner, such as 'user:jane-doe', or an empty string.
	 */
	private function get_term_owner( WP_Term $term, object $guest_author ): string {
		$nicename   = Prefix::strip_slug_prefix( $term->slug );
		$candidates = array(
			get_user_by( 'slug', $nicename ),
			get_user_by( 'slug', $term->slug ),
			$this->coauthors_plus->guest_authors->get_guest_author_by( 'user_nicename', $nicename ),
		);

		foreach ( $candidates as $candidate ) {
			if ( ! $candidate ) {
				continue;
			}

			$is_user = $candidate instanceof WP_User;

			// The nicename lookup finds the guest author being checked, because
			// their post_name is the term's slug.
			if ( ! $is_user && (int) $candidate->ID === (int) $guest_author->ID ) {
				continue;
			}

			$own_term = $this->coauthors_plus->get_author_term( $candidate );

			if ( $own_term && $own_term->term_id === $term->term_id ) {
				return ( $is_user ? 'user:' : 'guest-author:' ) . $candidate->user_login;
			}
		}

		return '';
	}

	/**
	 * Decide what to report about a guest author.
	 *
	 * @param WP_Term|false|null $resolved The term the guest author resolves to.
	 * @param WP_Term|null       $matching The matching term they don't own.
	 * @param string             $owner    Another co-author who owns the matching term.
	 * @param int                $objects  Objects on the matching term, leaving out the guest author's profile.
	 * @return string|null The status, or null when there's nothing to report.
	 */
	private function get_status( $resolved, ?WP_Term $matching, string $owner, int $objects ): ?string {
		if ( ! $resolved ) {
			return '' === $owner ? self::STATUS_NO_TERM : self::STATUS_TERM_OWNED_BY_OTHER;
		}

		// A guest author with a term of their own, next to a cap- slug that's
		// someone else's, is what the ownership check exists for. "Cap Ri" beside
		// "Ri" looks like this, and so does an empty term left behind.
		if ( ! $matching || '' !== $owner || 0 === $objects ) {
			return null;
		}

		return self::STATUS_SPLIT;
	}

	/**
	 * Count what's attached to a term, leaving out the guest author's own profile.
	 *
	 * A term's count only covers published posts, and the profile post is attached
	 * to the term too, so neither the count nor a plain list of objects says
	 * whether any content is sitting on it.
	 *
	 * @param WP_Term $term            The term.
	 * @param int     $guest_author_id Guest author post ID.
	 * @return int
	 */
	private function count_objects( WP_Term $term, int $guest_author_id ): int {
		$objects = get_objects_in_term( $term->term_id, $this->coauthors_plus->coauthor_taxonomy );

		if ( is_wp_error( $objects ) ) {
			return 0;
		}

		return count( array_diff( array_map( 'intval', $objects ), array( $guest_author_id ) ) );
	}
}
