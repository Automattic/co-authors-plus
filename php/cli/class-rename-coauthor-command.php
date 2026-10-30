<?php
/**
 * The rename-coauthor WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use CoAuthors\Prefix;
use CoAuthors_Plus;
use WP_CLI;

/**
 * Renames the term, and any guest author profile, behind one co-author.
 *
 * Moved here from CoAuthorsPlus_Command unchanged. Behaviour is pinned by
 * features/rename-coauthor.feature.
 */
class Rename_Coauthor_Command {

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
	 * Change the user_login a co-author term represents.
	 *
	 * Where the co-author is a guest author, its profile post_name is changed to
	 * match, so the term and the profile do not drift apart. Unlike swap-coauthors
	 * this renames the term itself rather than moving posts between two terms.
	 *
	 * ## OPTIONS
	 *
	 * --from=<user-login>
	 * : The login the term currently represents.
	 *
	 * --to=<user-login>
	 * : The login it should represent instead.
	 *
	 * ## EXAMPLES
	 *
	 *     # Rename a co-author.
	 *     $ wp co-authors-plus rename-coauthor --from=olduser --to=newuser
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		global $wpdb;

		$coauthors_plus = $this->coauthors_plus;

		$defaults   = array(
			'from' => null,
			'to'   => null,
		);
		$assoc_args = array_merge( $defaults, $assoc_args );

		$to_userlogin          = $assoc_args['to'];
		$to_userlogin_prefixed = Prefix::prefix_slug( $to_userlogin );

		$orig_coauthor = $coauthors_plus->get_coauthor_by( 'user_login', $assoc_args['from'] );
		if ( ! $orig_coauthor ) {
			WP_CLI::error( "No co-author found for {$assoc_args['from']}" );
		}

		if ( ! $to_userlogin ) {
			WP_CLI::error( '--to param must not be empty' );
		}

		if ( $coauthors_plus->get_coauthor_by( 'user_login', $to_userlogin ) ) {
			WP_CLI::error( 'New user_login value conflicts with existing co-author' );
		}

		$orig_term = $coauthors_plus->get_author_term( $orig_coauthor );

		WP_CLI::log( "Renaming {$orig_term->name} to {$to_userlogin}" );
		$rename_args = array(
			'name' => $to_userlogin,
			'slug' => $to_userlogin_prefixed,
		);
		wp_update_term( $orig_term->term_id, $coauthors_plus->coauthor_taxonomy, $rename_args );

		if ( 'guest-author' == $orig_coauthor->type ) {
			$wpdb->update( $wpdb->posts, array( 'post_name' => $to_userlogin_prefixed ), array( 'ID' => $orig_coauthor->ID ) );
			clean_post_cache( $orig_coauthor->ID );
			update_post_meta( $orig_coauthor->ID, Prefix::ensure_meta_key_prefix( 'user_login' ), $to_userlogin );
			$coauthors_plus->guest_authors->delete_guest_author_cache( $orig_coauthor->ID );
			WP_CLI::log( 'Updated guest author profile value too' );
		}

		WP_CLI::success( 'All done!' );
	}
}
