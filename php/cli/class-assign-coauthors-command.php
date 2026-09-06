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
	 * [--post-statuses=<post-statuses>]
	 * : Comma-separated post statuses to cover. Defaults to publish.
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

		// Named explicitly rather than left to WP_Query's default, which is publish
		// plus whatever private statuses the current user can read — so the scope of a
		// run would otherwise depend on whether --user was passed. Drafts are opted
		// into rather than included by default, as on create-terms-for-posts, because
		// this command rewrites a byline per post.
		$parsed_args['post_status'] = isset( $assoc_args['post-statuses'] ) ? explode( ',', $assoc_args['post-statuses'] ) : array( 'publish' );
		unset( $parsed_args['post-statuses'] );

		$posts_total              = 0;
		$posts_already_associated = 0;
		$posts_missing_coauthor   = 0;
		$posts_associated         = 0;
		$posts_keeping_author     = 0;
		$posts_missing_meta_value = 0;
		$missing_coauthors        = array();

		$posts = new WP_Query( $parsed_args );
		while ( $posts->post_count ) {

			foreach ( $posts->posts as $single_post ) {
				$posts_total++;

				$original_author = get_post_meta( $single_post->ID, $parsed_args['meta_key'], true );

				// The query matches on the key existing, so the value can still be empty. An
				// empty value names no co-author, so it is not a missing profile and must not
				// join the missing list, where it imploded into a dangling comma.
				if ( '' === $original_author ) {
					$posts_missing_meta_value++;
					WP_CLI::log( $posts_total . ': Post #' . $single_post->ID . ' has an empty ' . $parsed_args['meta_key'] . ' value' );
					continue;
				}

				// Resolve the co-author before deciding anything. The meta value is tried as
				// given and then as a slug, which is how it is stored once an importer has
				// been through it. Everything below then works from the resolved co-author
				// rather than the raw value, so a second run recognises its own work and the
				// log names who was really assigned.
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

				// Only a real author term counts as already associated. get_coauthors() falls
				// back to the post_author user when a post has no terms, and treating that as
				// done left the post with no term at all — invisible to every term-driven
				// query, this plugin's own included.
				$existing_coauthors = cap_get_coauthor_terms_for_post( $single_post->ID ) ? get_coauthors( $single_post->ID ) : array();
				$already_associated = false;
				foreach ( $existing_coauthors as $existing_coauthor ) {
					if ( $coauthor->user_login === $existing_coauthor->user_login ) {
						$already_associated = true;
						break;
					}
				}
				if ( $already_associated ) {
					$posts_already_associated++;
					WP_CLI::log( $posts_total . ': Post #' . $single_post->ID . ' already has "' . $coauthor->user_login . '" associated as a co-author' );
					continue;
				}

				// Assign the co-author to the post. The byline is written either way; a
				// false return means only that post_author could not be pointed at a
				// WordPress user, which is the norm for a guest author with no account.
				$post_author_synced = $coauthors_plus->add_coauthors( $single_post->ID, array( $coauthor->user_nicename ), $append_coauthors );
				WP_CLI::log( $posts_total . ': Post #' . $single_post->ID . ' has been assigned "' . $coauthor->user_login . '" as the author' );
				$posts_associated++;

				if ( ! $post_author_synced ) {
					$posts_keeping_author++;
				}
				clean_post_cache( $single_post->ID );
			}//end foreach

			$parsed_args['paged']++;
			\WP_CLI\Utils\wp_clear_object_cache();
			$posts = new WP_Query( $parsed_args );
		}//end while

		if ( 0 === $posts_total ) {
			WP_CLI::log( sprintf( 'No posts found with the "%s" meta key.', $parsed_args['meta_key'] ) );

			return;
		}

		WP_CLI::log( 'All done! Here are your results:' );
		if ( $posts_already_associated ) {
			WP_CLI::log( "- {$posts_already_associated} posts already had the co-author assigned" );
		}
		if ( $posts_missing_coauthor ) {
			WP_CLI::log( "- {$posts_missing_coauthor} posts reference co-authors that don't exist. These are:" );
			WP_CLI::log( '  ' . implode( ', ', array_unique( $missing_coauthors ) ) );
		}
		if ( $posts_missing_meta_value ) {
			WP_CLI::log(
				'- ' . sprintf(
					/* translators: 1: Count of posts. 2: Post meta key. */
					_n(
						'%1$s post has an empty %2$s value',
						'%1$s posts have an empty %2$s value',
						$posts_missing_meta_value,
						'co-authors-plus'
					),
					number_format_i18n( $posts_missing_meta_value ),
					$parsed_args['meta_key']
				)
			);
		}
		if ( $posts_associated ) {
			WP_CLI::log( "- {$posts_associated} posts now have the proper co-author" );
		}
		if ( $posts_keeping_author ) {
			WP_CLI::log(
				'- ' . sprintf(
					/* translators: Count of posts. */
					_n(
						'%s post kept its original post_author, because no co-author assigned to it has a WordPress account',
						'%s posts kept their original post_author, because no co-author assigned to them has a WordPress account',
						$posts_keeping_author,
						'co-authors-plus'
					),
					number_format_i18n( $posts_keeping_author )
				)
			);
		}
	}
}
