<?php
/**
 * The create-terms-for-posts WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use CoAuthors_Plus;
use WP_CLI;
use WP_Query;
use WP_Term;

/**
 * Adds a missing author term to every supported post.
 *
 * Behaviour is pinned by features/create-terms-for-posts.feature.
 */
class Create_Terms_For_Posts_Command {

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
	 * Create author terms for any post missing one.
	 *
	 * Walks every supported post type from the start of the table. See
	 * create-author-terms-for-posts for a version that targets only the posts that
	 * need it, which is faster on all but the smallest sites.
	 *
	 * ## OPTIONS
	 *
	 * [--post-statuses=<post-statuses>]
	 * : Comma-separated post statuses to cover. Defaults to publish.
	 *
	 * ## EXAMPLES
	 *
	 *     # Backfill author terms across the site.
	 *     $ wp co-authors-plus create-terms-for-posts
	 *
	 *     # Cover drafts and pending posts as well.
	 *     $ wp co-authors-plus create-terms-for-posts --post-statuses=publish,draft,pending
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$coauthors_plus = $this->coauthors_plus;

		// Cache this to prevent repeated lookups.
		$author_terms = array();

		// Named explicitly rather than left to WP_Query's default, which is publish
		// plus whatever private statuses the current user can read — so the scope of a
		// backfill would otherwise depend on whether --user was passed.
		$post_statuses = isset( $assoc_args['post-statuses'] ) ? explode( ',', $assoc_args['post-statuses'] ) : array( 'publish' );

		$args = array(
			'order'             => 'ASC',
			'orderby'           => 'ID',
			'post_type'         => $coauthors_plus->supported_post_types(),
			'post_status'       => $post_statuses,
			'posts_per_page'    => 100,
			'paged'             => 1,
			'update_meta_cache' => false,
		);

		$posts       = new WP_Query( $args );
		$affected    = 0;
		$count       = 0;
		$total_posts = $posts->found_posts;
		WP_CLI::log( "Now inspecting or updating {$posts->found_posts} total posts." );
		while ( $posts->post_count ) {

			foreach ( $posts->posts as $single_post ) {

				$count++;

				$terms = cap_get_coauthor_terms_for_post( $single_post->ID );
				if ( empty( $terms ) ) {
					WP_CLI::log( sprintf( 'No co-authors found for post #%d.', $single_post->ID ) );
				}

				if ( ! empty( $terms ) ) {
					WP_CLI::log( "{$count}/{$total_posts}) Skipping - Post #{$single_post->ID} '{$single_post->post_title}' already has these terms: " . implode( ', ', wp_list_pluck( $terms, 'slug' ) ) );
					continue;
				}

				$author_term                               = $author_terms[ $single_post->post_author ] ?? $coauthors_plus->update_author_term( get_user_by( 'id', $single_post->post_author ) );
				$author_terms[ $single_post->post_author ] = $author_term;

				// update_author_term() returns false for a missing user and a WP_Error if the
				// term cannot be created. Both used to be dereferenced, setting no term while
				// still counting the post as done.
				if ( ! $author_term instanceof WP_Term ) {
					WP_CLI::warning( "{$count}/{$total_posts}) Skipping - Post #{$single_post->ID} '{$single_post->post_title}' has no author term for user ID {$single_post->post_author}." );
					continue;
				}

				wp_set_post_terms( $single_post->ID, array( $author_term->slug ), $coauthors_plus->coauthor_taxonomy );
				WP_CLI::log( "{$count}/{$total_posts}) Added - Post #{$single_post->ID} '{$single_post->post_title}' now has this author term: {$author_term->slug}" );
				$affected++;
			}//end foreach

			if ( $count && 0 === $count % 500 ) {
				\WP_CLI\Utils\wp_clear_object_cache();
				sleep( 1 );
			}

			$args['paged']++;
			$posts = new WP_Query( $args );
		}//end while
		WP_CLI::success( "Done! Of {$total_posts} posts, {$affected} now have author terms." );
	}
}
