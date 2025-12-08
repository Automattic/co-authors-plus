<?php
/**
 * @package Automattic\CoAuthorsPlus
 */

class CoAuthors_Plus {

	// Name for the taxonomy we're using to store relationships
	// and the post type we're using to store guest authors
	public $coauthor_taxonomy = 'author';

	public $coreauthors_meta_box_name = 'authordiv';
	public $coauthors_meta_box_name   = 'coauthorsdiv';
	public $force_guest_authors       = false;

	public $_pages_whitelist = array( 'post.php', 'post-new.php', 'edit.php' );

	public $supported_post_types = array();

	public $ajax_search_fields = array( 'display_name', 'first_name', 'last_name', 'user_login', 'ID', 'user_email' );

	public $having_terms = '';

	public $to_be_filtered_caps = array();

	/**
	 * @var CoAuthors_Guest_Authors
	 */
	public $guest_authors;

	/**
	 * __construct()
	 */
	public function __construct() {

		// Register our models
		add_action( 'init', array( $this, 'action_init' ) );
		add_action( 'init', array( $this, 'action_init_late' ), 100 );

		// Load admin_init function
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		// Modify SQL queries to include guest authors
		add_filter( 'posts_where', array( $this, 'posts_where_filter' ), 10, 2 );
		add_filter( 'posts_join', array( $this, 'posts_join_filter' ), 10, 2 );
		add_filter( 'posts_groupby', array( $this, 'posts_groupby_filter' ), 10, 2 );

		// Action to set co-authors when a post is saved
		add_action( 'save_post', array( $this, 'coauthors_update_post' ), 10, 2 );
		// Filter to set the post_author field when wp_insert_post is called
		add_filter( 'wp_insert_post_data', array( $this, 'coauthors_set_post_author_field' ), 10, 2 );

		// Action to reassign posts when a guest author is deleted
		add_action( 'delete_user', array( $this, 'delete_user_action' ) );

		add_filter( 'get_usernumposts', array( $this, 'filter_count_user_posts' ), 10, 2 );

		// Action to set up co-author auto-suggest
		add_action( 'wp_ajax_coauthors_ajax_suggest', array( $this, 'ajax_suggest' ) );

		// Filter to allow co-authors to edit posts
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 3 );

		// Handle the custom co-author meta box
		add_action( 'add_meta_boxes', array( $this, 'add_coauthors_box' ) );
		add_action( 'add_meta_boxes', array( $this, 'remove_authors_box' ) );

		// Refresh the nonce after the user re-authenticates due to a wp_auth_check() to avoid failing check_admin_referrer()
		add_action( 'wp_refresh_nonces', array( $this, 'refresh_coauthors_nonce' ), 20 );

		// Removes the co-author dropdown from the post quick edit
		add_action( 'admin_head', array( $this, 'remove_quick_edit_authors_box' ) );

		// Restricts WordPress from blowing away term order on bulk edit
		add_filter( 'wp_get_object_terms', array( $this, 'filter_wp_get_object_terms' ), 10, 4 );

		// Make sure we've correctly set data on guest author pages
		add_action( 'posts_selection', array( $this, 'fix_author_page' ) ); // Use posts_selection since it's after WP_Query has built the request, and before it's queried any posts.
		add_action( 'the_post', array( $this, 'fix_author_page' ) );

		// Support for Edit Flow's calendar and story budget
		add_filter( 'ef_calendar_item_information_fields', array( $this, 'filter_ef_calendar_item_information_fields' ), 10, 2 );
		add_filter( 'ef_story_budget_term_column_value', array( $this, 'filter_ef_story_budget_term_column_value' ), 10, 3 );

		// Support Jetpack Open Graph Tags
		add_filter( 'jetpack_open_graph_tags', array( $this, 'filter_jetpack_open_graph_tags' ), 10, 2 );

		// Filter to send comment moderation notification e-mail to multiple co-authors
		add_filter( 'comment_moderation_recipients', 'cap_filter_comment_moderation_email_recipients', 10, 2 );

		// Support infinite scroll for Guest Authors on author pages
		add_filter( 'infinite_scroll_js_settings', array( $this, 'filter_infinite_scroll_js_settings' ), 10, 2 );

		// Delete Co-Author Cache on Post Save & Post Delete
		add_action( 'save_post', array( $this, 'clear_cache' ) );
		add_action( 'delete_post', array( $this, 'clear_cache' ) );
		add_action( 'set_object_terms', array( $this, 'clear_cache_on_terms_set' ), 10, 6 );

		// Filter to correct author on author archive page
		add_filter( 'get_the_archive_title', array( $this, 'filter_author_archive_title' ) );

		// Filter to display author image if exists instead of avatar
		add_filter( 'pre_get_avatar_data', array( $this, 'filter_pre_get_avatar_data_url' ), 10, 2 );

		// Block editor assets for the sidebar plugin.
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_sidebar_plugin_assets' ) );

		// REST API: Depending on user capabilities, hide author term description.
		add_action( 'rest_prepare_author', array( $this, 'conditionally_hide_author_term_description' ) );

