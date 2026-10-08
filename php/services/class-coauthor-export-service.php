<?php
/**
 * Builds a portable representation of the site's guest authors.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Services;

use CoAuthors_Plus;

/**
 * Builds a portable representation of the site's guest authors.
 *
 * Post assignments are recorded by slug and post type rather than by ID,
 * because IDs are reassigned by a WordPress export and import cycle and would
 * point at unrelated posts, or nothing, on the far side.
 */
class Coauthor_Export_Service {

	/**
	 * How many guest authors to load per batch.
	 */
	private const BATCH_SIZE = 500;

	/**
	 * Plugin instance.
	 *
	 * @var CoAuthors_Plus
	 */
	private $coauthors_plus;

	/**
	 * Guest author profile reader.
	 *
	 * @var Guest_Author_Service
	 */
	private $guest_authors;

	/**
	 * Byline reader.
	 *
	 * @var Coauthor_Assignment_Service
	 */
	private $assignments;

	/**
	 * Constructor.
	 *
	 * @param CoAuthors_Plus              $coauthors_plus Plugin instance.
	 * @param Guest_Author_Service        $guest_authors  Guest author profiles.
	 * @param Coauthor_Assignment_Service $assignments    Byline reader.
	 */
	public function __construct(
		CoAuthors_Plus $coauthors_plus,
		Guest_Author_Service $guest_authors,
		Coauthor_Assignment_Service $assignments
	) {
		$this->coauthors_plus = $coauthors_plus;
		$this->guest_authors  = $guest_authors;
		$this->assignments    = $assignments;
	}

	/**
	 * Every guest author ID on the site, oldest first.
	 *
	 * Loaded in batches so a site with a large contributor list does not have
	 * to hold an unbounded query result.
	 *
	 * @return int[]
	 */
	public function guest_author_ids(): array {
		$ids   = array();
		$paged = 1;

		do {
			$batch = get_posts(
				array(
					'post_type'        => $this->coauthors_plus->guest_authors->post_type,
					'post_status'      => 'any',
					'posts_per_page'   => self::BATCH_SIZE,
					'paged'            => $paged,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'fields'           => 'ids',
					'suppress_filters' => false,
				)
			);

			$found = count( $batch );
			$ids   = array_merge( $ids, $batch );
			++$paged;
		} while ( self::BATCH_SIZE === $found );

		return array_map( 'intval', $ids );
	}

	/**
	 * Build the export for one guest author.
	 *
	 * @param int      $guest_author_id Guest author post ID.
	 * @param string[] $post_types      Post types to record assignments for.
	 * @return array<string, mixed>|null Null when the profile cannot be loaded.
	 */
	public function entry_for( int $guest_author_id, array $post_types ): ?array {
		$guest_author = $this->guest_authors->find_by( 'ID', (string) $guest_author_id );

		if ( ! $guest_author ) {
			return null;
		}

		return array(
			'profile'   => $this->guest_authors->profile( $guest_author ),
			'post_refs' => $this->post_refs_for( $guest_author, $post_types ),
		);
	}

	/**
	 * Where a guest author appears across the site's posts.
	 *
	 * @param object   $guest_author Guest author object.
	 * @param string[] $post_types   Post types to look in.
	 * @return array<int, array<string, mixed>>
	 */
	private function post_refs_for( $guest_author, array $post_types ): array {
		$term = $this->coauthors_plus->get_author_term( $guest_author );

		if ( ! $term ) {
			return array();
		}

		// ponytail: one byline read per matched post. Fine for a migration run,
		// and the position data is only available per post; if this becomes the
		// bottleneck on a large site, read term_order in bulk behind a
		// repository rather than adding SQL here.
		$refs = array();

		foreach ( $this->posts_with_term( (int) $term->term_id, $post_types ) as $post ) {
			$position = array_search(
				$guest_author->user_nicename,
				$this->assignments->nicenames_for_post( (int) $post->ID ),
				true
			);

			$refs[] = array(
				'post_slug' => $post->post_name,
				'post_type' => $post->post_type,
				'position'  => false === $position ? 0 : (int) $position,
			);
		}

		return $refs;
	}

	/**
	 * Posts carrying a given author term, in batches.
	 *
	 * @param int      $term_id    Author term ID.
	 * @param string[] $post_types Post types to look in.
	 * @return object[]
	 */
	private function posts_with_term( int $term_id, array $post_types ): array {
		$posts = array();
		$paged = 1;

		do {
			$batch = get_posts(
				array(
					'post_type'        => $post_types,
					'post_status'      => 'any',
					'posts_per_page'   => self::BATCH_SIZE,
					'paged'            => $paged,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					'tax_query'        => array(
						array(
							'taxonomy' => $this->coauthors_plus->coauthor_taxonomy,
							'field'    => 'term_id',
							'terms'    => array( $term_id ),
						),
					),
				)
			);

			$found = count( $batch );
			$posts = array_merge( $posts, $batch );
			++$paged;
		} while ( self::BATCH_SIZE === $found );

		return $posts;
	}
}
