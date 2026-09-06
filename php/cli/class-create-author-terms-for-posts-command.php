<?php
/**
 * The create-author-terms-for-posts WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use CoAuthors_Plus;
use Exception;
use WP_CLI;
use WP_Term;

/**
 * Backfills author terms for the posts that are missing them.
 *
 * Moved here from CoAuthorsPlus_Command unchanged, bringing with it the five
 * private helpers it was the only caller of: the three that build and run the
 * missing-terms query, the one that marks a post as unbackfillable, and the one
 * that formats progress. They were private to a class shared by every
 * subcommand and are private to this one command now, which is where they
 * always belonged.
 *
 * The raw SQL those helpers hold is the strongest candidate in the plugin for a
 * repository of its own: it spans four tables and is the hardest thing here to
 * test. That is a change with its own shape and its own cover, though, so it is
 * not this one. Behaviour is pinned by
 * features/create-author-terms-for-posts.feature.
 */
class Create_Author_Terms_For_Posts_Command {

	/**
	 * Postmeta marking a post the backfill could not handle.
	 *
	 * Read by delete-postmeta-that-skip-author-term-backfill, which exists to
	 * clear what this command writes.
	 *
	 * @var string
	 */
	const SKIP_POST_FOR_BACKFILL_META_KEY = '_cap_skip_backfill';

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
	 * Create author terms for posts that are missing them.
	 *
	 * Unlike create-terms-for-posts, this finds only the posts that actually
	 * need a term, which is far quicker on a site of any size. Posts whose
	 * author no longer exists are marked so later runs skip them.
	 *
	 * ## OPTIONS
	 *
	 * [--post-types=<post-types>]
	 * : Comma-separated post types to cover. Defaults to post.
	 *
	 * [--post-statuses=<post-statuses>]
	 * : Comma-separated post statuses to cover. Defaults to publish.
	 *
	 * [--records-per-batch=<number>]
	 * : How many posts to handle per batch. Defaults to 250.
	 *
	 * [--unbatched]
	 * : Handle every matching post in one pass.
	 *
	 * [--specific-post-ids=<ids>]
	 * : Comma-separated post IDs to limit the run to.
	 *
	 * [--above-post-id=<id>]
	 * : Only consider posts with an ID above this.
	 *
	 * [--below-post-id=<id>]
	 * : Only consider posts with an ID below this.
	 *
	 * ## EXAMPLES
	 *
	 *     # Backfill published posts.
	 *     $ wp co-authors-plus create-author-terms-for-posts
	 *
	 *     # Backfill drafts and pages in larger batches.
	 *     $ wp co-authors-plus create-author-terms-for-posts --post-types=page --post-statuses=draft --records-per-batch=500
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 * @throws Exception If above-post-id is greater than or equal to below-post-id.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$post_types        = isset( $assoc_args['post-types'] ) ? explode( ',', $assoc_args['post-types'] ) : array( 'post' );
		$post_statuses     = isset( $assoc_args['post-statuses'] ) ? explode( ',', $assoc_args['post-statuses'] ) : array( 'publish' );
		$batched           = ! isset( $assoc_args['unbatched'] );
		$records_per_batch = $assoc_args['records-per-batch'] ?? 250;
		$specific_post_ids = isset( $assoc_args['specific-post-ids'] ) ? explode( ',', $assoc_args['specific-post-ids'] ) : array();
		$above_post_id     = $assoc_args['above-post-id'] ?? null;
		$below_post_id     = $assoc_args['below-post-id'] ?? null;

		// Validated here rather than in the SQL builder, which was only reached when
		// no --specific-post-ids were given, and threw an uncaught Exception when it
		// was — so the operator saw a stack trace and a generic critical-error line
		// rather than being told which parameter was wrong.
		if ( null !== $above_post_id && null !== $below_post_id && (int) $below_post_id <= (int) $above_post_id ) {
			WP_CLI::error( '--above-post-id must be less than --below-post-id.' );
		}

		if ( ! empty( $specific_post_ids ) && ( null !== $above_post_id || null !== $below_post_id ) ) {
			WP_CLI::warning( '--above-post-id and --below-post-id are ignored when --specific-post-ids is given.' );
		}

		global $wpdb;

		$coauthors_plus = $this->coauthors_plus;

		$count_of_posts_with_missing_author_terms = $this->get_count_of_posts_with_missing_terms(
			$coauthors_plus->coauthor_taxonomy,
			$post_types,
			$post_statuses,
			$specific_post_ids,
			$above_post_id,
			$below_post_id
		);

		WP_CLI::log( sprintf( 'Found %d posts with missing author terms.', $count_of_posts_with_missing_author_terms ) );

		$authors      = array();
		$author_terms = array();
		$count        = 0;
		$affected     = 0;
		$skipped      = 0;
		$page         = 1;

