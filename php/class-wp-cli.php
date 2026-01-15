<?php
/**
 * Manage co-authors and guest authors.
 *
 * @package wp-cli
 * @since 3.0
 * @see https://github.com/wp-cli/wp-cli
 */
WP_CLI::add_command( 'co-authors-plus', 'CoAuthorsPlus_Command' );

class CoAuthorsPlus_Command extends WP_CLI_Command {

	const SKIP_POST_FOR_BACKFILL_META_KEY = '_cap_skip_backfill';

	private $args;

	/**
	 * Subcommand to create guest authors based on users
	 *
	 * @since 3.0
	 *
	 * @subcommand create-guest-authors
	 */
	public function create_guest_authors( $args, $assoc_args ): void {
		global $coauthors_plus;

		$defaults = array(
				// There are no arguments at this time
		);
		$this->args = wp_parse_args( $assoc_args, $defaults );

		$users    = get_users();
		$count    = count( $users );
		$created  = 0;
		$skipped  = 0;

		WP_CLI::log( "Attempting to create guest author profiles for {$count} users." );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Processing guest authors...', $count );
		foreach ( $users as $user ) {

			$result = $coauthors_plus->guest_authors->create_guest_author_from_user_id( $user->ID );
			if ( is_wp_error( $result ) ) {
				$skipped++;
			} else {
				$created++;
			}
			$progress->tick();
		}
		$progress->finish();
		WP_CLI::log( 'All done! Here are your results:' );
		WP_CLI::log( "- {$created} guest author profiles were created" );
		WP_CLI::log( "- {$skipped} users already had guest author profiles" );
	}

	/**
	 * Create author terms for all posts that don't have them. However, please see `create_author_terms_for_posts` for an
	 * alternative approach that not only allows for more granular control over which posts are targeted, but
	 * is also faster in most cases.
	 *
	 * @subcommand create-terms-for-posts
	 */
	public function create_terms_for_posts(): void {
		global $coauthors_plus, $wp_post_types;

		// Cache these to prevent repeated lookups
		$authors      = array();
		$author_terms = array();

		$args = array(
			'order'             => 'ASC',
			'orderby'           => 'ID',
			'post_type'         => $coauthors_plus->supported_post_types(),
			'posts_per_page'    => 100,
			'paged'             => 1,
			'update_meta_cache' => false,
		);

		$posts       = new WP_Query( $args );
		$affected    = 0;
		$count       = 0;
		$total_posts = $posts->found_posts;
		WP_CLI::log( "Now inspecting or updating {$posts->found_posts} total posts." );
		while ( $posts->post_count ) {

			foreach ( $posts->posts as $single_post ) {

				$count++;

				$terms = cap_get_coauthor_terms_for_post( $single_post->ID );
				if ( empty( $terms ) ) {
					WP_CLI::log( sprintf( 'No co-authors found for post #%d.', $single_post->ID ) );
				}

				if ( ! empty( $terms ) ) {
					WP_CLI::log( "{$count}/{$posts->found_posts}) Skipping - Post #{$single_post->ID} '{$single_post->post_title}' already has these terms: " . implode( ', ', wp_list_pluck( $terms, 'name' ) ) );
					continue;
				}

				$author                               = ( ! empty( $authors[ $single_post->post_author ] ) ) ? $authors[ $single_post->post_author ] : get_user_by( 'id', $single_post->post_author );
				$authors[ $single_post->post_author ] = $author;

				$author_term                               = ( ! empty( $author_terms[ $single_post->post_author ] ) ) ? $author_terms[ $single_post->post_author ] : $coauthors_plus->update_author_term( $author );
				$author_terms[ $single_post->post_author ] = $author_term;

				wp_set_post_terms( $single_post->ID, array( $author_term->slug ), $coauthors_plus->coauthor_taxonomy );
				WP_CLI::log( "{$count}/{$total_posts}) Added - Post #{$single_post->ID} '{$single_post->post_title}' now has an author term for: " . $author->user_nicename );
				$affected++;
			}

			if ( $count && 0 === $count % 500 ) {
				$this->stop_the_insanity();
				sleep( 1 );
			}

			$args['paged']++;
			$posts = new WP_Query( $args );
		}
		WP_CLI::log( 'Updating author terms with new counts' );
		foreach ( $authors as $author ) {
			$coauthors_plus->update_author_term( $author );
		}

		WP_CLI::success( "Done! Of {$total_posts} posts, {$affected} now have author terms." );

	}

