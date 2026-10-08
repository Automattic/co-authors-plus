<?php
/**
 * The update-author-terms WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use CoAuthors_Guest_Authors;
use CoAuthors_Plus;
use WP_CLI;
use WP_Query;

/**
 * Refreshes author term counts and descriptions.
 *
 * Behaviour is pinned by features/update-author-terms.feature.
 */
class Update_Author_Terms_Command {

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
	 * Refresh every author term's description and post count.
	 *
	 * Also creates any author term missing for an existing user or guest author.
	 *
	 * ## EXAMPLES
	 *
	 *     # Refresh all author terms.
	 *     $ wp co-authors-plus update-author-terms
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
		WP_CLI::log(
			sprintf(
				/* translators: Count of author terms. */
				_n(
					'Now updating %s term',
					'Now updating %s terms',
					count( $author_terms ),
					'co-authors-plus'
				),
				number_format_i18n( count( $author_terms ) )
			)
		);
		foreach ( $author_terms as $author_term ) {
			$old_count = $author_term->count;
			$coauthor  = $coauthors_plus->get_coauthor_by( 'user_nicename', $author_term->slug );
			$coauthors_plus->update_author_term( $coauthor );
			$coauthors_plus->update_author_term_post_count( $author_term );
			clean_term_cache( $author_term->term_id, $coauthors_plus->coauthor_taxonomy );
			$new_count = get_term_by( 'id', $author_term->term_id, $coauthors_plus->coauthor_taxonomy )->count;
			WP_CLI::log( "Term {$author_term->slug} ({$author_term->term_id}) changed from {$old_count} to {$new_count} and the description was refreshed" );
		}
		// Create author terms for any users that don't have them.
		$users = get_users();
		foreach ( $users as $user ) {
			$term = $coauthors_plus->get_author_term( $user );
			if ( empty( $term ) || empty( $term->description ) ) {
				$coauthors_plus->update_author_term( $user );
				WP_CLI::log( "Created author term for {$user->user_login}" );
			}
		}

		// And create author terms for any Guest Authors that don't have them.
		if ( $coauthors_plus->guest_authors instanceof CoAuthors_Guest_Authors && $coauthors_plus->is_guest_authors_enabled() ) {
			$args = array(
				'order'             => 'ASC',
				'orderby'           => 'ID',
				'post_type'         => $coauthors_plus->guest_authors->post_type,
				// Guest authors are inserted as drafts, so the default 'publish' would hide them.
				'post_status'       => 'any',
				'posts_per_page'    => 100,
				'paged'             => 1,
				'update_meta_cache' => false,
				'fields'            => 'ids',
			);

			$posts = new WP_Query( $args );
			WP_CLI::log(
				sprintf(
					/* translators: Count of guest authors. */
					_n(
						'Now inspecting or updating %s Guest Author.',
						'Now inspecting or updating %s Guest Authors.',
						(int) $posts->found_posts,
						'co-authors-plus'
					),
					number_format_i18n( (int) $posts->found_posts )
				)
			);

			while ( $posts->post_count ) {
				foreach ( $posts->posts as $guest_author_id ) {

					$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'ID', $guest_author_id );

					if ( ! $guest_author ) {
						WP_CLI::log( 'Failed to load guest author ' . $guest_author_id );

						continue;
					}

					$term = $coauthors_plus->get_author_term( $guest_author );

					if ( empty( $term ) || empty( $term->description ) ) {
						$coauthors_plus->update_author_term( $guest_author );

						WP_CLI::log( "Created author term for Guest Author {$guest_author->user_nicename}" );
					}
				}

				\WP_CLI\Utils\wp_clear_object_cache();

				$args['paged']++;
				$posts = new WP_Query( $args );
			}//end while
		}//end if

		WP_CLI::success( 'All done' );
	}
}
