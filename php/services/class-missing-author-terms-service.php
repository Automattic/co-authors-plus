<?php
/**
 * Finds posts that have no author term.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Services;

use CoAuthors_Plus;
use Exception;

/**
 * Detects posts that are missing their author terms.
 *
 * These posts turn up whenever Co-Authors Plus is inactive for a while: core keeps
 * saving them with a post_author, but nothing assigns an author term. The term
 * counts go wrong and get_coauthors() falls back to the post_author, so the site
 * reports authors it does not really have.
 *
 * The query behind this is a NOT IN over term_relationships, spanning four tables.
 * It used to be private to Create_Author_Terms_For_Posts_Command, which was its
 * only caller. It lives here now so the Site Health test can read the same count
 * without a second copy of the SQL, and so an eventual `check` command has one
 * place to ask.
 *
 * The query deliberately skips posts carrying the backfill marker. Those are posts
 * the backfill has already given up on, usually because their post_author no longer
 * exists, so counting them would report a problem that running the command cannot
 * fix. Site Health says "good" when the only term-less posts are the unfixable ones.
 */
class Missing_Author_Terms_Service {

	/**
	 * Postmeta marking a post the backfill could not handle.
	 *
	 * A post carrying this key is left out of every count and fetch here, because
	 * the backfill can not repair it either. Managed by
	 * create-author-terms-for-posts and delete-postmeta-that-skip-author-term-backfill.
	 *
	 * @var string
	 */
	public const SKIP_POST_FOR_BACKFILL_META_KEY = '_cap_skip_backfill';

	/**
	 * Transient holding the last missing-terms count, keyed by post type.
	 *
	 * The detection query is a NOT IN spanning four tables and does not get
	 * cheaper on a large site, so the Site Health test must not run it on every
	 * admin page load. Cleared by the two commands that change the answer.
	 *
	 * @var string
	 */
	public const COUNT_TRANSIENT = 'coauthors_missing_author_terms_count';

	/**
	 * How long the cached count is kept, in seconds.
	 *
	 * An hour is long enough that the count is not run on every admin page load
	 * and short enough that a site left un-repaired does not need the cache
	 * cleared on its behalf.
	 *
	 * @var int
	 */
	public const COUNT_CACHE_TTL = 3600;

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
	 * Count posts that are missing an author term.
	 *
	 * @param string[] $post_types The post types to search.
	 * @param string[] $post_statuses The post statuses to search.
	 * @param int[]    $specific_post_ids Limit to these post IDs.
	 * @param int|null $above_post_id Only posts with an ID above this.
	 * @param int|null $below_post_id Only posts with an ID below this.
	 *
	 * @return int
	 * @throws Exception If above-post-id is greater than or equal to below-post-id.
	 */
	public function count( array $post_types = array( 'post' ), array $post_statuses = array( 'publish' ), array $specific_post_ids = array(), ?int $above_post_id = null, ?int $below_post_id = null ): int {
		global $wpdb;

		[
			$sql,
			$args,
		] = array_values( $this->get_sql_for_posts_with_missing_terms( $post_types, $post_statuses, $specific_post_ids, $above_post_id, $below_post_id ) );

		// Replace the first SELECT with SELECT COUNT(*).
		$sql = preg_replace(
			'/^(SELECT(?s)(.*?)FROM)/',
			'SELECT COUNT(*) FROM',
			$sql,
			1
		);

		// phpcs:disable -- Query is properly prepared
		$count = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable

		// A failed query returns null, which intval() would flatten to 0 and
		// report as a site with nothing to fix. Throwing instead means a caller
		// that cannot count does not claim the site is clean.
		if ( null === $count ) {
			throw new Exception( 'Could not count the posts with missing author terms.' );
		}

		return (int) $count;
	}

	/**
	 * Count posts missing an author term, broken down by post type.
	 *
	 * One query per post type rather than a GROUP BY, so the detection SQL stays in
	 * a single place.
	 *
	 * @param string[] $post_types The post types to count.
	 * @param string[] $post_statuses The post statuses to search.
	 *
	 * @return array<string, int> Counts keyed by post type, including zeroes.
	 * @throws Exception If the post ID range is invalid.
	 */
	public function counts_by_post_type( array $post_types, array $post_statuses = array( 'publish' ) ): array {
		$counts = array();

		foreach ( $post_types as $post_type ) {
			$counts[ $post_type ] = $this->count( array( $post_type ), $post_statuses );
		}

		return $counts;
	}

	/**
	 * Get the per-post-type counts, from the cache when it is warm.
	 *
	 * Fills the cache on a miss, and carries the timestamp of the moment it was
	 * taken so callers can say how stale the figure is.
	 *
	 * @param string[] $post_types The post types to count.
	 * @param int|null $expires_in How long to cache the result for, in seconds.
	 *
	 * @return array{counts: array<string, int>, total: int, timestamp: int}
	 * @throws Exception If the post ID range is invalid.
	 */
	public function get_cached_counts( array $post_types, ?int $expires_in = null ): array {
		$cached = get_transient( self::COUNT_TRANSIENT );

		// A cached entry is only reusable for exactly the post types it was taken
		// over. A type registered since the cache was filled would otherwise be
		// reported clean for the rest of the hour without ever being counted, and
		// an entry covering more types than were asked for would leak their
		// counts into the total and the repair command.
		if ( is_array( $cached ) && isset( $cached['counts'], $cached['timestamp'] ) && array() === array_diff( $post_types, array_keys( $cached['counts'] ) ) && array() === array_diff( array_keys( $cached['counts'] ), $post_types ) ) {
			return $cached;
		}

		$counts = $this->counts_by_post_type( $post_types );

		$result = array(
			'counts'    => $counts,
			'total'     => array_sum( $counts ),
			'timestamp' => time(),
		);

		set_transient( self::COUNT_TRANSIENT, $result, $expires_in ?? self::COUNT_CACHE_TTL );

		return $result;
	}