	/**
	 * Creates missing author terms for posts. `create_terms_for_posts` does exactly the same thing as this one,
	 * except with some key differences:
	 * 1. This command will only ever target posts that are missing* author terms, whereas create_terms_for_posts
	 * always will start from the beginning of the posts table and work its way through all posts.
	 * 2. Since this command only targets posts that are missing author terms, it will be faster than
	 * create_terms_for_posts in most cases. If the command is ever interrupted, it can be restarted without
	 * reprocessing posts that already have author terms.
	 * 3. This command allows one to target specific post types and statuses, as well as specific post IDs.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @subcommand create-author-terms-for-posts
	 * @synopsis [--post-types=<csv>] [--post-statuses=<csv>] [--unbatched] [--records-per-batch=<records-per-batch>] [--specific-post-ids=<csv>] [--above-post-id=<above-post-id>] [--below-post-id=<below-post-id>]
	 * @return void
	 * @throws Exception If above-post-id is greater than or equal to below-post-id.
	 */
	public function create_author_terms_for_posts( $args, $assoc_args ) {
		$post_types        = isset( $assoc_args['post-types'] ) ? explode( ',', $assoc_args['post-types'] ) : [ 'post' ];
		$post_statuses     = isset( $assoc_args['post-statuses'] ) ? explode( ',', $assoc_args['post-statuses'] ) : [ 'publish' ];
		$batched           = ! isset( $assoc_args['unbatched'] );
		$records_per_batch = $assoc_args['records-per-batch'] ?? 250;
		$specific_post_ids = isset( $assoc_args['specific-post-ids'] ) ? explode( ',', $assoc_args['specific-post-ids'] ) : [];
		$above_post_id     = $assoc_args['above-post-id'] ?? null;
		$below_post_id     = $assoc_args['below-post-id'] ?? null;

		global $coauthors_plus, $wpdb;

		$count_of_posts_with_missing_author_terms = $this->get_count_of_posts_with_missing_terms(
			$coauthors_plus->coauthor_taxonomy,
			$post_types,
			$post_statuses,
			$specific_post_ids,
			$above_post_id,
			$below_post_id
		);

		WP_CLI::log( sprintf( 'Found %d posts with missing author terms.', $count_of_posts_with_missing_author_terms ) );

		$authors      = [];
		$author_terms = [];
		$count        = 0;
		$affected     = 0;
		$page         = 1;

		$posts_with_missing_author_terms = $this->get_posts_with_missing_terms(
			$coauthors_plus->coauthor_taxonomy,
			$post_types,
			$post_statuses,
			$batched,
			$records_per_batch,
			$specific_post_ids,
			$above_post_id,
			$below_post_id
		);

		do {
			foreach ( $posts_with_missing_author_terms as $record ) {
				$record->post_author = intval( $record->post_author );
				++$count;
				$complete_percentage = $this->get_formatted_complete_percentage( $count, $count_of_posts_with_missing_author_terms );
				WP_CLI::log( sprintf( 'Processing post %d (%d/%d or %s)', $record->post_id, $count, $count_of_posts_with_missing_author_terms, $complete_percentage ) );

				$author = null;
				if ( isset( $authors[ $record->post_author ] ) ) {
					$author = $authors[ $record->post_author ];
				} else {
					$author = get_user_by( 'id', $record->post_author );

					if ( false === $author ) {
						// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users -- This is just trying to convey where the root problem should be resolved.
						WP_CLI::warning( sprintf( 'Post Author ID %d does not exist in %s table, inserting skip postmeta (`%s`).', $record->post_author, $wpdb->users, self::SKIP_POST_FOR_BACKFILL_META_KEY ) );
						$this->skip_backfill_for_post( $record->post_id, 'nonexistent_post_author_id' );
						continue;
					}

					$authors[ $record->post_author ] = $author;
				}

				$author_term                          = ( ! empty( $author_terms[ $record->post_author ] ) ) ?
					$author_terms[ $record->post_author ] :
					$coauthors_plus->update_author_term( $author );
				$author_terms[ $record->post_author ] = $author_term;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$insert_author_term_relationship = $wpdb->insert(
					$wpdb->term_relationships,
					[
						'object_id'        => $record->post_id,
						'term_taxonomy_id' => $author_term->term_taxonomy_id,
						'term_order'       => 0,
					]
				);

				if ( false === $insert_author_term_relationship ) {
					WP_CLI::warning( sprintf( 'Failed to insert term relationship for post %d and author %d.', $record->post_id, $record->post_author ) );
				} else {
					WP_CLI::success( sprintf( 'Inserted term relationship for post %d and author %d (%s).', $record->post_id, $record->post_author, $author->user_nicename ) );
					++$affected;
				}

				if ( $count >= $count_of_posts_with_missing_author_terms ) {
					break;
				}

				if ( $count && 0 === $count % 500 ) {
					sleep( 1 ); // Sleep for a second every 500 posts to avoid overloading the database.
				}
			}

			$posts_with_missing_author_terms = [];

			if ( $batched && $count < $count_of_posts_with_missing_author_terms ) {
				++$page;
				WP_CLI::log( sprintf( 'Processing page %d.', $page ) );
				$posts_with_missing_author_terms = $this->get_posts_with_missing_terms(
					$coauthors_plus->coauthor_taxonomy,
					$post_types,
					$post_statuses,
					$batched,
					$records_per_batch,
					$specific_post_ids,
					$above_post_id,
					$below_post_id
				);
			}
		} while ( ! empty( $posts_with_missing_author_terms ) );

		WP_CLI::log( sprintf( '%d records affected', $affected ) );

		WP_CLI::log( 'Updating author terms with new counts' );
		$count_of_authors = count( $authors );
		$count            = 0;
		foreach ( $authors as $author ) {
			++$count;
			$result = $coauthors_plus->update_author_term( $author );

			if ( is_wp_error( $result ) || false === $result ) {
				WP_CLI::warning( sprintf( 'Failed to update author term for author %d (%s).', $author->ID, $author->user_nicename ) );
			} else {
				$percentage = $this->get_formatted_complete_percentage( $count, $count_of_authors );
				WP_CLI::success( sprintf( 'Updated author term for author %d (%s) (%s).', $author->ID, $author->user_nicename, $percentage ) );
			}
		}

		WP_CLI::success( 'Done!' );
	}

