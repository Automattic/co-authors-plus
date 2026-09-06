<?php
/**
 * The list-posts-without-terms WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use CoAuthors_Plus;
use WP_CLI;
use WP_Query;

/**
 * Lists posts that have no author terms.
 *
 * Moved here from CoAuthorsPlus_Command unchanged, save for the scratch
 * property holding its parsed arguments becoming a local. Behaviour is pinned
 * by features/list-posts-without-terms.feature.
 */
class List_Posts_Without_Terms_Command {

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
	 * List posts carrying no author terms.
	 *
	 * Prints one comma-separated line per post, so the output can be fed onwards.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<post-type>]
	 * : Limit to one post type. Defaults to post.
	 *
	 * ## EXAMPLES
	 *
	 *     # Find posts with no author terms.
	 *     $ wp co-authors-plus list-posts-without-terms
	 *
	 *     # The same, for pages.
	 *     $ wp co-authors-plus list-posts-without-terms --post_type=page
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$defaults   = array(
			'post_type'         => 'post',
			// Without this the query defaults to publish, hiding the drafts this
			// command exists to find. 'any' still excludes trash and auto-drafts.
			'post_status'       => 'any',
			'order'             => 'ASC',
			'orderby'           => 'ID',
			'posts_per_page'    => 300,
			'paged'             => 1,
			'no_found_rows'     => true,
			'update_meta_cache' => false,
		);
		$parsed_args = wp_parse_args( $assoc_args, $defaults );

		$posts = new WP_Query( $parsed_args );
		while ( $posts->post_count ) {

			foreach ( $posts->posts as $single_post ) {

				$terms = cap_get_coauthor_terms_for_post( $single_post->ID );
				if ( empty( $terms ) ) {
					$saved = array(
						$single_post->ID,
						$single_post->post_title,
						get_permalink( $single_post->ID ),
						$single_post->post_date,
					);
					// Every field is quoted, so CSV needs an embedded quote doubled.
					// addslashes() backslash-escaped it instead, which no CSV parser reads.
					WP_CLI::log( '"' . implode( '","', str_replace( '"', '""', $saved ) ) . '"' );
				}
			}

			\WP_CLI\Utils\wp_clear_object_cache();

			$parsed_args['paged']++;
			$posts = new WP_Query( $parsed_args );
		}//end while
	}
}