		// Add Bulk Edit support on supported versions of WordPress.
		global $wp_version;
		if ( version_compare( $wp_version, '6.3', '>=' ) ) {
			// Add Co-Author select field to the Bulk Edit actions form.
			add_action( 'bulk_edit_custom_box', array( $this, '_action_bulk_edit_custom_box' ), 10, 2 );

			// Update Co-Authors when bulk editing posts.
			add_action( 'bulk_edit_posts', array( $this, 'action_bulk_edit_update_coauthors' ), 10, 2 );
		}
	}

	/**
	 * Register the taxonomy used to managing relationships,
	 * and the custom post type to store our author data
	 */
	public function action_init(): void {

		// Load the Guest Authors functionality if needed
		if ( $this->is_guest_authors_enabled() ) {
			require_once dirname( COAUTHORS_PLUS_FILE ) . '/php/class-coauthors-guest-authors.php';
			$this->guest_authors = new CoAuthors_Guest_Authors();
			if ( apply_filters( 'coauthors_guest_authors_force', false ) ) {
				$this->force_guest_authors = true;
			}
		}

		// Maybe automatically apply our template tags
		if ( apply_filters( 'coauthors_auto_apply_template_tags', false ) ) {
			global $coauthors_plus_template_filters;
			$coauthors_plus_template_filters = new CoAuthors_Template_Filters();
		}

	}

	/**
	 * Determine if block editor sidebar integration should be loaded.
	 *
	 * @param WP_Post|int|null $post Post ID or object, null to use global.
	 * @return bool
	 */
	public function is_block_editor( $post = null ): bool {
		$screen = get_current_screen();

		// Pre-5.0 compatibility
		if ( method_exists( $screen, 'is_block_editor' ) ) {
			return $screen->is_block_editor();
		}

		return false;
	}

	/**
	 * When filter is set to enable block editor integration, enqueue assets
	 * for posts and users where Co Authors is enabled
	 */
	public function enqueue_sidebar_plugin_assets(): void {
		if ( $this->is_post_type_enabled() && $this->current_user_can_set_authors() ) {
			$asset = require dirname( COAUTHORS_PLUS_FILE ) . '/build/index.asset.php';

			wp_register_script(
				'coauthors-sidebar-js',
				plugins_url( 'build/index.js', COAUTHORS_PLUS_FILE ),
				$asset['dependencies'],
				$asset['version']
			);

			wp_register_style(
				'coauthors-sidebar-css',
				plugins_url( 'build/style-index.css', COAUTHORS_PLUS_FILE ),
				'',
				$asset['version']
			);

			wp_set_script_translations(
				'coauthors-sidebar-js',
				'co-authors-plus',
				dirname( COAUTHORS_PLUS_FILE ) . '/languages'
			);

			wp_enqueue_script( 'coauthors-sidebar-js' );
			wp_enqueue_style( 'coauthors-sidebar-css' );
		}
	}

	/**
	 * Register the 'author' taxonomy and add post type support
	 */
	public function action_init_late(): void {

		// Register new taxonomy so that we can store all the relationships.
		$args = array(
			'hierarchical' => false,
			'labels'       => array(
				'name'      => __( 'Authors', 'co-authors-plus' ),
				'all_items' => __( 'All Authors', 'co-authors-plus' ),
			),
			'query_var'    => false,
			'rewrite'      => false,
			'public'       => false,
			'sort'         => true,
			'args'         => array( 'orderby' => 'term_order' ),
			'show_ui'      => false,
			'show_in_rest' => true,
			'rest_base'    => 'coauthors',
		);

		// If we use the nasty SQL query, we need our custom callback. Otherwise, we still need to flush cache.
		if ( apply_filters( 'coauthors_plus_should_query_post_author', true ) ) {
			$args['update_count_callback'] = array( $this, '_update_users_posts_count' );
		} else {
			add_action( 'edited_term_taxonomy', array( $this, 'action_edited_term_taxonomy_flush_cache' ), 10, 2 );
		}

		register_taxonomy( $this->coauthor_taxonomy, $this->supported_post_types(), $args );
	}

	/**
	 * Initialize the plugin for the admin
	 */
	public function admin_init(): void {
		global $pagenow;

		// Add the main JS script and CSS file
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		// Add necessary JS variables
		add_action( 'admin_head', array( $this, 'js_vars' ) );

		// Hooks to add additional co-authors to 'authors' column to edit page
		add_filter( 'manage_posts_columns', array( $this, '_filter_manage_posts_columns' ) );
		add_filter( 'manage_pages_columns', array( $this, '_filter_manage_posts_columns' ) );
		add_action( 'manage_posts_custom_column', array( $this, '_filter_manage_posts_custom_column' ) );
		add_action( 'manage_pages_custom_column', array( $this, '_filter_manage_posts_custom_column' ) );

		// Add quick-edit co-author select field
		add_action( 'quick_edit_custom_box', array( $this, '_action_quick_edit_custom_box' ), 10, 2 );

		// Hooks to modify the published post number count on the Users WP List Table
		add_filter( 'manage_users_columns', array( $this, '_filter_manage_users_columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, '_filter_manage_users_custom_column' ), 10, 3 );

		// Apply some targeted filters
		add_action( 'load-edit.php', array( $this, 'load_edit' ) );
		add_action( 'load-users.php', array( $this, 'load_users_screen' ) );
	}

	/**
	 * Get the list of supported post types.
	 *
	 * By default, this is the built-in and custom post types that have authors.
	 *
	 * @since 3.6.0
	 *
	 * @return array Supported post types.
	 */
	public function supported_post_types(): array {
		$post_types = array_values( get_post_types() );

		$excluded_built_in = array(
			'revision',
			'attachment',
			'customize_changeset',
			'wp_template',
			'wp_template_part',
		);

		foreach ( $post_types as $key => $name ) {
			if ( ! post_type_supports( $name, 'author' ) || in_array( $name, $excluded_built_in, true ) ) {
				unset( $post_types[ $key ] );
			}
		}

		/**
		 * Filter the list of supported post types.
		 *
		 * @param array $post_types Post types.
		 */
		$supported_post_types = (array) apply_filters( 'coauthors_supported_post_types', $post_types );

		// Set class property for back-compat.
		$this->supported_post_types = $supported_post_types;

		return $supported_post_types;
	}

	/**
	 * Check whether the guest authors functionality is enabled or not
	 * Guest authors can be disabled entirely with:
	 *     add_filter( 'coauthors_guest_authors_enabled', '__return_false' )
	 *
	 * @since 3.0
	 *
	 * @return bool
	 */
	public function is_guest_authors_enabled(): bool {
		return apply_filters( 'coauthors_guest_authors_enabled', true );
	}

	/**
	 * Get a guest author object by a specific type of key
	 *
	 * @param string $key Key to search by (slug,email)
	 * @param string $value Value to search for
	 * @return object|false The co-author on success, false on failure
	 */
	public function get_coauthor_by( $key, $value, $force = false ) {

		// If Guest Authors are enabled, prioritize those profiles
		if ( $this->is_guest_authors_enabled() && isset( $this->guest_authors ) ) {
			$guest_author = $this->guest_authors->get_guest_author_by( $key, $value, $force );
			if ( is_object( $guest_author ) ) {
				if ( isset( $guest_author->linked_account ) ) {
					$user = $this->get_user_by( 'login', $guest_author->linked_account );

					if ( null !== $user ) {
						$guest_author->wp_user = $user;
					}
				}

				return $guest_author;
			} else {
				// Guest Author was not found, so let's see if we are searching for a WP_User.
				$user = $this->get_user_by( $key, $value );

				if ( null === $user ) {
					return false;
				}

				// At this point we have a valid $user.
				$user->type = 'wpuser';

				$guest_author = $this->guest_authors->get_guest_author_by( 'linked_account', $user->user_login );
				if ( is_object( $guest_author ) ) {
					$guest_author->wp_user = $user;
					$user                  = $guest_author;
				}

				return $user;
			}
		} else {
			$user = $this->get_user_by( $key, $value );

			if ( null === $user ) {
				return false;
			}

			$user->type = 'wpuser';

			return $user;
		}
	}

	/**
	 * Searches for authors by way of the WP_User table using a specific list of data points. If login or slug
	 * are provided as search parameters, this function will remove `cap-` from the search value, if present.
	 *
	 * @param string $key Key to search by, i.e. 'id', 'login', 'user_login', 'email', 'user_email', 'user_nicename'.
	 * @param string $value Value to search for.
	 *
	 * @return WP_User|null
	 */
	protected function get_user_by( $key, $value ) {
		$acceptable_keys = [
			'id'            => 'id',
			'login'         => 'login',
			'user_login'    => 'login',
			'email'         => 'email',
			'user_email'    => 'email',
			'user_nicename' => 'slug',
		];

		if ( ! array_key_exists( $key, $acceptable_keys ) ) {
			return null;
		}

		$key = $acceptable_keys[ $key ];

		$user = get_user_by( $key, $value );

		if ( ! $user && ( 'login' === $key || 'slug' === $key ) ) {
			// Re-try lookup without prefixed value if no results found.
			$value = preg_replace( '#^cap\-#', '', $value );
			$user  = get_user_by( $key, $value );
		}

		if ( false === $user ) {
			return null;
		}

		return $user;
	}

	/**
	 * Whether Co-Authors Plus is enabled for this post type.
	 * Must be called after init
	 *
	 * @since 3.0
	 *
	 * @param string $post_type The name of the post type we're considering
	 * @return bool Whether co-authors are enabled for the post type.
	 */
	public function is_post_type_enabled( $post_type = null ): bool {

		if ( ! $post_type ) {
			$post_type = get_post_type();
			if ( ! $post_type && is_admin() ) {
				$post_type = get_current_screen()->post_type;
			}
		}

		return in_array( $post_type, $this->supported_post_types() );
	}

	/**
	 * Removes the standard WordPress 'Author' box.
	 * We don't need it because the Co-Authors Plus one is way cooler.
	 */
	public function remove_authors_box(): void {

		if ( $this->is_post_type_enabled() ) {
			remove_meta_box( $this->coreauthors_meta_box_name, get_post_type(), 'normal' );
		}
	}

	/**
	 * Adds a custom 'Authors' box
	 */
	public function add_coauthors_box(): void {
		if ( $this->is_post_type_enabled() && $this->current_user_can_set_authors() ) {
			if ( false === $this->is_block_editor() ) {
				add_meta_box( $this->coauthors_meta_box_name, apply_filters( 'coauthors_meta_box_title', __( 'Authors', 'co-authors-plus' ) ), array( $this, 'coauthors_meta_box' ), get_post_type(), apply_filters( 'coauthors_meta_box_context', 'side' ), apply_filters( 'coauthors_meta_box_priority', 'high' ) );
			}
		}
	}

	/**
	 * Callback for adding the custom 'Authors' box
	 */
	public function coauthors_meta_box( $post ): void {
		global $post, $coauthors_plus, $current_screen;

		$post_id = $post->ID;

		$default_user = apply_filters( 'coauthors_default_author', wp_get_current_user() );

		// @daniel, $post_id and $post->post_author are always set when a new post is created due to auto draft,
		// and the else case below was always able to properly assign users based on wp_posts.post_author,
		// but that's not possible with force_guest_authors = true.
		if ( ! $post_id || ( ! $post->post_author && ! $coauthors_plus->force_guest_authors ) || ( 'post' === $current_screen->base && 'add' === $current_screen->action ) ) {
			$coauthors = array();
			// If guest authors is enabled, try to find a guest author attached to this user ID
			if ( $this->is_guest_authors_enabled() ) {
				$coauthor = $coauthors_plus->guest_authors->get_guest_author_by( 'linked_account', $default_user->user_login );
				if ( $coauthor ) {
					$coauthors[] = $coauthor;
				}
			}
			// If the above block was skipped, or if it failed to find a guest author, use the current
			// logged-in user, so long as force_guest_authors is false. If force_guest_authors = true, we are
			// OK with having an empty authoring box.
			if ( ! $coauthors_plus->force_guest_authors && empty( $coauthors ) ) {
				if ( is_array( $default_user ) ) {
					$coauthors = $default_user;
				} else {
					$coauthors[] = $default_user;
				}
			}
		} else {
			$coauthors = get_coauthors();
		}

		$count = 0;
		if ( ! empty( $coauthors ) ) :
			?>
			<div id="coauthors-readonly" class="hide-if-js">
				<ul>
				<?php
				foreach ( $coauthors as $coauthor ) :
					$count++;
					$user_type = 'guest-user';
					if ( $coauthor instanceof WP_User ) {
						$user_type = 'wp-user';
					}
					$avatar_url = get_avatar_url( $coauthor->ID, array( 'user_type' => $user_type ) );
					?>
					<li>
						<?php echo get_avatar( $coauthor->ID ); ?>
						<span id="<?php echo esc_attr( 'coauthor-readonly-' . $count ); ?>" class="coauthor-tag">
							<input type="text" name="coauthorsinput[]" readonly="readonly" value="<?php echo esc_attr( $coauthor->display_name ); ?>" />
							<input type="text" name="coauthors[]" value="<?php echo esc_attr( $coauthor->user_login ); ?>" />
							<input type="text" name="coauthorsemails[]" value="<?php echo esc_attr( $coauthor->user_email ); ?>" />
							<input type="text" name="coauthorsnicenames[]" value="<?php echo esc_attr( $coauthor->user_nicename ); ?>" />
							<input type="hidden" name="coauthorsavatars[]" value="<?php echo esc_url( $avatar_url ); ?>" />
						</span>
					</li>
					<?php
				endforeach;
				?>
				</ul>
				<div class="clear"></div>
				<p><?php echo wp_kses( __( '<strong>Note:</strong> To edit post authors, please enable JavaScript or use a JavaScript-capable browser', 'co-authors-plus' ), array( 'strong' => array() ) ); ?></p>
			</div>
			<?php
		endif;
		?>

		<div id="coauthors-edit" class="hide-if-no-js">
			<p><?php echo wp_kses( __( 'Click on an author to change them. Drag to change their order. Click on <strong>Remove</strong> to remove them.', 'co-authors-plus' ), array( 'strong' => array() ) ); ?></p>
		</div>

		<?php wp_nonce_field( 'coauthors-edit', 'coauthors-nonce' ); ?>

		<?php
	}

	/**
	 * Filters the Heartbeat response to refresh the coauthors-nonce
	 */
	public function refresh_coauthors_nonce( $response ): array {
		$response['wp-refresh-post-nonces']['replace']['coauthors-nonce']  = wp_create_nonce( 'coauthors-edit' );

		return $response;
	}

	/**
	 * Removes the default 'author' dropdown from quick edit.
	 */
	public function remove_quick_edit_authors_box(): void {
		global $pagenow;

		if ( 'edit.php' === $pagenow && $this->is_post_type_enabled() ) {
			/*
			 * The author dropdown isn't displayed if wp_dropdown_users( $args ) returns an empty string.
			 * It will return an empty string if the user query returns an empty array.
			 * We can force it return an empty array by changing $args to include only the user ID 0 which doesn't exist.
			 * We can target the $args specific to Quick Edit using the filter quick_edit_dropdown_authors_args.
			 * See https://github.com/Automattic/Co-Authors-Plus/issues/1033.
			 */
			add_filter(
				'quick_edit_dropdown_authors_args',
				static fn() => [ 'include' => [ 0 ] ]
			);
		}
	}

	/**
	 * Add co-authors to 'authors' column on edit pages
	 *
	 * @param array $post_columns
	 */
	public function _filter_manage_posts_columns( $posts_columns ) {

		$new_columns = array();
		if ( ! $this->is_post_type_enabled() ) {
			return $posts_columns;
		}

		foreach ( $posts_columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'title' === $key ) {
				$new_columns['coauthors'] = __( 'Authors', 'co-authors-plus' );
			}

			if ( $this->coauthor_taxonomy === $key ) {
				unset( $new_columns[ $key ] );
			}
		}
		return $new_columns;
	}

	/**
	 * Insert co-authors into post rows on Edit Page
	 *
	 * @param string $column_name
	 */
	public function _filter_manage_posts_custom_column( $column_name ): void {
		if ( 'coauthors' === $column_name ) {
			global $post;
			$authors = get_coauthors( $post->ID );

			$count = 1;
			foreach ( $authors as $author ) :
				$args = array(
					'author_name' => $author->user_nicename,
				);
				if ( 'post' !== $post->post_type ) {
					$args['post_type'] = $post->post_type;
				}
				$author_filter_url = add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'edit.php' ) );
				?>
				<a href="<?php echo esc_url( $author_filter_url ); ?>"
				data-user_nicename="<?php echo esc_attr( $author->user_nicename ); ?>"
				data-user_email="<?php echo esc_attr( $author->user_email ); ?>"
				data-display_name="<?php echo esc_attr( $author->display_name ); ?>"
				data-user_login="<?php echo esc_attr( $author->user_login ); ?>"
				data-avatar="<?php echo esc_attr( get_avatar_url( $author->ID ) ); ?>"
				><?php echo esc_html( $author->display_name ); ?></a><?php echo ( $count < count( $authors ) ) ? ',' : ''; ?>
				<?php
				$count++;
			endforeach;
		}
	}

	/**
	 * Unset the post count column because it's going to be inaccurate and provide our own
	 */
	public function _filter_manage_users_columns( $columns ): array {

		$new_columns = array();
		// Unset and add our column while retaining the order of the columns
		foreach ( $columns as $column_name => $column_title ) {
			if ( 'posts' === $column_name ) {
				$new_columns['coauthors_post_count'] = __( 'Posts', 'co-authors-plus' );
			} else {
				$new_columns[ $column_name ] = $column_title;
			}
		}
		return $new_columns;
	}

	/**
	 * Provide an accurate count when looking up the number of published posts for a user
	 */
	public function _filter_manage_users_custom_column( $value, $column_name, $user_id ) {
		if ( 'coauthors_post_count' !== $column_name ) {
			return $value;
		}
		// We filter count_user_posts() so it provides an accurate number
		$numposts = count_user_posts( $user_id ); // phpcs:ignore
		$user     = get_user_by( 'id', $user_id );
		if ( $numposts > 0 ) {
			$value .= "<a href='edit.php?author_name=$user->user_nicename' title='" . esc_attr__( 'View posts by this author', 'co-authors-plus' ) . "' class='edit'>";
			$value .= $numposts;
			$value .= '</a>';
		} else {
			$value .= 0;
		}
		return $value;
	}

	/**
	 * Quick Edit co-authors box.
	 */
	public function _action_quick_edit_custom_box( $column_name, $post_type ): void {
		if ( 'coauthors' !== $column_name || ! $this->is_post_type_enabled( $post_type ) || ! $this->current_user_can_set_authors() ) {
			return;
		}
		?>
		<label class="inline-edit-group inline-edit-coauthors">
			<span class="title"><?php esc_html_e( 'Authors', 'co-authors-plus' ); ?></span>
			<div id="coauthors-edit" class="hide-if-no-js">
				<p><?php echo wp_kses( __( 'Click on an author to change them. Drag to change their order. Click on <strong>Remove</strong> to remove them.', 'co-authors-plus' ), array( 'strong' => array() ) ); ?></p>
			</div>
			<?php wp_nonce_field( 'coauthors-edit', 'coauthors-nonce' ); ?>
		</label>
		<?php
	}

	/**
	 * When we update the terms at all, we should update the published post count for each user
	 */
	public function _update_users_posts_count( $tt_ids, $taxonomy ): void {
		global $wpdb;

		$tt_ids   = implode( ', ', array_map( 'intval', $tt_ids ) );
		$term_ids = $wpdb->get_results( "SELECT term_id FROM $wpdb->term_taxonomy WHERE term_taxonomy_id IN ($tt_ids)" ); // phpcs:ignore

		foreach ( (array) $term_ids as $term_id_result ) {
			$term = get_term_by( 'id', $term_id_result->term_id, $this->coauthor_taxonomy );
			$this->update_author_term_post_count( $term );
		}
		$tt_ids = explode( ', ', $tt_ids );
		clean_term_cache( $tt_ids, '', false );

	}

	/**
	 * If we're forcing Co-Authors Plus to just do taxonomy queries, we still
	 * need to flush our special cache after a taxonomy term has been updated
	 *
	 * @since 3.1
	 */
	public function action_edited_term_taxonomy_flush_cache( $tt_id, $taxonomy ) {
		global $wpdb;

		if ( $this->coauthor_taxonomy != $taxonomy ) {
			return;
		}

		$term_id = $wpdb->get_results( $wpdb->prepare( "SELECT term_id FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d ", $tt_id ) );

		$term     = get_term_by( 'id', $term_id[0]->term_id, $taxonomy );
		$coauthor = $this->get_coauthor_by( 'user_nicename', $term->slug );
		if ( ! $coauthor ) {
			return new WP_Error( 'missing-coauthor', __( 'No co-author exists for that term', 'co-authors-plus' ) );
		}

		wp_cache_delete( 'author-term-' . $coauthor->user_nicename, 'co-authors-plus' );
	}

	/**
	 * Update the post count associated with an author term
	 *
	 * @since 3.0
	 *
	 * @param object $term The co-author term
	 */
	public function update_author_term_post_count( $term ) {
		global $wpdb;

		$coauthor = $this->get_coauthor_by( 'user_nicename', $term->slug );
		if ( ! $coauthor ) {
			return new WP_Error( 'missing-coauthor', __( 'No co-author exists for that term', 'co-authors-plus' ) );
		}

		$query = "SELECT COUNT({$wpdb->posts}.ID) FROM {$wpdb->posts}";

		$query .= " LEFT JOIN {$wpdb->term_relationships} ON ({$wpdb->posts}.ID = {$wpdb->term_relationships}.object_id)";
		$query .= " LEFT JOIN {$wpdb->term_taxonomy} ON ( {$wpdb->term_relationships}.term_taxonomy_id = {$wpdb->term_taxonomy}.term_taxonomy_id )";

		$having_terms_and_authors = $having_terms = $wpdb->prepare( "{$wpdb->term_taxonomy}.term_id = %d", $term->term_id );
		if ( 'wpuser' === $coauthor->type ) {
			$having_terms_and_authors .= $wpdb->prepare( " OR {$wpdb->posts}.post_author = %d", $coauthor->ID );
		}

		$post_types = apply_filters( 'coauthors_count_published_post_types', array( 'post' ) );
		$post_types = array_map( 'sanitize_key', $post_types );
		$post_types = "'" . implode( "','", $post_types ) . "'";

		$query .= " WHERE ({$having_terms_and_authors}) AND {$wpdb->posts}.post_type IN ({$post_types}) AND {$wpdb->posts}.post_status = 'publish'";

		$query .= $wpdb->prepare( " GROUP BY {$wpdb->posts}.ID HAVING MAX( IF ( {$wpdb->term_taxonomy}.taxonomy = '%s', IF ( {$having_terms},2,1 ),0 ) ) <> 1 ", $this->coauthor_taxonomy ); //phpcs:ignore

		$count = $wpdb->query( $query ); // phpcs:ignore
		$wpdb->update( $wpdb->term_taxonomy, array( 'count' => $count ), array( 'term_taxonomy_id' => $term->term_taxonomy_id ) );

		wp_cache_delete( 'author-term-' . $coauthor->user_nicename, 'co-authors-plus' );
	}

	/**
	 * Modify the author query posts SQL to include posts co-authored
	 */
	public function posts_join_filter( $join, $query ) {
		global $wpdb;

		if ( $query->is_author() ) {
			$post_type = $query->query_vars['post_type'];
			if ( 'any' === $post_type ) {
				$post_type = get_post_types( array( 'exclude_from_search' => false ) );
			}

			if ( ! empty( $post_type ) && ! is_object_in_taxonomy( $post_type, $this->coauthor_taxonomy ) ) {
				return $join;
			}

			if ( empty( $this->having_terms ) ) {
				return $join;
			}

			// Check to see that JOIN hasn't already been added. Props michaelingp and nbaxley
			$term_relationship_inner_join = " INNER JOIN {$wpdb->term_relationships} ON ({$wpdb->posts}.ID = {$wpdb->term_relationships}.object_id)";
			$term_relationship_left_join  = " LEFT JOIN {$wpdb->term_relationships} AS tr1 ON ({$wpdb->posts}.ID = tr1.object_id)";

			$term_taxonomy_join = " INNER JOIN {$wpdb->term_taxonomy} ON ( tr1.term_taxonomy_id = {$wpdb->term_taxonomy}.term_taxonomy_id )";

			// 4.6+ uses a LEFT JOIN for tax queries, so we need to check for both.
			if ( false === strpos( $join, trim( $term_relationship_inner_join ) )
				&& false === strpos( $join, trim( $term_relationship_left_join ) ) ) {
				$join .= $term_relationship_left_join;
			}

			if ( false === strpos( $join, trim( $term_taxonomy_join ) ) ) {
				$join .= str_replace( 'INNER JOIN', 'LEFT JOIN', $term_taxonomy_join );
			}
		}

		return $join;
	}

	/**
	 * Modify the author query posts SQL to include posts co-authored
	 *
	 * @param string   $where
	 * @param WP_Query $query
	 *
	 * @return string
	 */
	public function posts_where_filter( $where, $query ): string {
		global $wpdb;

		if ( $query->is_author() ) {
			$post_type = $query->query_vars['post_type'];
			if ( 'any' === $post_type ) {
				$post_type = get_post_types( array( 'exclude_from_search' => false ) );
			}

			if ( ! empty( $post_type ) && ! is_object_in_taxonomy( $post_type, $this->coauthor_taxonomy ) ) {
				return $where;
			}

			if ( $query->get( 'author_name' ) ) {
				$author_name = sanitize_title( $query->get( 'author_name' ) );
			} else {
				$author_data = get_userdata( $query->get( $this->coauthor_taxonomy ) );
				if ( is_object( $author_data ) ) {
					$author_name = $author_data->user_nicename;
				} else {
					return $where;
				}
			}

			$terms    = array();
			$coauthor = $this->get_coauthor_by( 'user_nicename', $author_name );
			if ( $author_term = $this->get_author_term( $coauthor ) ) {
				$terms[] = $author_term;
			}
			// If this co-author has a linked account, we also need to get posts with those terms
			if ( ! empty( $coauthor->linked_account ) ) {
				$linked_account = get_user_by( 'login', $coauthor->linked_account );
				if ( $guest_author_term = $this->get_author_term( $linked_account ) ) {
					$terms[] = $guest_author_term;
				}
			}

			// Whether to include the original 'post_author' value in the query.
			// Don't include it if we're forcing guest authors, or it's obvious our query is for a guest author's posts
			if ( $this->force_guest_authors || stripos( $where, '.post_author = 0)' ) ) {
				$maybe_both = false;
			} else {
				$maybe_both = apply_filters( 'coauthors_plus_should_query_post_author', true );
			}

			$maybe_both_query = $maybe_both ? '$1 OR' : '';

			if ( ! empty( $terms ) ) {
				$terms_implode      = '';
				$this->having_terms = '';
				foreach ( $terms as $term ) {
					$terms_implode      .= '(' . $wpdb->term_taxonomy . '.taxonomy = \'' . $this->coauthor_taxonomy . '\' AND ' . $wpdb->term_taxonomy . '.term_id = \'' . $term->term_id . '\') OR ';
					$this->having_terms .= ' ' . $wpdb->term_taxonomy . '.term_id = \'' . $term->term_id . '\' OR ';
				}
				$terms_implode = rtrim( $terms_implode, ' OR' );

				// We need to check the query is the main query as a new query object would result in the wrong ID
				$id = is_author() && $query->is_main_query() ? get_queried_object_id() : '\d+';

				// If we have an ID, but it's not a "real" ID that means that this isn't the first time the filter has fired and the object_id has already been replaced by a previous run of this filter. We therefore need to replace the 0
				// This happens when wp_query::get_posts() is run multiple times.
				// If previous condition resulted in this being a string there's no point wasting a db query looking for a user.
				if ( $id !== '\d+' && false === get_user_by( 'id', $id ) ) {
					$id = '\d+';
				}

				$maybe_both_query = $maybe_both ? '$0 OR' : '';

				// add the taxonomy terms to the where query
				$where = preg_replace( '/\(?\b(?:' . $wpdb->posts . '\.)?post_author\s*(?:=|IN)\s*\(?\d+\)?\)?/', ' (' . $maybe_both_query . ' ' . $terms_implode . ')', $where, 1 );

				// if there is a duplicate post_author query parameter, remove the duplicate
				$where = preg_replace( '/AND\s*\((?:' . $wpdb->posts . '\.)?post_author\s*\=\s*\d+\)/', ' ', $where, 1 );

				// When WordPress generates query as 'post_author IN (id)', and there is a numeric $id, replace the often errant $id with the correct one - related to https://core.trac.wordpress.org/ticket/54268
				if ( '\d+' !== $id ) {
					$where = preg_replace( '/\b(?:' . $wpdb->posts . '\.)?post_author\s*IN\s*\(\d+\)/', ' (' . $wpdb->posts . '.post_author = ' . $id . ')', $where, 1 );
				}

				// the block targets the private posts clause (if it exists)
				if (
					is_user_logged_in() &&
					is_author() &&
					get_queried_object_id() !== get_current_user_id()
				) {
					$current_coauthor      = $this->get_coauthor_by( 'user_nicename', wp_get_current_user()->user_nicename );
					$current_coauthor_term = $this->get_author_term( $current_coauthor );

					if ( $current_coauthor_term instanceof \WP_Term ) {
						$current_user_query  = $wpdb->term_taxonomy . '.taxonomy = \'' . $this->coauthor_taxonomy . '\' AND ' . $wpdb->term_taxonomy . '.term_id = \'' . $current_coauthor_term->term_id . '\'';
						$this->having_terms .= ' ' . $wpdb->term_taxonomy . '.term_id = \'' . $current_coauthor_term->term_id . '\' OR ';

						$where = preg_replace( '/(\b(?:' . $wpdb->posts . '\.)?post_author\s*=\s*(' . get_current_user_id() . ') )/', $current_user_query . ' ', $where, 1 ); // ' . $wpdb->postmeta . '.meta_id IS NOT NULL AND}
					}
				}

				$this->having_terms = rtrim( $this->having_terms, ' OR' );

			}
		}
		return $where;
	}

	/**
	 * Modify the author query posts SQL to include posts co-authored
	 */
	public function posts_groupby_filter( $groupby, $query ) {
		global $wpdb;

		if ( $query->is_author() ) {
			$post_type = $query->query_vars['post_type'];
			if ( 'any' === $post_type ) {
				$post_type = get_post_types( array( 'exclude_from_search' => false ) );
			}
			if ( ! empty( $post_type ) && ! is_object_in_taxonomy( $post_type, $this->coauthor_taxonomy ) ) {
				return $groupby;
			}

			if ( $this->having_terms ) {
				$having  = 'MAX( IF ( ' . $wpdb->term_taxonomy . '.taxonomy = \'' . $this->coauthor_taxonomy . '\', IF ( ' . $this->having_terms . ',2,1 ),0 ) ) <> 1 ';
				$groupby = $wpdb->posts . '.ID HAVING ' . $having;
			}
		}
		return $groupby;
	}

	/**
	 * Filters post data before saving to db to set post_author
	 */
	public function coauthors_set_post_author_field( $data, $postarr ) {

		// Bail on autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}

		// Bail on revisions
		if ( ! $this->is_post_type_enabled( $data['post_type'] ) ) {
			return $data;
		}

		// This action happens when a post is saved while editing a post
		if ( isset( $_REQUEST['coauthors-nonce'], $_POST['coauthors'] ) && is_array( $_POST['coauthors'] ) ) { // phpcs:ignore

			// rawurlencode() is for encoding co-author name with special characters to compare names when getting co-author.
			$author = rawurlencode( sanitize_text_field( $_POST['coauthors'][0] ) ); // phpcs:ignore

			if ( $author ) {
				$author_data = $this->get_coauthor_by( 'user_nicename', $author );
				// If it's a guest author and has a linked account, store that information in post_author
				// because it'll be the valid user ID
				if ( 'guest-author' === $author_data->type && ! empty( $author_data->linked_account ) ) {
					$user = get_user_by( 'login', $author_data->linked_account );
					if ( is_object( $user ) ) {
						$data['post_author'] = $user->ID;
					}
				} elseif ( 'wpuser' === $author_data->type ) {
					$data['post_author'] = $author_data->ID;
				}
			}
		}

		// If for some reason we don't have the co-authors fields set
		if ( ! isset( $data['post_author'] ) ) {
			$user                = wp_get_current_user();
			$data['post_author'] = $user->ID;
		}

		// Allow the 'post_author' to be forced to generic user if it doesn't match any users on the post
		$data['post_author'] = apply_filters( 'coauthors_post_author_value', $data['post_author'], $postarr['ID'] );

		return $data;
	}

	/**
	 * Update a post's co-authors on the 'save_post' hook
	 *
	 * @param $post_ID
	 */
	public function coauthors_update_post( $post_id, $post ): void {

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! $this->is_post_type_enabled( $post->post_type ) ) {
			return;
		}

		if ( isset( $_POST['coauthors-nonce'], $_POST['coauthors'] ) && $this->current_user_can_set_authors() ) {
			// if current_user_can_set_authors and nonce valid
			check_admin_referer( 'coauthors-edit', 'coauthors-nonce' );

			$coauthors = (array) $_POST['coauthors'];
			$coauthors = array_map( 'sanitize_title', $coauthors );
			$this->add_coauthors( $post_id, $coauthors );
		} else {
			// If a co-author isn't currently set, we need to explicity set one
			if ( ! $this->has_author_terms( $post_id ) ) {
				$user = get_userdata( $post->post_author );
				if ( $user ) {
					$this->add_coauthors( $post_id, array( $user->user_nicename ) );
				}
			}
		}
	}

	public function has_author_terms( $post_id ): bool {
		$terms = wp_get_object_terms( $post_id, $this->coauthor_taxonomy, array( 'fields' => 'ids' ) );
		return ! empty( $terms ) && ! is_wp_error( $terms );
	}

	/**
	 * Add one or more co-authors as bylines for a post
	 *
	 * @param int
	 * @param array
	 * @param bool
	 * @param string
	 */
	public function add_coauthors( $post_id, $coauthors, $append = false, $query_type = 'user_nicename' ): bool {
		global $current_user, $wpdb;

		$post_id = (int) $post_id;
		$insert  = false;

		// Best way to persist order
		if ( $append ) {
			$field              = apply_filters( 'coauthors_post_list_pluck_field', 'user_login' );
			$existing_coauthors = wp_list_pluck( get_coauthors( $post_id ), $field );
		} else {
			$existing_coauthors = array();
		}

		// A co-author is always required
		// If no co-author is provided AND no co-authors are currently set, assign to current user - retain old ones otherwise.
		if ( empty( $coauthors ) ) {
			if ( empty( $existing_coauthors ) ) {
				$coauthors = array( $current_user->user_login );
			} else {
				$coauthors = $existing_coauthors;
			}
		}

		// Set the co-authors
		$coauthors        = array_unique( array_merge( $existing_coauthors, $coauthors ) );
		$coauthor_objects = array();
		foreach ( $coauthors as &$author_name ) {
			$field = apply_filters( 'coauthors_post_get_coauthor_by_field', $query_type, $author_name );

			$author             = $this->get_coauthor_by( $field, $author_name );
			$coauthor_objects[] = $author;
			$term               = $this->update_author_term( $author );
			if ( is_object( $term ) ) {
				$author_name = $term->slug;
			}
		}
		wp_set_post_terms( $post_id, $coauthors, $this->coauthor_taxonomy );

		// If the original post_author is no longer assigned,
		// update to the first WP_User $coauthor
		$post_author_user = get_user_by( 'id', get_post( $post_id )->post_author );
		if ( empty( $post_author_user )
			|| ! in_array( $post_author_user->user_login, $coauthors ) ) {
			foreach ( $coauthor_objects as $coauthor_object ) {
				if ( $coauthor_object instanceof WP_User ) {
					$new_author = $coauthor_object;
					break;
				} elseif ( isset( $coauthor_object->wp_user ) && $coauthor_object->wp_user instanceof WP_User ) {
					$new_author = $coauthor_object->wp_user;
					break;
				}
			}

			/*
			 * If setting a fresh group of authors for a post, (i.e. $append === false),
			 * then perhaps one of those authors should be a WP_USER. However,
			 * if $append === true, and we are perhaps unable to find a
			 * WP_USER (perhaps none was given), we don't really
			 * care whether post_author should be updated.
			 * */
			if ( false === $append && empty( $new_author ) ) {
				return false;
			}

			if ( ! empty( $new_author ) ) {
				$update = $wpdb->update( $wpdb->posts, array( 'post_author' => $new_author->ID ), array( 'ID' => $post_id ) );
				clean_post_cache( $post_id );

				if ( is_bool( $update ) ) {
					return $update;
				}
			}
		}
		return true;

	}

	/**
	 * Action taken when co-author is deleted.
	 * - Co-Author term is removed from all associated posts
	 * - Option to specify alternate co-author in place for each post
	 *
	 * @param delete_id
	 */
	public function delete_user_action( $delete_id ): void {
		global $wpdb;

		$reassign_id = isset( $_POST['reassign_user'] ) ? absint( $_POST['reassign_user'] ) : false; // phpcs:ignore

		// If reassign posts, do that -- use coauthors_update_post
		if ( $reassign_id ) {
			// Get posts belonging to deleted author
			$reassign_user = get_user_by( 'id', $reassign_id );
			// Set to new guest author
			if ( is_object( $reassign_user ) ) {
				$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_author = %d", $delete_id ) );

				if ( $post_ids ) {
					foreach ( $post_ids as $post_id ) {
						$this->add_coauthors( $post_id, array( $reassign_user->user_nicename ), true );
					}
				}
			}
		}

		$delete_user = get_user_by( 'id', $delete_id );
		if ( is_object( $delete_user ) ) {
			// Delete term
			$term = $this->get_author_term( $delete_user );
			if ( $term ) {
				wp_delete_term( $term->term_id, $this->coauthor_taxonomy );
			}
		}

		if ( $this->is_guest_authors_enabled() ) {
			// Get the deleted user data by user id.
			$user_data = get_user_by( 'id', $delete_id );

			// Get the associated user.
			$associated_user = $this->guest_authors->get_guest_author_by( 'linked_account', $user_data->data->user_login );

			if ( isset( $associated_user->ID ) ) {
				// Delete associated guest user.
				$this->guest_authors->delete( $associated_user->ID );
			}
		}
	}

	/**
	 * Restrict WordPress from blowing away co-author order when bulk editing terms
	 *
	 * @since 2.6
	 * @props kingkool68, http://wordpress.org/support/topic/plugin-co-authors-plus-making-authors-sortable
	 * @props kingkool68, http://wordpress.org/support/topic/plugin-co-authors-plus-making-authors-sortable
	 */
	public function filter_wp_get_object_terms( $terms, $object_ids, $taxonomies, $args ) {
		if ( ! isset( $_REQUEST['bulk_edit'] ) || $this->coauthor_taxonomy !== $taxonomies ) {
			return $terms;
		}

		global $wpdb;
		$orderby       = 'ORDER BY tr.term_order';
		$order         = 'ASC';
		$object_ids    = (int) $object_ids;
		$query         = $wpdb->prepare( "SELECT t.name, t.term_id, tt.term_taxonomy_id FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy AS tt ON tt.term_id = t.term_id INNER JOIN $wpdb->term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy IN (%s) AND tr.object_id IN (%s) $orderby $order", $this->coauthor_taxonomy, $object_ids ); //phpcs:ignore
		$raw_coauthors = $wpdb->get_results( $query ); //phpcs:ignore
		$terms         = array();
		foreach ( $raw_coauthors as $author ) {
			if ( is_array( $args ) && isset( $args['fields'] ) ) {
				switch ( $args['fields'] ) {
					case 'names':
						$terms[] = $author->name;
						break;
					case 'tt_ids':
						$terms[] = $author->term_taxonomy_id;
						break;
					case 'ids':
						$terms[] = (int) $author->term_id;
						break;
					case 'all':
					default:
						$terms[] = get_term( $author->term_id, $this->coauthor_taxonomy );
						break;
				}
			} else {
				$terms[] = get_term( $author->term_id, $this->coauthor_taxonomy );
			}
		}

		return $terms;

	}

	/**
	 * Filter the count_users_posts() core function to include our correct count.
	 *
	 * @param int $count Post count
	 * @param int $user_id WP user ID
	 * @return int Post count
	 */
	public function filter_count_user_posts( $count, $user_id ): int {
		$user     = get_userdata( $user_id );
		$coauthor = $this->get_coauthor_by( 'user_nicename', $user->user_nicename );

		// Return $count if no co-author exists.
		if ( ! is_object( $coauthor ) ) {
			return $count;
		}

		$term = $this->get_author_term( $coauthor );

		if ( is_object( $term ) ) {
			// Return combined post count, if account is linked.
			if ( strlen( $coauthor->linked_account ) > 2 ) {
				return $count + $term->count;
			}

			// Otherwise, return the term count.
			return $term->count;
		}

		// Return $count as fallback.
		return $count;
	}

	/**
	 * Checks to see if the current user can set co-authors or not
	 */
	public function current_user_can_set_authors() {
		$current_user = wp_get_current_user();
		if ( ! $current_user ) {
			return false;
		}
		// Super admins can do anything
		if ( function_exists( 'is_super_admin' ) && is_super_admin() ) {
			return true;
		}

		// Instead of using current_user_can(), we need to manually check the allcaps because of filter_user_has_cap
		$can_set_authors = isset( $current_user->allcaps['edit_others_posts'] ) && $current_user->allcaps['edit_others_posts'];

		return apply_filters( 'coauthors_plus_edit_authors', $can_set_authors );
	}

	/**
	 * Fix for author pages 404ing or not properly displaying on author pages
	 *
	 * If a guest author has no posts, we only want to force the queried object to be
	 * the author if they're a user.
	 *
	 * If the guest author does have posts, it doesn't matter that they're not an author.
	 *
	 * Alternatively, on an author archive, if the first story has co-authors and
	 * the first author is NOT the same as the author for the archive,
	 * the query_var is changed.
	 *
	 * Also, we have to do some hacky WP_Query modification for guest authors
	 *
	 * @param string $selection The assembled selection query
	 * @void
	 */
	public function fix_author_page( $selection ): void {

		global $wp_query, $authordata;

		if ( ! isset( $wp_query ) ) {
			return;
		}

		if ( ! is_author() ) {
			return;
		}

		$author_name = sanitize_title( get_query_var( 'author_name' ) );
		if ( ! $author_name ) {
			return;
		}

		$author = $this->get_coauthor_by( 'user_nicename', $author_name );
		if ( is_object( $author ) ) {
			$authordata = $author; //phpcs:ignore
			$term       = $this->get_author_term( $authordata );
		}

		if ( is_object( $authordata ) || ! empty( $term ) ) {
			$wp_query->queried_object    = $authordata;
			$wp_query->queried_object_id = (int) $authordata->ID;
			if ( ! is_paged() ) {
				add_filter( 'pre_handle_404', '__return_true' );
			}
		} else {
			$wp_query->queried_object = $wp_query->queried_object_id = null;
			$wp_query->is_author      = $wp_query->is_archive = false;
			$wp_query->is_404         = false;
		}
	}

	/**
	 * Filters the Infinite Scroll settings to remove `author` from the query_args
	 * when we are dealing with a Guest Author
	 *
	 * If this isn't removed, the author id can be sent in place of author_name, and the
	 * normal query interception doesn't work, resulting in incorrect results
	 *
	 * @param  array $settings The existing IS settings to filter
	 * @return array           The filtered IS settings
	 */
	public function filter_infinite_scroll_js_settings( $settings ): array {
		if ( ! is_author() ) {
			return $settings;
		}

		$author = get_queried_object();

		if ( $author && 'guest-author' === $author->type ) {
			unset( $settings['query_args'][ $this->coauthor_taxonomy ] );

			$settings['query_args']['author_name'] = $author->user_nicename;
		}

		return $settings;
	}

	/**
	 * Main function that handles search-as-you-type for adding co-authors
	 */
	public function ajax_suggest(): void {

		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'coauthors-search' ) ) {
			die();
		}

		if ( empty( $_REQUEST['q'] ) ) {
			die();
		}

		$search = sanitize_text_field( strtolower( $_REQUEST['q'] ) );
		$ignore = array_map( 'sanitize_text_field', explode( ',', $_REQUEST['existing_authors'] ) ); // phpcs:ignore

		$authors = $this->search_authors( $search, $ignore );

		// Return message if no authors found
		if ( empty( $authors ) ) {
			echo esc_html( apply_filters( 'coauthors_no_matching_authors_message', 'Sorry, no matching authors found.' ) );
		}

		foreach ( $authors as $author ) {
			$user_type = 'guest-user';
			if ( $author instanceof WP_User ) {
				$user_type = 'wp-user';
			}

			printf(
				"%s ∣ %s ∣ %s ∣ %s ∣ %s ∣ %s \n",
				esc_html( $author->ID ),
				esc_html( $author->user_login ),
				// Ensure that author names can contain a pipe character by replacing the pipe character with the
				// divides character, which will now serve as a delimiter of the author parameters. (#370)
				esc_html( str_replace( '∣', '|', $author->display_name ) ),
				esc_html( $author->user_email ),
				esc_html( rawurldecode( $author->user_nicename ) ),
				esc_url( get_avatar_url( $author->ID,  array( 'user_type' => $user_type ) ) )
			);
		}

		die();

	}

	/**
	 * Get matching co-authors based on a search value
	 */
	public function search_authors( $search = '', $ignored_authors = array() ): array {

		// Since 2.7, we're searching against the term description for the fields
		// instead of the user details. If the term is missing, we probably need to
		// back-fill with user details. Let's do this first... easier than running
		// an upgrade script that could break on a lot of users
		$args        = array(
			'count_total'    => false,
			'search'         => sprintf( '*%s*', $search ),
			'search_columns' => array(
				'ID',
				'display_name',
				'user_email',
				'user_login',
			),
			'capability'     => array( apply_filters( 'coauthors_edit_author_cap', 'edit_posts' ) ),
			'fields'         => 'all_with_meta',
		);
		$found_users = get_users( $args );

		foreach ( $found_users as $found_user ) {
			$term = $this->get_author_term( $found_user );
			if ( empty( $term ) || empty( $term->description ) ) {
				$this->update_author_term( $found_user );
			}
		}

		$args = array(
			'search' => $search,
			'get'    => 'all',
			'number' => 10,
		);
		$args = apply_filters( 'coauthors_search_authors_get_terms_args', $args );
		add_filter( 'terms_clauses', array( $this, 'filter_terms_clauses' ) );
		$found_terms = get_terms( $this->coauthor_taxonomy, $args );
		remove_filter( 'terms_clauses', array( $this, 'filter_terms_clauses' ) );

		if ( empty( $found_terms ) ) {
			return array();
		}

		// Get the guest author objects
		$found_users = array();
		foreach ( $found_terms as $found_term ) {
			$found_user = $this->get_coauthor_by( 'user_nicename', $found_term->slug );
			if ( ! $found_user && 0 === strpos( $found_term->slug, 'cap-cap-' ) ) {
				// Account for guest author terms that start with 'cap-'.
				// e.g. "Cap Ri" -> "cap-cap-ri".
				$cap_slug   = substr( $found_term->slug, 4, strlen( $found_term->slug ) );
				$found_user = $this->get_coauthor_by( 'user_nicename', $cap_slug );
			}
			if ( ! empty( $found_user ) ) {
				$found_users[ $found_user->user_login ] = $found_user;
			}
		}

		// Allow users to always filter out certain users if needed (e.g. administrators)
		$ignored_authors = apply_filters( 'coauthors_edit_ignored_authors', $ignored_authors );
		foreach ( $found_users as $key => $found_user ) {
			// Make sure the user is contributor and above (or a custom cap)
			if ( in_array( $found_user->user_nicename, $ignored_authors, true ) ) { // AJAX sends a list of already present *users_nicenames*
				unset( $found_users[ $key ] );
			} elseif ( 'wpuser' === $found_user->type && false === $found_user->has_cap( apply_filters( 'coauthors_edit_author_cap', 'edit_posts' ) ) ) {
				unset( $found_users[ $key ] );
			}
		}
		return $found_users;
	}

	/**
	 * Modify get_terms() to LIKE against the term description instead of the term name
	 *
	 * @since 3.0
	 */
	public function filter_terms_clauses( $pieces ) {

		$pieces['where'] = str_replace( 't.name LIKE', 'tt.description LIKE', $pieces['where'] );
		return $pieces;
	}

	/**
	 * Functions to add scripts and css
	 */
	public function enqueue_scripts( $hook_suffix ): void {
		global $pagenow, $post;

		if ( ! $this->is_valid_page() || ! $this->is_post_type_enabled() || ! $this->current_user_can_set_authors() ) {
			return;
		}

		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_style( 'co-authors-plus-css', plugins_url( 'css/co-authors-plus.css', COAUTHORS_PLUS_FILE ), false, COAUTHORS_PLUS_VERSION );
		wp_enqueue_script( 'co-authors-plus-js', plugins_url( 'js/co-authors-plus.js', COAUTHORS_PLUS_FILE ), array( 'jquery', 'suggest' ), COAUTHORS_PLUS_VERSION, true );

		$js_strings = array(
			'edit_label'      => __( 'Edit', 'co-authors-plus' ),
			'delete_label'    => __( 'Remove', 'co-authors-plus' ),
			'confirm_delete'  => __( 'Are you sure you want to remove this author?', 'co-authors-plus' ),
			'input_box_title' => __( 'Click to change this author, or drag to change their position', 'co-authors-plus' ),
			'search_box_text' => __( 'Search for an author', 'co-authors-plus' ),
			'help_text'       => __( 'Click on an author to change them. Drag to change their order. Click on <strong>Remove</strong> to remove them.', 'co-authors-plus' ),
		);
		wp_localize_script( 'co-authors-plus-js', 'coAuthorsPlusStrings', $js_strings );

	}

	/**
	 * load-edit.php is when the screen has been set up
	 */
	public function load_edit(): void {

		$screen = get_current_screen();
		if ( in_array( $screen->post_type, $this->supported_post_types() ) ) {
			add_filter( 'views_' . $screen->id, array( $this, 'filter_views' ) );
		}
	}

	/**
	 * Filter the view links that appear at the top of the Manage Posts view
	 *
	 * @since 3.0
	 */
	public function filter_views( $views ) {

		if ( array_key_exists( 'mine', $views ) ) {
			return $views;
		}

		$views     = array_reverse( $views );
		$all_view  = array_pop( $views );
		$mine_args = array(
			'author_name' => wp_get_current_user()->user_nicename,
		);
		if ( 'post' !== get_post_type() ) {
			$mine_args['post_type'] = get_current_screen()->post_type;
		}
		if ( ! empty( $_REQUEST['author_name'] ) && wp_get_current_user()->user_nicename == $_REQUEST['author_name'] ) {
			$class = ' class="current"';
		} else {
			$class = '';
		}
		$views['mine'] = '<a' . $class . ' href="' . esc_url( add_query_arg( array_map( 'rawurlencode', $mine_args ), admin_url( 'edit.php' ) ) ) . '">' . __( 'Mine', 'co-authors-plus' ) . '</a>';

		$views['all'] = str_replace( $class, '', $all_view );
		$views        = array_reverse( $views );

		return $views;
	}

	/**
	 * Prevent WordPress from counting users' posts for Users table column that
	 * is removed by the `_filter_manage_users_columns` method.
	 *
	 * @return void
	 */
	public function load_users_screen(): void {
		add_filter( 'pre_count_many_users_posts', array( $this, 'bypass_user_post_count' ), 10, 2 );
	}

	/**
	 * Return empty counts for `count_users_many_posts()`, to bypass the heavy
	 * and unused query results.
	 *
	 * @param string[]|null $counts   Post counts.
	 * @param array         $user_ids User IDs to return counts for.
	 * @return array
	 */
	public function bypass_user_post_count( $counts, $user_ids ) {
		return array_fill_keys(
			array_map( 'absint', $user_ids ),
			0
		);
	}

	/**
	 * Adds necessary javascript variables to admin pages
	 */
	public function js_vars(): void {

		if ( ! $this->is_valid_page() || ! $this->is_post_type_enabled() || ! $this->current_user_can_set_authors() ) {
			return;
		}
		?>
			<script type="text/javascript">
				// AJAX link used for the autosuggest
				var coAuthorsPlus_ajax_suggest_link =
				<?php
				echo wp_json_encode(
					add_query_arg(
						array(
							'action'    => 'coauthors_ajax_suggest',
							'post_type' => rawurlencode( get_post_type() ),
						),
						wp_nonce_url( 'admin-ajax.php', 'coauthors-search' )
					)
				);
				?>
				;
			</script>
		<?php
	}

	/**
	 * Helper to only add javascript to necessary pages. Avoids bloat in admin.
	 *
	 * @return bool
	 */
	public function is_valid_page(): bool {
		global $pagenow;

		return in_array( $pagenow, $this->_pages_whitelist );
	}

	/**
	 * Builds list of capabilities that CAP should filter.
	 *
	 * Will only work after $this->supported_post_types has been populated.
	 * Will only run once per request, and then cache the result.
	 * The result is cached in $this->to_be_filtered_caps since CoAuthors_Plus is only instantiated once and stored as a global.
	 *
	 * @return array caps that CAP should filter
	 */
	public function get_to_be_filtered_caps(): array {
		if ( ! empty( $this->supported_post_types() ) && empty( $this->to_be_filtered_caps ) ) {
			$this->to_be_filtered_caps[] = 'edit_post'; // Need to filter this too, unfortunately: http://core.trac.wordpress.org/ticket/22415
			$this->to_be_filtered_caps[] = 'read_post';

			foreach ( $this->supported_post_types() as $single ) {
				$obj = get_post_type_object( $single );

				$this->to_be_filtered_caps[] = $obj->cap->edit_post;
				$this->to_be_filtered_caps[] = $obj->cap->edit_others_posts; // This as well: http://core.trac.wordpress.org/ticket/22417
				$this->to_be_filtered_caps[] = $obj->cap->read_post;
			}

			$this->to_be_filtered_caps = array_unique( $this->to_be_filtered_caps );
		}

		return $this->to_be_filtered_caps;
	}

	/**
	 * Allows guest authors to edit the post they're co-authors of
	 */
	public function filter_user_has_cap( $allcaps, $caps, $args ) {

		$cap     = $args[0];
		$user_id = $args[1] ?? 0;
		$post_id = $args[2] ?? 0;

		if ( ! in_array( $cap, $this->get_to_be_filtered_caps(), true ) ) {
			return $allcaps;
		}

		$obj = get_post_type_object( get_post_type( $post_id ) );
		if ( ! $obj || 'revision' === $obj->name ) {
			return $allcaps;
		}

		// Even though we bail if cap is not among the to_be_filtered ones, there is a time in early request processing in which that list is not yet available, so the following block is needed
		$caps_to_modify = array(
			$obj->cap->edit_post,
			'edit_post', // Need to filter this too, unfortunately: http://core.trac.wordpress.org/ticket/22415
			$obj->cap->edit_others_posts, // This as well: http://core.trac.wordpress.org/ticket/22417
			'read_post',
			$obj->cap->read_post,
		);
		if ( ! in_array( $cap, $caps_to_modify ) ) {
			return $allcaps;
		}

		// We won't be doing any modification if they aren't already a co-author on the post
		if ( ! is_user_logged_in() || ! is_coauthor_for_post( $user_id, $post_id ) ) {
			return $allcaps;
		}

		$current_user = wp_get_current_user();
		if ( 'publish' === get_post_status( $post_id ) &&
		     ( isset( $obj->cap->edit_published_posts ) && ! empty( $current_user->allcaps[ $obj->cap->edit_published_posts ] ) ) ) {
			$allcaps[ $obj->cap->edit_published_posts ] = true;
		} elseif ( 'private' === get_post_status( $post_id ) &&
		           ( isset( $obj->cap->edit_private_posts ) && ! empty( $current_user->allcaps[ $obj->cap->edit_private_posts ] ) ) ) {
			$allcaps[ $obj->cap->edit_private_posts ] = true;
		}

		$allcaps[ $obj->cap->edit_others_posts ] = true;

		return $allcaps;
	}

	/**
	 * Get the author term for a given co-author
	 *
	 * @since 3.0
	 *
	 * @param object $coauthor The co-author object
	 * @return object|false $author_term The author term on success
	 */
	public function get_author_term( $coauthor ) {

		if ( ! is_object( $coauthor ) ) {
			return;
		}

		$cache_key = 'author-term-' . $coauthor->user_nicename;
		if ( false !== ( $term = wp_cache_get( $cache_key, 'co-authors-plus' ) ) ) {
			return $term;
		}

		// See if the prefixed term is available, otherwise default to just the nicename
		$term = get_term_by( 'slug', 'cap-' . $coauthor->user_nicename, $this->coauthor_taxonomy );
		if ( ! $term ) {
			$term = get_term_by( 'slug', $coauthor->user_nicename, $this->coauthor_taxonomy );
		}
		wp_cache_set( $cache_key, $term, 'co-authors-plus' );
		return $term;
	}

	/**
	 * Update the author term for a given co-author
	 *
	 * @since 3.0
	 *
	 * @param object $coauthor The co-author object (user or guest author)
	 * @return object|false $success Term object if successful, false if not
	 */
	public function update_author_term( $coauthor ) {

		if ( ! is_object( $coauthor ) ) {
			return false;
		}

		// Update the taxonomy term to include details about the user for searching
		$search_values = array();
		foreach ( $this->ajax_search_fields as $search_field ) {
			$search_values[] = $coauthor->$search_field;
		}
		$term_description = implode( ' ', $search_values );

		if ( $term = $this->get_author_term( $coauthor ) ) {
			if ( $term->description != $term_description ) {
				wp_update_term( $term->term_id, $this->coauthor_taxonomy, array( 'description' => $term_description ) );
			}
		} else {
			$coauthor_slug = 'cap-' . $coauthor->user_nicename;
			$args          = array(
				'slug'        => $coauthor_slug,
				'description' => $term_description,
			);

			$new_term = wp_insert_term( $coauthor->user_login, $this->coauthor_taxonomy, $args );
		}
		wp_cache_delete( 'author-term-' . $coauthor->user_nicename, 'co-authors-plus' );
		return $this->get_author_term( $coauthor );
	}

	/**
	 * Filter Edit Flow's 'ef_calendar_item_information_fields' to add co-authors
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/2
	 *
	 * @param array $information_fields
	 * @param int   $post_id
	 * @return array
	 */
	public function filter_ef_calendar_item_information_fields( $information_fields, $post_id ): array {

		// Don't add the author row again if another plugin has removed
		if ( ! array_key_exists( $this->coauthor_taxonomy, $information_fields ) ) {
			return $information_fields;
		}

		$co_authors = get_coauthors( $post_id );
		if ( count( $co_authors ) > 1 ) {
			$information_fields[ $this->coauthor_taxonomy ]['label'] = __( 'Authors', 'co-authors-plus' );
		}
		$co_authors_names = '';
		foreach ( $co_authors as $co_author ) {
			$co_authors_names .= $co_author->display_name . ', ';
		}
		$information_fields[ $this->coauthor_taxonomy ]['value'] = rtrim( $co_authors_names, ', ' );
		return $information_fields;
	}

	/**
	 * Filter Edit Flow's 'ef_story_budget_term_column_value' to add co-authors to the story budget
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/2
	 *
	 * @param string $column_name
	 * @param object $post
	 * @param object $parent_term
	 * @return string
	 */
	public function filter_ef_story_budget_term_column_value( $column_name, $post, $parent_term ): string {

		// We only want to modify the 'author' column
		if ( $this->coauthor_taxonomy != $column_name ) {
			return $column_name;
		}

		$co_authors       = get_coauthors( $post->ID );
		$co_authors_names = '';
		foreach ( $co_authors as $co_author ) {
			$co_authors_names .= $co_author->display_name . ', ';
		}
		return rtrim( $co_authors_names, ', ' );
	}

	/**
	 * Filter non-native users added by Co-Author-Plus in Jetpack
	 *
	 * @since 3.1
	 *
	 * @param array $og_tags Required. Array of Open Graph Tags.
	 * @param array $image_dimensions Required. Dimensions for images used.
	 * @return array Open Graph Tags either as they were passed or updated.
	 */
	public function filter_jetpack_open_graph_tags( $og_tags, $image_dimensions ): array {

		if ( is_author() ) {
			$author = get_queried_object();

			if ( $author !== null ) {
				$og_tags['og:title']           = $author->display_name;
				$og_tags['og:url']             = get_author_posts_url( $author->ID, $author->user_nicename );
				$og_tags['og:description']     = $author->description;
				$og_tags['profile:first_name'] = $author->first_name;
				$og_tags['profile:last_name']  = $author->last_name;
				if ( isset( $og_tags['article:author'] ) ) {
					$og_tags['article:author'] = get_author_posts_url( $author->ID, $author->user_nicename );
				}
			}
		} elseif ( is_singular() && $this->is_post_type_enabled() ) {
			$authors = get_coauthors();
			if ( ! empty( $authors ) ) {
				$author = array_shift( $authors );
				if ( isset( $og_tags['article:author'] ) ) {
					$og_tags['article:author'] = get_author_posts_url( $author->ID, $author->user_nicename );
				}
			}
		}

		// Send back the updated Open Graph Tags
		return apply_filters( 'coauthors_open_graph_tags', $og_tags );
	}

	/**
	 * Retrieve a list of author terms for a single post.
	 *
	 * Grabs a correctly ordered list of co-authors for a single post, appropriately
	 * cached because it requires `wp_get_object_terms()` to succeed.
	 *
	 * @param int $post_id ID of the post for which to retrieve co-authors.
	 * @return array Array of co-author WP_Term objects.
	 */
	public function get_coauthor_terms_for_post( $post_id ): array {

		if ( ! $post_id ) {
			return array();
		}

		$cache_key      = 'coauthors_post_' . $post_id;
		$coauthor_terms = wp_cache_get( $cache_key, 'co-authors-plus' );

		if ( false === $coauthor_terms ) {
			$coauthor_terms = wp_get_object_terms(
				$post_id,
				$this->coauthor_taxonomy,
				array(
					'orderby' => 'term_order',
					'order'   => 'ASC',
				)
			);

			// This usually happens if the taxonomy doesn't exist, which should never happen, but you never know.
			if ( is_wp_error( $coauthor_terms ) ) {
				return array();
			}

			wp_cache_set( $cache_key, $coauthor_terms, 'co-authors-plus' );
		}

		return $coauthor_terms;

	}

	/**
	 * Callback to clear the cache on post save and post delete.
	 *
	 * @param $post_id The Post ID.
	 */
	public function clear_cache( $post_id ): void {
		wp_cache_delete( 'coauthors_post_' . $post_id, 'co-authors-plus' );
	}

	/**
	 * Callback to clear the cache when an object's terms are changed.
	 *
	 * @param $post_id The Post ID.
	 */
	public function clear_cache_on_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {

		// We only care about the co-authors taxonomy.
		if ( $this->coauthor_taxonomy !== $taxonomy ) {
			return;
		}

		wp_cache_delete( 'coauthors_post_' . $object_id, 'co-authors-plus' );

	}

	/**
	 * Filter of the header of author archive pages to correctly display author.
	 *
	 * @param $title string Archive Page Title
	 *
	 * @return string Archive Page Title
	 */
	public function filter_author_archive_title( $title ): string {

		// Bail if not an author archive template
		if ( ! is_author() ) {
			return $title;
		}

		$author_slug = sanitize_user( get_query_var( 'author_name' ) );
		$author      = $this->get_coauthor_by( 'user_nicename', $author_slug );

		/* translators: Author display name. */
		return sprintf( __( 'Author: %s', 'co-authors-plus' ), $author->display_name );
	}

	/**
	 * Get the post count for the guest author
	 *
	 * @param object $guest_author guest-author object.
	 * @return int post count for the guest author
	 */
	public function get_guest_author_post_count( $guest_author ): int {
		if ( ! is_object( $guest_author ) ) {
			return 0;
		}

		$term       = $this->get_author_term( $guest_author );
		$guest_term = get_term_by( 'slug', 'cap-' . $guest_author->user_nicename, $this->coauthor_taxonomy );

		if ( is_object( $guest_term )
			&& ! empty( $guest_author->linked_account )
			&& $guest_term->count ) {
			$user = get_user_by( 'login', $guest_author->linked_account );
			if ( is_object( $user ) ) {
				return count_user_posts( $user->ID ); // phpcs:ignore
			}
		} elseif ( $term ) {
			return $term->count;
		}

		return 0;
	}

	/**
	 * Filter to display author image if exists instead of avatar.
	 *
	 * @param $url string Avatar URL
	 * @param $id  int Author ID
	 *
	 * @return array Avatar URL
	 */
	public function filter_pre_get_avatar_data_url( $args, $id ): array {
		global $wp_current_filter;

		if ( isset( $args['url'] ) || ! $id || ! is_numeric( $id ) || ! $this->is_guest_authors_enabled() ) {
			return $args;
		}

		// Do not filter the icon in the admin bar
		if ( doing_filter( 'admin_bar_menu' ) ) {
			return $args;
		}

		// Do not filter when we have a WordPress user sent from CAP meta box
		if ( isset( $args['user_type'] ) && 'wp-user' === $args['user_type'] ) {
			return $args;
		}

		// Do not filter when on the user screen
		$current_screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_null( $current_screen ) && isset( $current_screen->parent_base ) && 'users' === $current_screen->parent_base ) {
			return $args;
		}


		$coauthor = $this->get_coauthor_by( 'id', $id );
		if ( false !== $coauthor && isset( $coauthor->type ) && 'guest-author' === $coauthor->type ) {
			if ( has_post_thumbnail( $id ) ) {
				$args['url'] = get_the_post_thumbnail_url( $id, array( $args['width'], $args['height'] ) );
			} elseif ( isset( $coauthor->user_email ) ) {
				$args['url'] = get_avatar_url( $coauthor->user_email, $args );
			} else {
				$args['url'] = get_avatar_url( '', $args ); // Fallback to default.
			}
		}
		return $args;
	}

	/**
	 * Conditionally Hide Author Term Description
	 *
	 * If the current user does not have the required capability,
	 * hide the author term description by unsetting it.
	 *
	 * @link https://github.com/Automattic/Co-Authors-Plus/issues/930
	 * @param WP_REST_Response $response Response for an individual author taxonomy term.
	 * @return WP_REST_Response $response Same response, possibly mutated to eliminate value of description.
	 */
	public function conditionally_hide_author_term_description( WP_REST_Response $response ): \WP_REST_Response {
		$capability = apply_filters(
			'coauthors_rest_view_description_cap',
			'edit_posts'
		);

		if ( current_user_can( $capability ) ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) || ! array_key_exists( 'description', $data ) ) {
			return $response;
		}

		unset( $data['description'] );

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Create Bulk Edit Co-Authors box.
	 *
	 * This is used in the Bulk Edit screen to allow users to set Co-Authors
	 * for multiple posts at once.
	 *
	 * @link https://github.com/Automattic/Co-Authors-Plus/issues/551
	 * @param string $column_name The name of the column being edited.
	 * @param string $post_type The post type being edited.
	 * @return void
	 */
	public function _action_bulk_edit_custom_box( string $column_name, string $post_type ): void {
		if ( 'coauthors' !== $column_name || ! $this->is_post_type_enabled( $post_type ) || ! $this->current_user_can_set_authors() ) {
			return;
		}
		?>
		<label class="bulk-edit-group bulk-edit-coauthors">
			<span id="coauthors-bulk-edit-label" class="title"><?php esc_html_e( 'Authors', 'co-authors-plus' ) ?></span>
		</label>
		<div id="coauthors-edit" class="inline-edit-group wp-clearfix hide-if-no-js">
			<p id="coauthors-bulk-edit-desc"><?php echo wp_kses( __( 'Leave the field below blank to keep the Authors unchanged. Any change here will overwrite all previously assigned Authors.', 'co-authors-plus' ), array( 'strong' => array() ) ); ?></p>
			<?php wp_nonce_field( 'coauthors-edit', 'coauthors-nonce' ); ?>
		</div>
		<?php
	}

	/**
	 * Assign Co-Authors from the bulk edit screen.
	 *
	 * This function is called when the Bulk Edit form is submitted.
	 * It processes the submitted data and updates the Co-Authors for each post.
	 *
	 * @link https://github.com/Automattic/Co-Authors-Plus/issues/551
	 * @param array $post_data The post data from the bulk edit form.
	 * @param array $postarr The post array containing the posts being edited.
	 * @return array $post_data The modified post data.
	 */
	public function action_bulk_edit_update_coauthors( array $post_data, array $postarr ): array {
		if ( empty( $postarr['post'] ) || ! $this->is_post_type_enabled( $postarr['post_type'] ) ) {
			return $post_data;
		}

		foreach( $postarr['post'] as $post_id ) {
			$post = get_post( $post_id );
			if ( $this->current_user_can_set_authors( $post ) && ! empty( $postarr['coauthors'] ) ) {
				$coauthors = array_map( 'sanitize_title', (array) $postarr['coauthors'] );
				$this->add_coauthors( $post_id, $coauthors );
			}
		}

		return $post_data;
	}
}