	/**
	 * This command will delete the postmeta rows that were created in order to skip posts for processing in the author
	 * term backfill command ('create-author-terms-for-posts' or function named `create_author_terms_for_posts`).
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @subcommand delete-postmeta-that-skip-author-term-backfill
	 * @synopsis [--specific-post-ids=<csv>]
	 * @return void
	 */
	public function delete_postmeta_skipping_author_term_backfill( $args, $assoc_args ) {
		$specific_post_ids = isset( $assoc_args['specific-post-ids'] ) ? explode( ',', $assoc_args['specific-post-ids'] ) : [];

		if ( empty( $specific_post_ids ) ) {
			$query = new WP_Query(
				[
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_key' => self::SKIP_POST_FOR_BACKFILL_META_KEY,
					'fields'   => 'ids',
				]
			);

			$specific_post_ids = $query->get_posts();
		}

		foreach ( $specific_post_ids as $post_id ) {
			WP_CLI::log( sprintf( 'Deleting postmeta key `%s` for Post ID %d', self::SKIP_POST_FOR_BACKFILL_META_KEY, $post_id ) );
			$result = delete_post_meta( $post_id, self::SKIP_POST_FOR_BACKFILL_META_KEY );

			if ( $result ) {
				WP_CLI::success( '👍' );
			} else {
				WP_CLI::error( '👎' );
			}
		}
	}

