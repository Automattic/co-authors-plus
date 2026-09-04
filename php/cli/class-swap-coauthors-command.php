<?php
/**
 * The swap-coauthors WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use CoAuthors\Prefix;
use CoAuthors_Plus;
use WP_CLI;
use WP_Query;

/**
 * Swaps one co-author for another across the posts they share.
 *
 * Moved here from CoAuthorsPlus_Command unchanged. The behaviour is pinned by
 * features/swap-coauthors.feature, which exercises the command through the wp
 * binary and so is unaffected by which class the code lives in. Thinning this
 * out onto the services is deliberately a separate change, since that alters
 * behaviour and needs its own cover.
 */
class Swap_Coauthors_Command {

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
	 * Swap one co-author for another on every post they are a co-author of.
	 *
	 * Unlike rename-coauthor this leaves the original co-author term in place,
	 * and it works when the incoming co-author already has a term of their own.
	 *
	 * ## OPTIONS
	 *
	 * --from=<user-login>
	 * : The co-author to swap out.
	 *
	 * --to=<user-login>
	 * : The co-author to swap in.
	 *
	 * [--post_type=<post-type>]
	 * : Limit the swap to one post type. Defaults to post.
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * [--dry]
	 * : Deprecated alias for --dry-run.
	 *
	 * ## EXAMPLES
	 *
	 *     # See what swapping alice for bob would do.
	 *     $ wp co-authors-plus swap-coauthors --from=alice --to=bob --dry-run
	 *
	 *     # Do it, across pages rather than posts.
	 *     $ wp co-authors-plus swap-coauthors --from=alice --to=bob --post_type=page
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$coauthors_plus = $this->coauthors_plus;

		$defaults = array(
			'from'      => null,
			'to'        => null,
			'post_type' => 'post',
		);

		// Read the preview flags before defaults are merged in, so that an
		// absent flag stays absent and can be told apart from an explicit one.
		$dry = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		// --dry predates --dry-run and is kept working for existing scripts.
		if ( null !== \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry', null ) ) {
			WP_CLI::warning( 'The --dry flag is deprecated; use --dry-run instead.' );
			$dry = $dry || (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry', false );
		}

		$assoc_args = array_merge( $defaults, $assoc_args );

		$from_userlogin = $assoc_args['from'];
		$to_userlogin   = $assoc_args['to'];

		$orig_coauthor = $coauthors_plus->get_coauthor_by( 'user_login', $from_userlogin );

		if ( ! $orig_coauthor ) {
			WP_CLI::error( "No co-author found for $from_userlogin" );
		}

		if ( ! $to_userlogin ) {
			WP_CLI::error( '--to param must not be empty' );
		}

		$to_coauthor = $coauthors_plus->get_coauthor_by( 'user_login', $to_userlogin );

		if ( ! $to_coauthor ) {
			WP_CLI::error( "No co-author found for $to_userlogin" );
		}

		// Work from the resolved logins rather than the raw input. Co-author
		// lookups are not case sensitive, so "--from=Alice" can resolve to the
		// co-author stored as "alice"; comparing the raw value against the
		// stored one would then never match, the term would never be removed,
		// and the drain loop below would never end.
		$from_userlogin = $orig_coauthor->user_login;
		$to_userlogin   = $to_coauthor->user_login;

		$from_userlogin_prefixed = Prefix::prefix_slug( $from_userlogin );

		// Swapping a co-author with themselves would remove the term and add it
		// straight back, so the loop below would never drain.
		if ( $from_userlogin === $to_userlogin ) {
			WP_CLI::error( '--from and --to must be different co-authors' );
		}

		WP_CLI::log( "Swapping authorship from {$from_userlogin} to {$to_userlogin}" );

		$query_args = array(
			'post_type'      => $assoc_args['post_type'],
			'order'          => 'ASC',
			'orderby'        => 'ID',
			'posts_per_page' => 100,
			'paged'          => 1,
			'tax_query'      => array(
				array(
					'taxonomy' => $coauthors_plus->coauthor_taxonomy,
					'field'    => 'slug',
					'terms'    => array( $from_userlogin_prefixed ),
				),
			),
		);

		$posts = new WP_Query( $query_args );

		$posts_total = 0;

		WP_CLI::log( "Found $posts->found_posts posts to update." );

		$previous_first_post_id = null;

		while ( $posts->post_count ) {
			// Outside preview mode this loop re-runs the same query and relies
			// on each post losing the "from" term to make progress. If a page
			// comes back unchanged, that has not happened, so stop rather than
			// spin forever.
			$first_post_id = $posts->posts[0]->ID;

			if ( ! $dry && $first_post_id === $previous_first_post_id ) {
				WP_CLI::error(
					sprintf(
						'Post #%d still has the "%s" term after being processed, so the swap cannot make progress. Aborting.',
						$first_post_id,
						$from_userlogin_prefixed
					)
				);
			}

			$previous_first_post_id = $first_post_id;

			foreach ( $posts->posts as $post ) {
				$coauthors = get_coauthors( $post->ID );

				if ( ! is_array( $coauthors ) || ! count( $coauthors ) ) {
					continue;
				}

				$coauthors = wp_list_pluck( $coauthors, 'user_login' );

				$posts_total++;

				if ( ! $dry ) {
					// Remove the $from_userlogin from $coauthors.
					foreach ( $coauthors as $index => $user_login ) {
						if ( $from_userlogin === $user_login ) {
							unset( $coauthors[ $index ] );

							break;
						}
					}

					// Add the 'to' author on.
					$coauthors[] = $to_userlogin;

					// By not passing $append = false as the 3rd param, we replace all existing co-authors.
					$coauthors_plus->add_coauthors( $post->ID, $coauthors );

					WP_CLI::log( $posts_total . ': Post #' . $post->ID . ' has been assigned "' . $to_userlogin . '" as a co-author' );

					clean_post_cache( $post->ID );
				} else {
					WP_CLI::log( $posts_total . ': Post #' . $post->ID . ' will be assigned "' . $to_userlogin . '" as a co-author' );
				}//end if
			}//end foreach

			// In dry mode, we must manually advance the page.
			if ( $dry ) {
				$query_args['paged']++;
			}

			\WP_CLI\Utils\wp_clear_object_cache();

			$posts = new WP_Query( $query_args );
		}//end while

		WP_CLI::success( 'All done!' );
	}
}
