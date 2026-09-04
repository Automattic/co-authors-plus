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
use WP_Query;

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
						continue;
					}

					$authors[ $record->post_author ] = $author;
				}

				$author_term                          = ( ! empty( $author_terms[ $record->post_author ] ) ) ?
					$author_terms[ $record->post_author ] :
					$coauthors_plus->update_author_term( $author );
				$author_terms[ $record->post_author ] = $author_term;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$insert_author_term_relationship = $wpdb->insert(
					$wpdb->term_relationships,
					array(
						'object_id'        => $record->post_id,
						'term_taxonomy_id' => $author_term->term_taxonomy_id,
						'term_order'       => 0,
					)
				);

				if ( false === $insert_author_term_relationship ) {
					WP_CLI::warning( sprintf( 'Failed to insert term relationship for post %d and author %d.', $record->post_id, $record->post_author ) );
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

		WP_CLI::log( sprintf( '%d records affected', $affected ) );

		WP_CLI::log( 'Updating author terms with new counts' );
		$count_of_authors = count( $authors );
		$count            = 0;
		foreach ( $authors as $author ) {
			++$count;
			$result = $coauthors_plus->update_author_term( $author );

			if ( is_wp_error( $result ) || false === $result ) {
				WP_CLI::warning( sprintf( 'Failed to update author term for author %d (%s).', $author->ID, $author->user_nicename ) );
			} else {
				$percentage = $this->get_formatted_complete_percentage( $count, $count_of_authors );
				WP_CLI::success( sprintf( 'Updated author term for author %d (%s) (%s).', $author->ID, $author->user_nicename, $percentage ) );
			}
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
			  AND post_author <> 0
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