	/**
	 * Subcommand to assign co-authors to a post based on a given meta key
	 *
	 * @since 3.0
	 *
	 * @subcommand assign-coauthors
	 * @synopsis [--meta_key=<key>] [--post_type=<ptype>] [--append_coauthors]
	 */
	public function assign_coauthors( $args, $assoc_args ): void {
		global $coauthors_plus;

		$defaults   = array(
			'meta_key'         => '_original_import_author',
			'post_type'        => 'post',
			'order'            => 'ASC',
			'orderby'          => 'ID',
			'posts_per_page'   => 100,
			'paged'            => 1,
			'append_coauthors' => false,
		);
		$this->args = wp_parse_args( $assoc_args, $defaults );

		// For global use and not a part of WP_Query
		$append_coauthors = $this->args['append_coauthors'];
		unset( $this->args['append_coauthors'] );

		$posts_total              = 0;
		$posts_already_associated = 0;
		$posts_missing_coauthor   = 0;
		$posts_associated         = 0;
		$missing_coauthors        = array();

		$posts = new WP_Query( $this->args );
		while ( $posts->post_count ) {

			foreach ( $posts->posts as $single_post ) {
				$posts_total++;

				// See if the value in the post meta field is the same as any of the existing co-authors.
				$original_author    = get_post_meta( $single_post->ID, $this->args['meta_key'], true );
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

				// Make sure this original author exists as a co-author
				if ( ( ! $coauthor = $coauthors_plus->get_coauthor_by( 'user_login', $original_author ) ) &&
					( ! $coauthor = $coauthors_plus->get_coauthor_by( 'user_login', sanitize_title( $original_author ) ) ) ) {
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
			}

			$this->args['paged']++;
			$this->stop_the_insanity();
			$posts = new WP_Query( $this->args );
		}

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

	/**
	 * Assign posts associated with a WordPress user to a co-author
	 * Only apply the changes if there aren't yet co-authors associated with the post
	 *
	 * @since 3.0
	 *
	 * @subcommand assign-user-to-coauthor
	 * @synopsis --user_login=<user-login> --coauthor=<co-author>
	 */
	public function assign_user_to_coauthor( $args, $assoc_args ): void {
		global $coauthors_plus, $wpdb;

		$defaults   = array(
			'user_login' => '',
			'coauthor'   => '',
		);
		$assoc_args = wp_parse_args( $assoc_args, $defaults );

		$user     = get_user_by( 'login', $assoc_args['user_login'] );
		$coauthor = $coauthors_plus->get_coauthor_by( 'login', $assoc_args['coauthor'] );

		if ( ! $user ) {
			WP_CLI::error( __( 'Please specify a valid user_login', 'co-authors-plus' ) );
		}

		if ( ! $coauthor ) {
			WP_CLI::error( __( 'Please specify a valid co-author login', 'co-authors-plus' ) );
		}

		$post_types = implode( "','", $coauthors_plus->supported_post_types() );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$posts    = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_author=%d AND post_type IN ('{$post_types}')", $user->ID ) );
		$affected = 0;
		foreach ( $posts as $post_id ) {
			$coauthors = cap_get_coauthor_terms_for_post( $post_id );
			if ( ! empty( $coauthors ) ) {
				WP_CLI::log(
					sprintf(
						/* translators: 1: Post ID, 2: Comma-separated list of co-author slugs. */
						__( 'Skipping - Post #%1$d already has co-authors assigned: %2$s', 'co-authors-plus' ),
						$post_id,
						implode( ', ', wp_list_pluck( $coauthors, 'slug' ) )
					)
				);
				continue;
			}

			$coauthors_plus->add_coauthors( $post_id, array( $coauthor->user_login ) );
			/* translators: 1: Co-author login, 2: Post ID */
			WP_CLI::log( sprintf( __( "Updating - Adding %1\$s's byline to post #%2\$d", 'co-authors-plus' ), $coauthor->user_login, $post_id ) );
			$affected++;
			if ( $affected && 0 === $affected % 100 ) {
				sleep( 2 );
			}
		}

		$success_message = sprintf(
			/* translators: Count of posts. */
			_n(
				'All done! %d post was affected.',
				'All done! %d posts were affected.',
				$affected,
				'co-authors-plus'
			),
			number_format_i18n( $affected )
		);
		WP_CLI::success( $success_message );

	}

	/**
	 * Subcommand to reassign co-authors based on some given format
	 * This will look for terms with slug 'x' and rename to term with slug and name 'y'
	 * This subcommand can be helpful for cleaning up after an import if the usernames
	 * for authors have changed. During the import process, 'author' terms will be
	 * created with the old user_login value. We can use this to migrate to the new user_login
	 *
	 * @todo support reassigning by CSV
	 *
	 * @since 3.0
	 *
	 * @subcommand reassign-terms
	 * @synopsis [--author-mapping=<file>] [--old_term=<slug>] [--new_term=<slug>]
	 */
	public function reassign_terms( $args, $assoc_args ): void {
		global $coauthors_plus;

		$defaults   = array(
			'author_mapping' => null,
			'old_term'       => null,
			'new_term'       => null,
		);
		$this->args = wp_parse_args( $assoc_args, $defaults );

		$author_mapping = $this->args['author_mapping'];
		$old_term       = $this->args['old_term'];
		$new_term       = $this->args['new_term'];

		// Get the reassignment data
		if ( $author_mapping && is_file( $author_mapping ) ) {
			require_once $author_mapping;
			$authors_to_migrate = $cli_user_map;
		} elseif ( $author_mapping ) {
			WP_CLI::error( "author_mapping doesn't exist: " . $author_mapping );
			exit;
		}

		// Alternate reassigment approach
		if ( $old_term && $new_term ) {
			$authors_to_migrate = array(
				$old_term => $new_term,
			);
		}

		// For each author to migrate, check whether the term exists,
		// whether the target term exists, and only do the migration if both are met
		$results = (object) array(
			'old_term_missing' => 0,
			'new_term_exists'  => 0,
			'success'          => 0,
		);
		foreach ( $authors_to_migrate as $old_user => $new_user ) {

			if ( is_numeric( $new_user ) ) {
				$new_user = get_user_by( 'id', $new_user )->user_login;
			}

			// The old user should exist as a term
			$old_term = $coauthors_plus->get_author_term( $coauthors_plus->get_coauthor_by( 'login', $old_user ) );
			if ( ! $old_term ) {
				WP_CLI::log( "Error: Term '{$old_user}' doesn't exist, skipping" );
				$results->old_term_missing++;
				continue;
			}

			// If the new user exists as a term already, we want to reassign all posts to that
			// new term and delete the original
			// Otherwise, simply rename the old term
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
		}

		WP_CLI::log( 'Reassignment complete. Here are your results:' );
		WP_CLI::log( "- $results->success authors were successfully reassigned terms" );
		WP_CLI::log( "- $results->new_term_exists authors had their old term merged to their new term" );
		WP_CLI::log( "- $results->old_term_missing authors were missing old terms" );

	}

	/**
	 * Change a term from representing one user_login value to another
	 * If the term represents a guest author, the post_name will be changed
	 * in addition to the term slug/name
	 *
	 * @since 3.0.1
	 *
	 * @subcommand rename-coauthor
	 * @synopsis --from=<user-login> --to=<user-login>
	 */
	public function rename_coauthor( $args, $assoc_args ): void {
		global $coauthors_plus, $wpdb;

		$defaults   = array(
			'from' => null,
			'to'   => null,
		);
		$assoc_args = array_merge( $defaults, $assoc_args );

		$to_userlogin          = $assoc_args['to'];
		$to_userlogin_prefixed = 'cap-' . $to_userlogin;

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
			update_post_meta( $orig_coauthor->ID, 'cap-user_login', $to_userlogin );
			$coauthors_plus->guest_authors->delete_guest_author_cache( $orig_coauthor->ID );
			WP_CLI::log( 'Updated guest author profile value too' );
		}

		WP_CLI::success( 'All done!' );
	}

	/**
	 * Swap one co-author with another on all posts for which they are a co-author. Unlike rename-coauthor,
	 * this leaves the original co-author term intact and works when the 'to' user already has a co-author term.
	 *
	 * @subcommand swap-coauthors
	 * @synopsis --from=<user-login> --to=<user-login> [--post_type=<ptype>] [--dry=<dry>]
	 */
	public function swap_coauthors( $args, $assoc_args ): void {
		global $coauthors_plus, $wpdb;

		$defaults = array(
			'from'      => null,
			'to'        => null,
			'post_type' => 'post',
			'dry'       => false,
		);

		$assoc_args = array_merge( $defaults, $assoc_args );

		$dry = $assoc_args['dry'];

		$from_userlogin = $assoc_args['from'];
		$to_userlogin   = $assoc_args['to'];

		$from_userlogin_prefixed = 'cap-' . $from_userlogin;
		$to_userlogin_prefixed   = 'cap-' . $to_userlogin;

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

		while ( $posts->post_count ) {
			foreach ( $posts->posts as $post ) {
				$coauthors = get_coauthors( $post->ID );

				if ( ! is_array( $coauthors ) || ! count( $coauthors ) ) {
					continue;
				}

				$coauthors = wp_list_pluck( $coauthors, 'user_login' );

				$posts_total++;

				if ( ! $dry ) {
					// Remove the $from_userlogin from $coauthors
					foreach ( $coauthors as $index => $user_login ) {
						if ( $from_userlogin === $user_login ) {
							unset( $coauthors[ $index ] );

							break;
						}
					}

					// Add the 'to' author on
					$coauthors[] = $to_userlogin;

					// By not passing $append = false as the 3rd param, we replace all existing co-authors.
					$coauthors_plus->add_coauthors( $post->ID, $coauthors );

					WP_CLI::log( $posts_total . ': Post #' . $post->ID . ' has been assigned "' . $to_userlogin . '" as a co-author' );

					clean_post_cache( $post->ID );
				} else {
					WP_CLI::log( $posts_total . ': Post #' . $post->ID . ' will be assigned "' . $to_userlogin . '" as a co-author' );
				}
			}

			// In dry mode, we must manually advance the page
			if ( $dry ) {
				$query_args['paged']++;
			}

			$this->stop_the_insanity();

			$posts = new WP_Query( $query_args );
		}

		WP_CLI::success( 'All done!' );
	}

	/**
	 * List all the posts without assigned co-authors terms.
	 *
	 * @since 3.0
	 *
	 * @subcommand list-posts-without-terms
	 * @synopsis [--post_type=<ptype>]
	 */
	public function list_posts_without_terms( $args, $assoc_args ): void {
		global $coauthors_plus;

		$defaults   = array(
			'post_type'         => 'post',
			'order'             => 'ASC',
			'orderby'           => 'ID',
			'year'              => '',
			'posts_per_page'    => 300,
			'paged'             => 1,
			'no_found_rows'     => true,
			'update_meta_cache' => false,
		);
		$this->args = wp_parse_args( $assoc_args, $defaults );

		$posts = new WP_Query( $this->args );
		while ( $posts->post_count ) {

			foreach ( $posts->posts as $single_post ) {

				$terms = cap_get_coauthor_terms_for_post( $single_post->ID );
				if ( empty( $terms ) ) {
					$saved = array(
						$single_post->ID,
						addslashes( $single_post->post_title ),
						get_permalink( $single_post->ID ),
						$single_post->post_date,
					);
					WP_CLI::log( '"' . implode( '","', $saved ) . '"' );
				}
			}

			$this->stop_the_insanity();

			$this->args['paged']++;
			$posts = new WP_Query( $this->args );
		}

	}

	/**
	 * Migrate author terms without prefixes to ones with prefixes
	 * Pre-3.0, all author terms didn't have a 'cap-' prefix, which means
	 * they can easily collide with terms in other taxonomies
	 *
	 * @since 3.0
	 *
	 * @subcommand migrate-author-terms
	 */
	public function migrate_author_terms( $args, $assoc_args ): void {
		global $coauthors_plus;

		$author_terms = get_terms( $coauthors_plus->coauthor_taxonomy, array( 'hide_empty' => false ) );
		WP_CLI::log( 'Now migrating up to ' . count( $author_terms ) . ' terms' );
		foreach ( $author_terms as $author_term ) {
			// Term is already prefixed. We're good.
			if ( preg_match( '#^cap\-#', $author_term->slug, $matches ) ) {
				WP_CLI::log( "Term {$author_term->slug} ({$author_term->term_id}) is already prefixed, skipping" );
				continue;
			}
			// A prefixed term was accidentally created, and the old term needs to be merged into the new (WordPress.com VIP)
			if ( $prefixed_term = get_term_by( 'slug', 'cap-' . $author_term->slug, $coauthors_plus->coauthor_taxonomy ) ) {
				WP_CLI::log( "Term {$author_term->slug} ({$author_term->term_id}) has a new term too: $prefixed_term->slug ($prefixed_term->term_id). Merging" );
				$args = array(
					'default'       => $author_term->term_id,
					'force_default' => true,
				);
				wp_delete_term( $prefixed_term->term_id, $coauthors_plus->coauthor_taxonomy, $args );
			}

			// Term isn't prefixed, doesn't have a sibling, and should be updated
			WP_CLI::log( "Term {$author_term->slug} ({$author_term->term_id}) isn't prefixed, adding one" );
			$args = array(
				'slug' => 'cap-' . $author_term->slug,
			);
			wp_update_term( $author_term->term_id, $coauthors_plus->coauthor_taxonomy, $args );
		}
		WP_CLI::success( 'All done! Grab a cold one (Affogatto)' );
	}

	/**
	 * Update the post count and description for each author and guest author
	 *
	 * @since 3.0
	 *
	 * @subcommand update-author-terms
	 */
	public function update_author_terms(): void {
		global $coauthors_plus;
		$author_terms = get_terms( $coauthors_plus->coauthor_taxonomy, array( 'hide_empty' => false ) );
		WP_CLI::log( 'Now updating ' . count( $author_terms ) . ' terms' );
		foreach ( $author_terms as $author_term ) {
			$old_count = $author_term->count;
			$coauthor  = $coauthors_plus->get_coauthor_by( 'user_nicename', $author_term->slug );
			$coauthors_plus->update_author_term( $coauthor );
			$coauthors_plus->update_author_term_post_count( $author_term );
			wp_cache_delete( $author_term->term_id, $coauthors_plus->coauthor_taxonomy );
			$new_count = get_term_by( 'id', $author_term->term_id, $coauthors_plus->coauthor_taxonomy )->count;
			WP_CLI::log( "Term {$author_term->slug} ({$author_term->term_id}) changed from {$old_count} to {$new_count} and the description was refreshed" );
		}
		// Create author terms for any users that don't have them
		$users = get_users();
		foreach ( $users as $user ) {
			$term = $coauthors_plus->get_author_term( $user );
			if ( empty( $term ) || empty( $term->description ) ) {
				$coauthors_plus->update_author_term( $user );
				WP_CLI::log( "Created author term for {$user->user_login}" );
			}
		}

		// And create author terms for any Guest Authors that don't have them
		if ( $coauthors_plus->guest_authors instanceof CoAuthors_Guest_Authors && $coauthors_plus->is_guest_authors_enabled() ) {
			$args = array(
				'order'             => 'ASC',
				'orderby'           => 'ID',
				'post_type'         => $coauthors_plus->guest_authors->post_type,
				'posts_per_page'    => 100,
				'paged'             => 1,
				'update_meta_cache' => false,
				'fields'            => 'ids',
			);

			$posts = new WP_Query( $args );
			WP_CLI::log( "Now inspecting or updating {$posts->found_posts} Guest Authors." );

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

				$this->stop_the_insanity();

				$args['paged']++;
				$posts = new WP_Query( $args );
			}
		}

		WP_CLI::success( 'All done' );
	}