	/**
	 * Drop the cached count.
	 *
	 * Called by the backfill once it finishes and by the command that clears the
	 * skip markers, because both change the number the cache holds.
	 *
	 * @return void
	 */
	public function clear_count_cache(): void {
		delete_transient( self::COUNT_TRANSIENT );
	}

	/**
	 * Fetch posts that are missing an author term.
	 *
	 * @param string[] $post_types The post types to search.
	 * @param string[] $post_statuses The post statuses to search.
	 * @param bool     $batched Whether to limit the results to one batch.
	 * @param int      $records_per_batch How many records to return per batch.
	 * @param int[]    $specific_post_ids Limit to these post IDs.
	 * @param int|null $above_post_id Only posts with an ID above this.
	 * @param int|null $below_post_id Only posts with an ID below this.
	 *
	 * @return array
	 * @throws Exception If above-post-id is greater than or equal to below-post-id.
	 */
	public function posts( array $post_types = array( 'post' ), array $post_statuses = array( 'publish' ), bool $batched = false, int $records_per_batch = 250, array $specific_post_ids = array(), ?int $above_post_id = null, ?int $below_post_id = null ): array {
		global $wpdb;

		[
			$sql,
			$args,
		] = array_values( $this->get_sql_for_posts_with_missing_terms( $post_types, $post_statuses, $specific_post_ids, $above_post_id, $below_post_id ) );

		if ( $batched ) {
			$sql .= " LIMIT $records_per_batch";
		}

		// phpcs:disable -- Query is properly prepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable
	}

	/**
	 * Build the SQL for posts that are missing an author term.
	 *
	 * The author taxonomy comes from the plugin instance rather than a parameter, so
	 * callers cannot accidentally ask about the wrong taxonomy.
	 *
	 * @param string[] $post_types The post types to search.
	 * @param string[] $post_statuses The post statuses to search.
	 * @param int[]    $specific_post_ids Limit to these post IDs.
	 * @param int|null $above_post_id Only posts with an ID above this.
	 * @param int|null $below_post_id Only posts with an ID below this.
	 *
	 * @return array{sql: string, args: array}
	 * @throws Exception If above-post-id is greater than or equal to below-post-id.
	 */
	private function get_sql_for_posts_with_missing_terms( array $post_types = array( 'post' ), array $post_statuses = array( 'publish' ), array $specific_post_ids = array(), ?int $above_post_id = null, ?int $below_post_id = null ): array {
		global $wpdb;

		$sql_and_args = array(
			'sql'  => '',
			'args' => array( $this->coauthors_plus->coauthor_taxonomy, self::SKIP_POST_FOR_BACKFILL_META_KEY ),
		);

		$post_status_placeholder = implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) );
		$sql_and_args['args']    = array_merge( $post_statuses, $sql_and_args['args'] );
		$post_types_placeholder  = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$sql_and_args['args']    = array_merge( $post_types, $sql_and_args['args'] );

		$from = $wpdb->posts;

		$specific_id_constraint = '';

		if ( ! empty( $specific_post_ids ) ) {
			$specific_post_ids_placeholder = implode( ',', array_fill( 0, count( $specific_post_ids ), '%d' ) );
			$specific_id_constraint        = "AND ID IN ( $specific_post_ids_placeholder )";
			$sql_and_args['args']          = array_merge( $sql_and_args['args'], $specific_post_ids );
		} elseif ( null !== $above_post_id || null !== $below_post_id ) {
			if ( null !== $above_post_id && null !== $below_post_id && ( $below_post_id <= $above_post_id ) ) {
				throw new Exception( 'The $above_post_id param must be less than the $below_post_id param.' );
			}

			$ids_between_constraint = array();

			if ( null !== $above_post_id ) {
				array_unshift( $ids_between_constraint, 'ID > %d' );
				array_unshift( $sql_and_args['args'], $above_post_id );
			}

			if ( null !== $below_post_id ) {
				array_unshift( $ids_between_constraint, 'ID < %d' );
				array_unshift( $sql_and_args['args'], $below_post_id );
			}

			$from = "( SELECT * FROM $wpdb->posts WHERE " . implode( ' AND ', $ids_between_constraint ) . ' ) as sub';
		}//end if

		$sql_and_args['sql'] = "SELECT
				ID as post_id,
				post_author
			FROM $from
			WHERE post_type IN ( $post_types_placeholder )
			  AND post_status IN ( $post_status_placeholder )
			  AND ID NOT IN (
			  	SELECT
			  	    tr.object_id
			  	FROM $wpdb->term_relationships tr
			  	    LEFT JOIN $wpdb->term_taxonomy tt
			  	        ON tr.term_taxonomy_id = tt.term_taxonomy_id
			  	WHERE tt.taxonomy = %s
			  	GROUP BY tr.object_id
			  	)
			  AND ID NOT IN (
			      SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s
			  )
			  $specific_id_constraint
			ORDER BY ID";

		return $sql_and_args;
	}
}