		$posts_with_missing_author_terms = $this->get_posts_with_missing_terms(
			$coauthors_plus->coauthor_taxonomy,
			$post_types,
			$post_statuses,
			$batched,
			$records_per_batch,
			$specific_post_ids,
			$above_post_id,
			$below_post_id
		);

		// One recount per term when counting resumes, rather than one per write.
		wp_defer_term_counting( true );

		do {
			foreach ( $posts_with_missing_author_terms as $record ) {
				$record->post_author = intval( $record->post_author );
				++$count;
				$complete_percentage = $this->get_formatted_complete_percentage( $count, $count_of_posts_with_missing_author_terms );
				WP_CLI::log( sprintf( 'Processing post %d (%d/%d or %s)', $record->post_id, $count, $count_of_posts_with_missing_author_terms, $complete_percentage ) );

				$author = null;
				if ( isset( $authors[ $record->post_author ] ) ) {
					$author = $authors[ $record->post_author ];
				} else {
					$author = get_user_by( 'id', $record->post_author );

					if ( false === $author ) {
						// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users -- This is just trying to convey where the root problem should be resolved.
						WP_CLI::warning( sprintf( 'Post Author ID %d does not exist in %s table, inserting skip postmeta (`%s`).', $record->post_author, $wpdb->users, self::SKIP_POST_FOR_BACKFILL_META_KEY ) );
						$this->skip_backfill_for_post( $record->post_id, 'nonexistent_post_author_id' );
						++$skipped;
						continue;
					}

					$authors[ $record->post_author ] = $author;
				}

				// ?? rather than ! empty(), so a failed term creation is memoised too and an
				// unresolvable author is attempted once per run rather than once per post.
				$author_term                          = $author_terms[ $record->post_author ] ?? $coauthors_plus->update_author_term( $author );
				$author_terms[ $record->post_author ] = $author_term;

				// update_author_term() returns a WP_Error when the term cannot be created.
				// Dereferencing that used to write a relationship row with no
				// term_taxonomy_id behind it.
				if ( ! $author_term instanceof WP_Term ) {
					WP_CLI::warning( sprintf( 'No author term for user ID %d, marking post %d as skipped.', $record->post_author, $record->post_id ) );
					$this->skip_backfill_for_post( $record->post_id, 'author_term_not_created' );
					++$skipped;
					continue;
				}

				// wp_set_object_terms() rather than a raw term_relationships insert: core
				// then does the dedupe, writes term_order, runs the count callback, and
				// fires set_object_terms — which CAP hooks to clear its own
				// coauthors_post_<id> cache. The raw insert skipped all of that, so on a
				// persistent object cache a backfilled post kept reporting no co-authors
				// until it was next saved.
				// Not appending, deliberately: the query above selects only posts with no
				// author terms at all, so set and append coincide, and set is the
				// operation this backfill actually means.
				$set_author_term = wp_set_object_terms( $record->post_id, array( $author_term->slug ), $coauthors_plus->coauthor_taxonomy, false );

				if ( is_wp_error( $set_author_term ) ) {
					WP_CLI::warning( sprintf( 'Failed to set the author term for post %d and author %d, marking it skipped.', $record->post_id, $record->post_author ) );
					$this->skip_backfill_for_post( $record->post_id, 'author_term_not_set' );
					++$skipped;
				} else {
					WP_CLI::success( sprintf( 'Inserted term relationship for post %d and author %d (%s).', $record->post_id, $record->post_author, $author->user_nicename ) );
					++$affected;
				}

				if ( $count >= $count_of_posts_with_missing_author_terms ) {
					break;
				}

				if ( $count && 0 === $count % 500 ) {
					sleep( 1 ); 
					// Sleep for a second every 500 posts to avoid overloading the database.
				}
			}//end foreach

			$posts_with_missing_author_terms = array();

			if ( $batched && $count < $count_of_posts_with_missing_author_terms ) {
				++$page;
				WP_CLI::log( sprintf( 'Processing page %d.', $page ) );
				$posts_with_missing_author_terms = $this->get_posts_with_missing_terms(
					$coauthors_plus->coauthor_taxonomy,
					$post_types,
					$post_statuses,
					$batched,
					$records_per_batch,
					$specific_post_ids,
					$above_post_id,
					$below_post_id
				);
			}
		} while ( ! empty( $posts_with_missing_author_terms ) );

		wp_defer_term_counting( false );

		WP_CLI::log( sprintf( '%d records affected', $affected ) );

		if ( $skipped > 0 ) {
			WP_CLI::warning(
				sprintf(
					/* translators: 1: Count of posts. 2: Post meta key. */
					_n(
						'%1$s post was skipped and marked with `%2$s`.',
						'%1$s posts were skipped and marked with `%2$s`.',
						$skipped,
						'co-authors-plus'
					),
					number_format_i18n( $skipped ),
					self::SKIP_POST_FOR_BACKFILL_META_KEY
				)
			);
		}