	/**
	 * Remove author terms from revisions, which we've been adding since the dawn of time
	 *
	 * @since 3.0.1
	 *
	 * @subcommand remove-terms-from-revisions
	 */
	public function remove_terms_from_revisions(): void {
		global $wpdb;

		$ids = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type='revision' AND post_status='inherit'" );

		WP_CLI::log( 'Found ' . count( $ids ) . ' revisions to look through' );
		$affected = 0;
		foreach ( $ids as $post_id ) {

			$terms = cap_get_coauthor_terms_for_post( $post_id );
			if ( empty( $terms ) ) {
				continue;
			}

			WP_CLI::log( "#{$post_id}: Removing " . implode( ',', wp_list_pluck( $terms, 'slug' ) ) );
			wp_set_post_terms( $post_id, array(), 'author' );
			$affected++;
		}
		WP_CLI::log( "All done! {$affected} revisions had author terms removed" );
	}

	/**
	 * Subcommand to create guest authors from an author list in a WXR file
	 *
	 * @subcommand create-guest-authors-from-wxr
	 * @synopsis --file=<file>
	 */
	public function create_guest_authors_from_wxr( $args, $assoc_args ): void {
		global $coauthors_plus;

		$defaults   = array(
			'file' => '',
		);
		$this->args = wp_parse_args( $assoc_args, $defaults );

		if ( empty( $this->args['file'] ) || ! is_readable( $this->args['file'] ) ) {
			WP_CLI::error( 'Please specify a valid WXR file with the --file arg.' );
		}

		if ( ! class_exists( 'WXR_Parser' ) ) {
			require_once WP_CONTENT_DIR . '/plugins/wordpress-importer/parsers.php';
		}

		$parser      = new WXR_Parser();
		$import_data = $parser->parse( $this->args['file'] );

		if ( is_wp_error( $import_data ) ) {
			WP_CLI::error( 'Failed to read WXR file.' );
		}

		// Get author nodes
		$authors = $import_data['authors'];

		foreach ( $authors as $author ) {
			WP_CLI::log( sprintf( 'Processing author %s (%s)', $author['author_login'], $author['author_email'] ) );

			$guest_author_data = array(
				'display_name' => $author['author_display_name'],
				'user_login'   => $author['author_login'],
				'user_email'   => $author['author_email'],
				'first_name'   => $author['author_first_name'],
				'last_name'    => $author['author_last_name'],
				'ID'           => $author['author_id'],
			);

			$this->create_guest_author( $guest_author_data );
		}

		WP_CLI::log( 'All done!' );
	}

