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

		if ( is_wp_error( $author_terms ) ) {
			WP_CLI::error( $author_terms->get_error_message() );
		}

		// Prefixed terms need nothing doing, and counting them made the total wrong.
		// Dropping them here also keeps the loop off stale term objects, since the only
		// row it deletes is a prefixed sibling the loop no longer holds.
		$author_terms = array_filter(
			$author_terms,
			static fn ( $author_term ): bool => ! Prefix::slug_has_prefix( $author_term->slug )
		);

		WP_CLI::log(
			sprintf(
				/* translators: Count of author terms. */
				_n(
					'Now migrating up to %s term',
					'Now migrating up to %s terms',
					count( $author_terms ),
					'co-authors-plus'
				),
				number_format_i18n( count( $author_terms ) )
			)
		);

		foreach ( $author_terms as $author_term ) {
			// A prefixed term was accidentally created, and the old term needs to be merged into the new (WordPress.com VIP).
			$prefixed_term = get_term_by( 'slug', Prefix::prefix_slug( $author_term->slug ), $coauthors_plus->coauthor_taxonomy );

			if ( $prefixed_term ) {
				WP_CLI::log( "Term {$author_term->slug} ({$author_term->term_id}) has a new term too: $prefixed_term->slug ($prefixed_term->term_id). Merging" );
				$delete_args = array(
					'default'       => $author_term->term_id,
					'force_default' => true,
				);
				wp_delete_term( $prefixed_term->term_id, $coauthors_plus->coauthor_taxonomy, $delete_args );
			}

			// Whether or not a sibling was just merged in, this term still holds the
			// unprefixed slug: the merge reassigns the sibling's posts to THIS term and
			// deletes the sibling, so re-slugging here is what completes the migration.
			WP_CLI::log( "Term {$author_term->slug} ({$author_term->term_id}) isn't prefixed, adding one" );
			$update_args = array(
				'slug' => Prefix::prefix_slug( $author_term->slug ),
			);
			wp_update_term( $author_term->term_id, $coauthors_plus->coauthor_taxonomy, $update_args );
		}//end foreach
		WP_CLI::success( 'All done! Grab a cold one (Affogato)' );
	}
}
