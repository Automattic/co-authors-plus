<?php
/**
 * The reassign-terms WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use CoAuthors_Plus;
use WP_CLI;

/**
 * Renames or merges author terms, so an import can be tidied up afterwards.
 *
 * Moved here from CoAuthorsPlus_Command unchanged, save for the scratch
 * property holding its parsed arguments becoming a local. Behaviour is pinned
 * by features/reassign-terms.feature.
 */
class Reassign_Terms_Command {

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
	 * Reassign author terms from one user_login to another.
	 *
	 * Looks for the term representing one login and renames it to another. Where the
	 * target term already exists the two are merged, with the posts moving across.
	 * Useful after an import that created terms from logins which have since changed.
	 *
	 * ## OPTIONS
	 *
	 * [--author-mapping=<file>]
	 * : A PHP file defining $cli_user_map, an array of old login => new login.
	 *
	 * [--old-term=<slug>]
	 * : The login to reassign from. Use with --new-term.
	 *
	 * [--new-term=<slug>]
	 * : The login to reassign to. Use with --old-term.
	 *
	 * [--old_term=<slug>]
	 * : Deprecated alias for --old-term.
	 *
	 * [--new_term=<slug>]
	 * : Deprecated alias for --new-term.
	 *
	 * ## EXAMPLES
	 *
	 *     # Reassign a single term.
	 *     $ wp co-authors-plus reassign-terms --old-term=olduser --new-term=newuser
	 *
	 *     # Reassign in bulk from a mapping file.
	 *     $ wp co-authors-plus reassign-terms --author-mapping=./author-map.php
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
			// WP-CLI supplies these under the hyphenated names given in the.
			// synopsis, so reading underscored keys silently found nothing.
			'author-mapping' => null,
			'old-term'       => null,
			'new-term'       => null,
			'old_term'       => null,
			'new_term'       => null,
		);
		$parsed_args = wp_parse_args( $assoc_args, $defaults );

		$author_mapping = $parsed_args['author-mapping'];
		$old_term       = $parsed_args['old-term'];
		$new_term       = $parsed_args['new-term'];

		// --old_term and --new_term predate the hyphenated spellings and are.
		// kept working for existing scripts.
		if ( null !== $parsed_args['old_term'] ) {
			WP_CLI::warning( 'The --old_term flag is deprecated; use --old-term instead.' );
			$old_term = $old_term ?? $parsed_args['old_term'];
		}

		if ( null !== $parsed_args['new_term'] ) {
			WP_CLI::warning( 'The --new_term flag is deprecated; use --new-term instead.' );
			$new_term = $new_term ?? $parsed_args['new_term'];
		}

		$authors_to_migrate = array();

		// Get the reassignment data.
		if ( $author_mapping && is_file( $author_mapping ) ) {
			require_once $author_mapping;

			if ( ! isset( $cli_user_map ) ) {
				WP_CLI::error( 'Mapping file does not define $cli_user_map: ' . $author_mapping );
			}

			$authors_to_migrate = $cli_user_map;
		} elseif ( $author_mapping ) {
			WP_CLI::error( "--author-mapping file doesn't exist: " . $author_mapping );
		}

		// Alternate reassigment approach.
		if ( $old_term && $new_term ) {
			$authors_to_migrate = array(
				$old_term => $new_term,
			);
		}

		if ( empty( $authors_to_migrate ) ) {
			WP_CLI::error( 'Please specify either --author-mapping, or both --old-term and --new-term.' );
		}

		// For each author to migrate, check whether the term exists,.
		// whether the target term exists, and only do the migration if both are met.
		$results = (object) array(
			'old_term_missing' => 0,
			'new_term_exists'  => 0,
			'success'          => 0,
		);
		foreach ( $authors_to_migrate as $old_user => $new_user ) {

			if ( is_numeric( $new_user ) ) {
				$new_user = get_user_by( 'id', $new_user )->user_login;
			}

			// The old user should exist as a term.
			$old_term = $coauthors_plus->get_author_term( $coauthors_plus->get_coauthor_by( 'login', $old_user ) );
			if ( ! $old_term ) {
				WP_CLI::log( "Error: Term '{$old_user}' doesn't exist, skipping" );
				$results->old_term_missing++;
				continue;
			}

			// If the new user exists as a term already, we want to reassign all posts to that.
			// new term and delete the original.
			// Otherwise, simply rename the old term.
			$new_term = $coauthors_plus->get_author_term( $coauthors_plus->get_coauthor_by( 'login', $new_user ) );
			if ( is_object( $new_term ) ) {
				WP_CLI::log( "Success: There's already a '{$new_user}' term for '{$old_user}'. Reassigning {$old_term->count} posts and then deleting the term" );
				$args = array(
					'default'       => $new_term->term_id,
					'force_default' => true,
				);
				wp_delete_term( $old_term->term_id, $coauthors_plus->coauthor_taxonomy, $args );
				$results->new_term_exists++;
			} else {
				$args = array(
					'slug' => $new_user,
					'name' => $new_user,
				);
				wp_update_term( $old_term->term_id, $coauthors_plus->coauthor_taxonomy, $args );
				WP_CLI::log( "Success: Converted '{$old_user}' term to '{$new_user}'" );
				$results->success++;
			}
			clean_term_cache( $old_term->term_id, $coauthors_plus->coauthor_taxonomy );
		}//end foreach

		WP_CLI::log( 'Reassignment complete. Here are your results:' );
		WP_CLI::log( "- $results->success authors were successfully reassigned terms" );
		WP_CLI::log( "- $results->new_term_exists authors had their old term merged to their new term" );
		WP_CLI::log( "- $results->old_term_missing authors were missing old terms" );
	}
}
