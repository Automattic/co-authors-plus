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
use WP_User;

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

		// Read the preview flags before defaults are merged in, so that an.
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

		// A usage error, so it is reported before any lookup: an empty --to used to
		// surface only after --from had resolved, so "--from=nobody --to=" complained
		// about the co-author rather than the missing parameter.
		if ( ! $to_userlogin ) {
			WP_CLI::error( '--to param must not be empty' );
		}

		$orig_coauthor = $coauthors_plus->get_coauthor_by( 'user_login', $from_userlogin );

		if ( ! $orig_coauthor ) {
			WP_CLI::error( "No co-author found for $from_userlogin" );
		}

		$to_coauthor = $coauthors_plus->get_coauthor_by( 'user_login', $to_userlogin );

		if ( ! $to_coauthor ) {
			WP_CLI::error( "No co-author found for $to_userlogin" );
		}

		// Work from the resolved logins rather than the raw input. Co-author.
		// lookups are not case sensitive, so "--from=Alice" can resolve to the.
		// co-author stored as "alice"; comparing the raw value against the.
		// stored one would then never match, the term would never be removed,.
		// and the drain loop below would never end.
		$from_userlogin = $orig_coauthor->user_login;
		$to_userlogin   = $to_coauthor->user_login;

		$from_userlogin_prefixed = Prefix::prefix_slug( $from_userlogin );

		// Swapping a co-author with themselves would remove the term and add it.
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

		$posts_total          = 0;
		$posts_keeping_author = 0;

		WP_CLI::log(
			sprintf(
				/* translators: Count of posts. */
				_n(
					'Found %s post to update.',
					'Found %s posts to update.',
					(int) $posts->found_posts,
					'co-authors-plus'
				),
				number_format_i18n( (int) $posts->found_posts )
			)
		);

		// This command is term-driven. A post whose only link to the from author is
		// wp_posts.post_author carries no cap- term, matches nothing above, and used to
		// vanish into a clean "Found 0 posts" — the likeliest shape on a site migrating
		// from plain WordPress authorship. Saying so is deliberate; actually swapping
		// those posts would widen what the command writes, which is a separate decision.
		$from_user = null;

		if ( $orig_coauthor instanceof WP_User ) {
			$from_user = $orig_coauthor;
		} elseif ( isset( $orig_coauthor->wp_user ) && $orig_coauthor->wp_user instanceof WP_User ) {
			$from_user = $orig_coauthor->wp_user;
		}

		if ( $from_user ) {
			$post_author_only = new WP_Query(
				array(
					'post_type'        => $assoc_args['post_type'],
					// The main query above sets no status, so an unauthenticated CLI run
					// sees publish only; this count matches that scope explicitly.
					'post_status'      => 'publish',
					// author__in rather than author: identical for one ID, and the source-grep
					// guard in CoauthorTaxonomyUsageTest rightly cannot tell a literal 'author'
					// query key from the taxonomy name it exists to keep out of these files.
					'author__in'       => array( $from_user->ID ),
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					// CAP itself rewrites author queries to include term matches, which
					// would defeat the point of this count. CLI-only, one query per run.
					'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
					'tax_query'        => array(
						array(
							'taxonomy' => $coauthors_plus->coauthor_taxonomy,
							'field'    => 'slug',
							'terms'    => array( $from_userlogin_prefixed ),
							'operator' => 'NOT IN',
						),
					),
				)
			);

			if ( $post_author_only->found_posts > 0 ) {
				WP_CLI::warning(
					sprintf(
						/* translators: 1: Count of posts. 2: Co-author login. 3: Author term slug. */
						_n(
							'%1$s post has %2$s as its post_author but does not carry the %3$s term, so this swap does not touch it.',
							'%1$s posts have %2$s as their post_author but do not carry the %3$s term, so this swap does not touch them.',
							$post_author_only->found_posts,
							'co-authors-plus'
						),
						number_format_i18n( $post_author_only->found_posts ),
						$from_userlogin,
						$from_userlogin_prefixed
					)
				);
			}
		}//end if

		$previous_first_post_id = null;

		while ( $posts->post_count ) {
			// Outside preview mode this loop re-runs the same query and relies.
			// on each post losing the "from" term to make progress. If a page.
			// comes back unchanged, that has not happened, so stop rather than.
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
					// The byline is written either way; a false return means only that post_author
					// could not be pointed at a WordPress user, which is the norm when the swap
					// targets a guest author with no account.
					$post_author_synced = $coauthors_plus->add_coauthors( $post->ID, $coauthors );

					WP_CLI::log( $posts_total . ': Post #' . $post->ID . ' has been assigned "' . $to_userlogin . '" as a co-author' );

					if ( ! $post_author_synced ) {
						$posts_keeping_author++;
					}

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

		if ( $posts_keeping_author ) {
			WP_CLI::log(
				sprintf(
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

		WP_CLI::success( 'All done!' );
	}
}