		WP_CLI::success( 'Done!' );
	}

	/**
	 * Obtains the raw SQL for posts that are missing a specific term.
	 *
	 * @param string   $author_taxonomy The author taxonomy to search for.
	 * @param string[] $post_types The post types to search for.
	 * @param string[] $post_statuses The post statuses to search for.
	 * @param int[]    $specific_post_ids The specific post IDs to search for.
	 * @param int|null $above_post_id The post ID to start from.
	 * @param int|null $below_post_id The post ID to end at.
	 *
	 * @return array
	 * @throws Exception If the $above_post_id is greater than or equal to the $below_post_id.
	 */
	private function get_sql_for_posts_with_missing_terms( $author_taxonomy, $post_types = array( 'post' ), $post_statuses = array( 'publish' ), $specific_post_ids = array(), $above_post_id = null, $below_post_id = null ) {
		global $wpdb;

		$sql_and_args = array(
			'sql'  => '',
			'args' => array( $author_taxonomy, self::SKIP_POST_FOR_BACKFILL_META_KEY ),
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

	/**
	 * Obtains the count of posts that are missing a specific term.
	 *
	 * @param string   $author_taxonomy The author taxonomy to search for.
	 * @param string[] $post_types The post types to search for.
	 * @param string[] $post_statuses The post statuses to search for.
	 * @param int[]    $specific_post_ids The specific post IDs to search for.
	 * @param int|null $above_post_id The post ID to start from.
	 * @param int|null $below_post_id The post ID to end at.
	 *
	 * @return int
	 * @throws Exception If the $above_post_id is greater than or equal to the $below_post_id.
	 */
	private function get_count_of_posts_with_missing_terms( $author_taxonomy, $post_types = array( 'post' ), $post_statuses = array( 'publish' ), $specific_post_ids = array(), $above_post_id = null, $below_post_id = null ) {
		global $wpdb;

		[
			$sql,
			$args,
		] = array_values( $this->get_sql_for_posts_with_missing_terms( $author_taxonomy, $post_types, $post_statuses, $specific_post_ids, $above_post_id, $below_post_id ) );

		// Replace the first SELECT with SELECT COUNT(*).
		$sql = preg_replace(
			'/^(SELECT(?s)(.*?)FROM)/',
			'SELECT COUNT(*) FROM',
			$sql,
			1
		);

		// phpcs:disable -- Query is properly prepared
		return intval( $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) );
		// phpcs:enable
	}

	/**
	 * Obtains posts that are missing a specific term.
	 *
	 * @param string   $author_taxonomy The author taxonomy to search for.
	 * @param string[] $post_types The post types to search for.
	 * @param string[] $post_statuses The post statuses to search for.
	 * @param bool     $batched Whether to process the records in batches.
	 * @param int      $records_per_batch The number of posts to retrieve per page.
	 * @param int[]    $specific_post_ids The specific post IDs to search for.
	 * @param int|null $above_post_id The post ID to start from.
	 * @param int|null $below_post_id The post ID to end at.
	 *
	 * @return array
	 * @throws Exception If the $above_post_id is greater than or equal to the $below_post_id.
	 */
	private function get_posts_with_missing_terms( $author_taxonomy, $post_types = array( 'post' ), $post_statuses = array( 'publish' ), $batched = false, $records_per_batch = 250, $specific_post_ids = array(), $above_post_id = null, $below_post_id = null ) {
		global $wpdb;

		[
			$sql,
			$args,
		] = array_values( $this->get_sql_for_posts_with_missing_terms( $author_taxonomy, $post_types, $post_statuses, $specific_post_ids, $above_post_id, $below_post_id ) );

		if ( $batched ) {
			$sql .= " LIMIT $records_per_batch";
		}

		// phpcs:disable -- Query is properly prepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable
	}

	/**
	 * This function will insert a postmeta row for posts that should be skipped for processing in the author term
	 * backfill command ('create-author-terms-for-posts' or function name `create_author_terms_for_posts`).
	 *
	 * @param int    $post_id The Post ID that needs to be skipped.
	 * @param string $reason The reason the post needs to be skipped.
	 *
	 * @return void;
	 */
	private function skip_backfill_for_post( $post_id, $reason ) {
		add_post_meta( $post_id, self::SKIP_POST_FOR_BACKFILL_META_KEY, $reason, true );
	}

	/**
	 * Convenience function to generate a formatted percentage string.
	 *
	 * @param int $completed Number of completed cycles.
	 * @param int $total Total number of cycles.
	 *
	 * @return string
	 */
	private function get_formatted_complete_percentage( $completed, $total ) {
		return number_format( ( $completed / $total ) * 100, 2 ) . '%';
	}
}
