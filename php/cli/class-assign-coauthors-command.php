<?php
/**
 * The assign-coauthors WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use CoAuthors_Plus;
use WP_CLI;
use WP_Query;

/**
 * Assigns co-authors to posts from a post meta value.
 *
 * Moved here from CoAuthorsPlus_Command unchanged, save for the scratch
 * property that held its parsed arguments becoming a local variable, which it
 * always was in effect. Behaviour is pinned by features/assign-coauthors.feature.
 */
class Assign_Coauthors_Command {

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
	 * Assign co-authors to posts based on a post meta value.
	 *
	 * Reads the meta key from each post of the given type and treats its value
	 * as a co-author login, which is how authorship arrives from many importers.
	 *
	 * ## OPTIONS
	 *
	 * [--meta_key=<meta-key>]
	 * : The post meta key holding the co-author login. Defaults to
	 * _original_import_author.
	 *
	 * [--post_type=<post-type>]
	 * : Limit to one post type. Defaults to post.
	 *
	 * [--append_coauthors]
	 * : Add to the existing byline rather than replacing it.
	 *
	 * ## EXAMPLES
	 *
	 *     # Assign from the default import meta key.
	 *     $ wp co-authors-plus assign-coauthors
	 *
	 *     # Assign pages from a meta key of your own, keeping existing bylines.
	 *     $ wp co-authors-plus assign-coauthors --meta_key=legacy_author --post_type=page --append_coauthors
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$coauthors_plus = $this->coauthors_plus;

		$defaults   = array(
			'meta_key'         => '_original_import_author',
			'post_type'        => 'post',
			'order'            => 'ASC',
			'orderby'          => 'ID',
			'posts_per_page'   => 100,
			'paged'            => 1,
			'append_coauthors' => false,
		);
		$parsed_args = wp_parse_args( $assoc_args, $defaults );

		// For global use and not a part of WP_Query.
		$append_coauthors = $parsed_args['append_coauthors'];
		unset( $parsed_args['append_coauthors'] );

		$posts_total              = 0;
		$posts_already_associated = 0;
		$posts_missing_coauthor   = 0;
		$posts_associated         = 0;
		$missing_coauthors        = array();

		$posts = new WP_Query( $parsed_args );
		while ( $posts->post_count ) {

			foreach ( $posts->posts as $single_post ) {
				$posts_total++;

				// See if the value in the post meta field is the same as any of the existing co-authors.
				$original_author    = get_post_meta( $single_post->ID, $parsed_args['meta_key'], true );
				$existing_coauthors = get_coauthors( $single_post->ID );
				$already_associated = false;
				foreach ( $existing_coauthors as $existing_coauthor ) {
					if ( $original_author == $existing_coauthor->user_login ) {
						$already_associated = true;
						break;
					}
				}
				if ( $already_associated ) {
					$posts_already_associated++;
					WP_CLI::log( $posts_total . ': Post #' . $single_post->ID . ' already has "' . $original_author . '" associated as a co-author' );
					continue;
				}

				// Make sure this original author exists as a co-author. The
				// meta value is tried as given and then as a slug, which is
				// how it is stored once an importer has been through it.
				$coauthor = $coauthors_plus->get_coauthor_by( 'user_login', $original_author );

				if ( ! $coauthor ) {
					$coauthor = $coauthors_plus->get_coauthor_by( 'user_login', sanitize_title( $original_author ) );
				}

				if ( ! $coauthor ) {
					$posts_missing_coauthor++;
					$missing_coauthors[] = $original_author;
					WP_CLI::log( $posts_total . ': Post #' . $single_post->ID . ' does not have "' . $original_author . '" associated as a co-author but there is not a co-author profile' );
					continue;
				}

				// Assign the co-author to the post.
				$coauthors_plus->add_coauthors( $single_post->ID, array( $coauthor->user_nicename ), $append_coauthors );
				WP_CLI::log( $posts_total . ': Post #' . $single_post->ID . ' has been assigned "' . $original_author . '" as the author' );
				$posts_associated++;
				clean_post_cache( $single_post->ID );
			}//end foreach

			$parsed_args['paged']++;
			\WP_CLI\Utils\wp_clear_object_cache();
			$posts = new WP_Query( $parsed_args );
		}//end while

		WP_CLI::log( 'All done! Here are your results:' );
		if ( $posts_already_associated ) {
			WP_CLI::log( "- {$posts_already_associated} posts already had the co-author assigned" );
		}
		if ( $posts_missing_coauthor ) {
			WP_CLI::log( "- {$posts_missing_coauthor} posts reference co-authors that don't exist. These are:" );
			WP_CLI::log( '  ' . implode( ', ', array_unique( $missing_coauthors ) ) );
		}
		if ( $posts_associated ) {
			WP_CLI::log( "- {$posts_associated} posts now have the proper co-author" );
		}
	}
}
