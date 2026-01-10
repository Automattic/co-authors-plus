<?php
/**
 * Integration with Jetpack Subscriber Emails.
 *
 * Ensures that subscription emails show the correct co-author information
 * instead of the WordPress post_author.
 *
 * @package CoAuthors
 * @since 3.8.0
 * @see https://github.com/Automattic/co-authors-plus/issues/750
 */

namespace Automattic\CoAuthorsPlus\Integrations;

/**
 * Jetpack Subscriber Emails Integration.
 *
 * Filters the author data synced to WordPress.com for subscription emails
 * to use the primary co-author instead of the post_author field.
 *
 * @since 3.8.0
 */
class Jetpack_Subscriber_Emails {

	/**
	 * Initialize the integration.
	 *
	 * @since 3.8.0
	 */
	public function init(): void {
		add_action( 'plugins_loaded', array( $this, 'register_hooks' ) );
	}

	/**
	 * Register hooks if Jetpack sync is available.
	 *
	 * @since 3.8.0
	 */
	public function register_hooks(): void {
		if ( ! $this->is_jetpack_sync_available() ) {
			return;
		}

		add_filter( 'jetpack_published_post_flags', array( $this, 'filter_published_post_flags' ), 10, 2 );
	}

	/**
	 * Check if Jetpack sync functionality is available.
	 *
	 * @since 3.8.0
	 *
	 * @return bool True if Jetpack sync is available.
	 */
	protected function is_jetpack_sync_available(): bool {
		return class_exists( 'Jetpack' ) || class_exists( 'Automattic\Jetpack\Sync\Modules\Posts' );
	}

	/**
	 * Filter the published post flags to use co-author information.
	 *
	 * This ensures that subscription emails display the correct author
	 * (the primary co-author) instead of the post_author field value.
	 *
	 * @since 3.8.0
	 *
	 * @param array    $flags Post flags being synced to WordPress.com.
	 * @param \WP_Post $post  The post being published.
	 * @return array Modified post flags with co-author information.
	 */
	public function filter_published_post_flags( array $flags, \WP_Post $post ): array {
		global $coauthors_plus;

		if ( ! $coauthors_plus || ! $coauthors_plus->is_post_type_enabled( $post->post_type ) ) {
			return $flags;
		}

		$coauthors = get_coauthors( $post->ID );

		if ( empty( $coauthors ) ) {
			return $flags;
		}

		// Use the primary (first) co-author for the email.
		$primary_author = $coauthors[0];

		$flags['author'] = $this->build_author_data( $primary_author, $post );

		// Add all co-authors for potential future use.
		$flags['coauthors'] = array_map(
			function ( $coauthor ) use ( $post ) {
				return $this->build_author_data( $coauthor, $post );
			},
			$coauthors
		);

		return $flags;
	}

	/**
	 * Build author data array for a co-author.
	 *
	 * @since 3.8.0
	 *
	 * @param object   $coauthor The co-author object (WP_User or guest author).
	 * @param \WP_Post $post     The post being published.
	 * @return array Author data formatted for Jetpack sync.
	 */
	protected function build_author_data( $coauthor, \WP_Post $post ): array {
		$author_data = array(
			'id'           => $coauthor->ID,
			'display_name' => $coauthor->display_name,
			'email'        => '',
			'type'         => isset( $coauthor->type ) ? $coauthor->type : 'wpuser',
		);

		// Get email - guest authors may have it in a different location.
		if ( $coauthor instanceof \WP_User ) {
			$author_data['email']           = $coauthor->user_email;
			$author_data['wpcom_user_id']   = get_user_meta( $coauthor->ID, 'wpcom_user_id', true );
			$author_data['translated_role'] = $this->get_translated_role( $coauthor );
		} elseif ( isset( $coauthor->user_email ) ) {
			$author_data['email'] = $coauthor->user_email;
		}

		// Add author URL.
		$author_data['url'] = get_author_posts_url( $coauthor->ID, $coauthor->user_nicename );

		return $author_data;
	}

	/**
	 * Get the translated role for a user.
	 *
	 * @since 3.8.0
	 *
	 * @param \WP_User $user The user object.
	 * @return string Translated role or empty string.
	 */
	protected function get_translated_role( \WP_User $user ): string {
		if ( class_exists( 'Automattic\Jetpack\Roles' ) ) {
			$roles = new \Automattic\Jetpack\Roles();
			return $roles->translate_user_to_role( $user );
		}

		return '';
	}
}
