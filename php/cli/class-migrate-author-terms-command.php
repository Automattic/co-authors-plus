<?php
/**
 * The migrate-author-terms WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use CoAuthors\Prefix;
use CoAuthors_Plus;
use WP_CLI;

/**
 * Adds the 'cap-' prefix to author terms left over from before 3.0.
 *
 * Moved here from CoAuthorsPlus_Command unchanged. Behaviour is pinned by
 * features/migrate-author-terms.feature.
 */
class Migrate_Author_Terms_Command {

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
	 * Give pre-3.0 author terms their 'cap-' prefix.
	 *
	 * Before 3.0 author terms carried no prefix, so they could collide with terms in
	 * other taxonomies. This prefixes any that are still bare, merging them into the
	 * prefixed term where one already exists.
	 *
	 * ## EXAMPLES
	 *
	 *     # Prefix any legacy author terms.
	 *     $ wp co-authors-plus migrate-author-terms
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$coauthors_plus = $this->coauthors_plus;

		$author_terms = get_terms(
			array(
				'taxonomy'   => $coauthors_plus->coauthor_taxonomy,
				'hide_empty' => false,
			) 
		);
		WP_CLI::log( 'Now migrating up to ' . count( $author_terms ) . ' terms' );
		foreach ( $author_terms as $author_term ) {
			// Term is already prefixed. We're good.
			if ( Prefix::slug_has_prefix( $author_term->slug ) ) {
				WP_CLI::log( "Term {$author_term->slug} ({$author_term->term_id}) is already prefixed, skipping" );
				continue;
			}
			// A prefixed term was accidentally created, and the old term needs to be merged into the new (WordPress.com VIP).
			$prefixed_term = get_term_by( 'slug', Prefix::prefix_slug( $author_term->slug ), $coauthors_plus->coauthor_taxonomy );

			if ( $prefixed_term ) {
				WP_CLI::log( "Term {$author_term->slug} ({$author_term->term_id}) has a new term too: $prefixed_term->slug ($prefixed_term->term_id). Merging" );
				$args = array(
					'default'       => $author_term->term_id,
					'force_default' => true,
				);
				wp_delete_term( $prefixed_term->term_id, $coauthors_plus->coauthor_taxonomy, $args );
			}

			// Term isn't prefixed, doesn't have a sibling, and should be updated.
			WP_CLI::log( "Term {$author_term->slug} ({$author_term->term_id}) isn't prefixed, adding one" );
			$args = array(
				'slug' => Prefix::prefix_slug( $author_term->slug ),
			);
			wp_update_term( $author_term->term_id, $coauthors_plus->coauthor_taxonomy, $args );
		}//end foreach
		WP_CLI::success( 'All done! Grab a cold one (Affogatto)' );
	}
}