	/**
	 * Create a single guest author.
	 *
	 * self::create_guest_author() wrapper.
	 *
	 * @subcommand create-author
	 * @synopsis
	 * [--display_name=<display_name>] [--user_login=<user_login>] [--first_name=<first_name>] [--last_name=<last_name>] [--website=<website>] [--user_email=<user_email>] [--description=<description>]
	 */
	public function create_author( $args, $assoc_args ): void {
		$this->create_guest_author( $assoc_args );
	}

	/**
	 * Subcommand to create guest authors from an author list in a CSV file
	 *
	 * @subcommand create-guest-authors-from-csv
	 * @synopsis --file=<file>
	 */
	public function create_guest_authors_from_csv( $args, $assoc_args ): void {
		global $coauthors_plus;

		$defaults   = array(
			'file' => '',
		);
		$this->args = wp_parse_args( $assoc_args, $defaults );

		if ( empty( $this->args['file'] ) || ! is_readable( $this->args['file'] ) ) {
			WP_CLI::error( 'Please specify a valid CSV file with the --file arg.' );
		}

		$file = fopen( $this->args['file'], 'rb' );

		if ( ! $file ) {
			WP_CLI::error( 'Failed to read file.' );
		}

		$authors = array();

		$row = 0;
		while ( false !== ( $data = fgetcsv( $file ) ) ) {
			if ( 0 === $row ) {
				$field_keys = array_map( 'trim', $data );
				// TODO: bail if required fields not found
			} else {
				$row_data    = array_map( 'trim', $data );
				$author_data = array();
				foreach ( $row_data as $col_num => $val ) {
						// Don't use the value of the field key isn't set
					if ( empty( $field_keys[ $col_num ] ) ) {
						continue;
					}
					$author_data[ $field_keys[ $col_num ] ] = $val;
				}

				$authors[] = $author_data;
			}
			$row++;
		}
		fclose( $file );

		WP_CLI::log( 'Found ' . count( $authors ) . ' authors in CSV' );

		foreach ( $authors as $author ) {
			WP_CLI::log( sprintf( 'Processing author %s (%s)', $author['user_login'], $author['user_email'] ) );

			$guest_author_data = array(
				'display_name' => sanitize_text_field( $author['display_name'] ),
				'user_login'   => sanitize_user( $author['user_login'] ),
				'user_email'   => sanitize_email( $author['user_email'] ),
				'website'      => esc_url_raw( $author['website'] ),
				'description'  => wp_filter_post_kses( $author['description'] ),
				'avatar'       => absint( $author['avatar'] ),
			);

			$display_name_space_pos = strpos( $author['display_name'], ' ' );

			if ( false !== $display_name_space_pos && empty( $author['first_name'] ) && empty( $author['last_name'] ) ) {
				$first_name = substr( $author['display_name'], 0, $display_name_space_pos );
				$last_name  = substr( $author['display_name'], ( $display_name_space_pos + 1 ) );

				$guest_author_data['first_name'] = sanitize_text_field( $first_name );
				$guest_author_data['last_name']  = sanitize_text_field( $last_name );
			} elseif ( ! empty( $author['first_name'] ) && ! empty( $author['last_name'] ) ) {
				$guest_author_data['first_name'] = sanitize_text_field( $author['first_name'] );
				$guest_author_data['last_name']  = sanitize_text_field( $author['last_name'] );
			}

			$this->create_guest_author( $guest_author_data );
		}

		WP_CLI::log( 'All done!' );
	}

	/**
	 * Helper function to create a guest author.
	 *
	 * @param $author array author args. Required: display_name, user_login
	 * @return void
	 */
	private function create_guest_author( $author ): void {
		global $coauthors_plus;

		$guest_author = false;

		if ( ! empty( $author['user_email'] ) ) {
			$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'user_email', $author['user_email'], true );
		}

		if ( ! $guest_author && ! empty( $author['user_login'] ) ) {
			$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'user_login', $author['user_login'], true );
		}

		if ( $guest_author ) {
			/* translators: Guest Author ID. */
			WP_CLI::warning( sprintf( esc_html__( '-- Author already exists (ID #%s); skipping.', 'co-authors-plus' ), $guest_author->ID ) );
			return;
		}

		WP_CLI::log( esc_html__( '-- Not found; creating profile.', 'co-authors-plus' ) );

		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => $author['display_name'],
				'user_login'   => $author['user_login'],
				'user_email'   => $author['user_email'],
				'first_name'   => $author['first_name'],
				'last_name'    => $author['last_name'],
				'website'      => $author['website'],
				'description'  => $author['description'],
				'avatar'       => $author['avatar'],
			)
		);

		if ( is_wp_error( $guest_author_id ) ) {
			/* translators: The error message. */
			WP_CLI::warning( sprintf( esc_html__( '-- Failed to create guest author: %s', 'co-authors-plus' ), $guest_author_id->get_error_message() ) );
			return;
		}

		if ( isset( $author['author_id'] ) ) {
			update_post_meta( $guest_author_id, '_original_author_id', $author['ID'] );
		}

		update_post_meta( $guest_author_id, '_original_author_login', $author['user_login'] );

		/* translators: Guest Author ID. */
		WP_CLI::success( sprintf( esc_html__( '-- Created as guest author #%s', 'co-authors-plus' ), $guest_author_id ) );
	}

	/**
	 * Clear all the caches for memory management.
	 */
	private function stop_the_insanity(): void {
		global $wpdb, $wp_object_cache;

		$wpdb->queries = array(); // or define( 'WP_IMPORTING', true );

		if ( ! is_object( $wp_object_cache ) ) {
			return;
		}

		$wp_object_cache->group_ops      = array();
		$wp_object_cache->stats          = array();
		$wp_object_cache->memcache_debug = array();
		$wp_object_cache->cache          = array();

		if ( is_callable( $wp_object_cache, '__remoteset' ) ) {
			$wp_object_cache->__remoteset(); // important
		}
	}

	/**
	 * Obtains the raw SQL for posts that are missing a specific term.
	 *
	 * @param string   $author_taxonomy The author taxonomy to search for.
	 * @param string[] $post_types The post types to search for.
	 * @param string[] $post_statuses The post statuses to search for.
	 * @param int[]    $specific_post_ids The specific post IDs to search for.
	 * @param int|null $above_post_id The post ID to start from.
	 * @param int|null $below_post_id The post ID to end at.
	 *
	 * @return array
	 * @throws Exception If the $above_post_id is greater than or equal to the $below_post_id.
	 */
	private function get_sql_for_posts_with_missing_terms( $author_taxonomy, $post_types = [ 'post' ], $post_statuses = [ 'publish' ], $specific_post_ids = [], $above_post_id = null, $below_post_id = null ) {
		global $wpdb;

		$sql_and_args = [
			'sql'  => '',
			'args' => [ $author_taxonomy, self::SKIP_POST_FOR_BACKFILL_META_KEY ],
		];

		$post_status_placeholder = implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) );
		$sql_and_args['args']    = array_merge( $post_statuses, $sql_and_args['args'] );
		$post_types_placeholder  = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$sql_and_args['args']    = array_merge( $post_types, $sql_and_args['args'] );

		$from = $wpdb->posts;

		$specific_id_constraint = '';

		if ( ! empty( $specific_post_ids ) ) {
			$specific_post_ids_placeholder = implode( ',', array_fill( 0, count( $specific_post_ids ), '%d' ) );
			$specific_id_constraint        = "AND ID IN ( $specific_post_ids_placeholder )";
			$sql_and_args['args']          = array_merge( $sql_and_args['args'], $specific_post_ids );
		} elseif ( null !== $above_post_id || null !== $below_post_id ) {
			if ( null !== $above_post_id && null !== $below_post_id && ( $below_post_id <= $above_post_id ) ) {
				throw new Exception( 'The $above_post_id param must be less than the $below_post_id param.' );
			}

			$ids_between_constraint = [];

			if ( null !== $above_post_id ) {
				array_unshift( $ids_between_constraint, 'ID > %d' );
				array_unshift( $sql_and_args['args'], $above_post_id );
			}

			if ( null !== $below_post_id ) {
				array_unshift( $ids_between_constraint, 'ID < %d' );
				array_unshift( $sql_and_args['args'], $below_post_id );
			}

			$from = "( SELECT * FROM $wpdb->posts WHERE " . implode( ' AND ', $ids_between_constraint ) . ' ) as sub';
		}

		$sql_and_args['sql'] = "SELECT
				ID as post_id,
				post_author
			FROM $from
			WHERE post_type IN ( $post_types_placeholder )
			  AND post_status IN ( $post_status_placeholder )
			  AND post_author <> 0
			  AND ID NOT IN (
			  	SELECT
			  	    tr.object_id
			  	FROM $wpdb->term_relationships tr
			  	    LEFT JOIN $wpdb->term_taxonomy tt
			  	        ON tr.term_taxonomy_id = tt.term_taxonomy_id
			  	WHERE tt.taxonomy = %s
			  	GROUP BY tr.object_id
			  	)
			  AND ID NOT IN (
			      SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s
			  )
			  $specific_id_constraint
			ORDER BY ID";

		return $sql_and_args;
	}

	/**
	 * Obtains the count of posts that are missing a specific term.
	 *
	 * @param string   $author_taxonomy The author taxonomy to search for.
	 * @param string[] $post_types The post types to search for.
	 * @param string[] $post_statuses The post statuses to search for.
	 * @param int[]    $specific_post_ids The specific post IDs to search for.
	 * @param int|null $above_post_id The post ID to start from.
	 * @param int|null $below_post_id The post ID to end at.
	 *
	 * @return int
	 * @throws Exception If the $above_post_id is greater than or equal to the $below_post_id.
	 */
	private function get_count_of_posts_with_missing_terms( $author_taxonomy, $post_types = [ 'post' ], $post_statuses = [ 'publish' ], $specific_post_ids = [], $above_post_id = null, $below_post_id = null ) {
		global $wpdb;

		[
			$sql,
			$args,
		] = array_values( $this->get_sql_for_posts_with_missing_terms( $author_taxonomy, $post_types, $post_statuses, $specific_post_ids, $above_post_id, $below_post_id ) );

		// Replace the first SELECT with SELECT COUNT(*).
		$sql = preg_replace(
			'/^(SELECT(?s)(.*?)FROM)/',
			'SELECT COUNT(*) FROM',
			$sql,
			1
		);

		// phpcs:disable -- Query is properly prepared
		return intval( $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) );
		// phpcs:enable
	}

	/**
	 * Obtains posts that are missing a specific term.
	 *
	 * @param string   $author_taxonomy The author taxonomy to search for.
	 * @param string[] $post_types The post types to search for.
	 * @param string[] $post_statuses The post statuses to search for.
	 * @param bool     $batched Whether to process the records in batches.
	 * @param int      $records_per_batch The number of posts to retrieve per page.
	 * @param int[]    $specific_post_ids The specific post IDs to search for.
	 * @param int|null $above_post_id The post ID to start from.
	 * @param int|null $below_post_id The post ID to end at.
	 *
	 * @return array
	 * @throws Exception If the $above_post_id is greater than or equal to the $below_post_id.
	 */
	private function get_posts_with_missing_terms( $author_taxonomy, $post_types = [ 'post' ], $post_statuses = [ 'publish' ], $batched = false, $records_per_batch = 250, $specific_post_ids = [], $above_post_id = null, $below_post_id = null ) {
		global $wpdb;

		[
			$sql,
			$args,
		] = array_values( $this->get_sql_for_posts_with_missing_terms( $author_taxonomy, $post_types, $post_statuses, $specific_post_ids, $above_post_id, $below_post_id ) );

		if ( $batched ) {
			$sql .= " LIMIT $records_per_batch";
		}

		// phpcs:disable -- Query is properly prepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable
	}

	/**
	 * This function will insert a postmeta row for posts that should be skipped for processing in the author term
	 * backfill command ('create-author-terms-for-posts' or function name `create_author_terms_for_posts`).
	 *
	 * @param int    $post_id The Post ID that needs to be skipped.
	 * @param string $reason The reason the post needs to be skipped.
	 *
	 * @return void;
	 */
	private function skip_backfill_for_post( $post_id, $reason ) {
		add_post_meta( $post_id, self::SKIP_POST_FOR_BACKFILL_META_KEY, $reason, true );
	}

	/**
	 * Convenience function to generate a formatted percentage string.
	 *
	 * @param int $completed Number of completed cycles.
	 * @param int $total Total number of cycles.
	 *
	 * @return string
	 */
	private function get_formatted_complete_percentage( $completed, $total ) {
		return number_format( ( $completed / $total ) * 100, 2 ) . '%';
	}
}
