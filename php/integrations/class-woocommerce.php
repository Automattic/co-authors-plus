<?php
/**
 * WooCommerce integration.
 *
 * WooCommerce sends customers who cannot edit posts to the shop page when
 * someone opens their author archive. A customer who is also a co-author
 * has a public byline, so that archive should stay available.
 *
 * @package CoAuthors
 * @since 4.2.0
 * @see https://github.com/Automattic/co-authors-plus/issues/1452
 */

namespace Automattic\CoAuthorsPlus\Integrations;

/**
 * Keeps author archives open for WooCommerce customers who have published co-author posts.
 *
 * @since 4.2.0
 */
class WooCommerce {

	/**
	 * Register the integration once every plugin has loaded.
	 *
	 * WooCommerce defines its redirect callback while plugins load, so the
	 * function_exists() check has to wait until plugins_loaded.
	 *
	 * @since 4.2.0
	 */
	public function init(): void {
		add_action( 'plugins_loaded', array( $this, 'register_hooks' ) );
	}

	/**
	 * Hook the author-archive check when WooCommerce is active.
	 *
	 * @since 4.2.0
	 */
	public function register_hooks(): void {
		if ( ! function_exists( 'wc_disable_author_archives_for_customers' ) ) {
			return;
		}

		// Priority 9: WooCommerce redirects on template_redirect at priority 10.
		add_action( 'template_redirect', array( $this, 'allow_customer_author_archive' ), 9 );
	}

	/**
	 * Remove WooCommerce's shop redirect for a customer co-author on this request.
	 *
	 * Customers with no published posts are left alone, so their author
	 * archives still redirect to the shop.
	 *
	 * @since 4.2.0
	 */
	public function allow_customer_author_archive(): void {
		if ( ! function_exists( 'wc_disable_author_archives_for_customers' ) || ! is_author() ) {
			return;
		}

		$user = $this->get_archive_user();
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		// Same condition WooCommerce uses before it redirects.
		if ( ! user_can( $user, 'customer' ) || user_can( $user, 'edit_posts' ) ) {
			return;
		}

		/**
		 * Filters whether a WooCommerce customer co-author keeps their author archive.
		 *
		 * Defaults to true only when the user, or a guest author linked to them,
		 * has published posts. Return false to keep the shop redirect.
		 *
		 * @param bool     $allow Whether the archive should stay available.
		 * @param \WP_User $user  The user whose author archive is being viewed.
		 */
		$allow = (bool) apply_filters( 'coauthors_plus_allow_customer_author_archive', $this->user_has_published_posts( $user ), $user );

		if ( ! $allow ) {
			return;
		}

		remove_action( 'template_redirect', 'wc_disable_author_archives_for_customers', 10 );
	}

	/**
	 * Load the user WooCommerce would redirect, from the author query var.
	 *
	 * @return \WP_User|null
	 */
	private function get_archive_user() {
		global $author;

		if ( empty( $author ) ) {
			return null;
		}

		$user = get_user_by( 'id', (int) $author );

		return $user instanceof \WP_User ? $user : null;
	}

	/**
	 * Whether this user has published posts as a co-author.
	 *
	 * Counts come from the author term, which the plugin already keeps in
	 * sync with published posts. A linked guest author is included because
	 * their posts can be what the user's archive shows.
	 *
	 * @param \WP_User $user Author archive user.
	 * @return bool
	 */
	private function user_has_published_posts( \WP_User $user ): bool {
		global $coauthors_plus;

		if ( ! is_object( $coauthors_plus ) || ! method_exists( $coauthors_plus, 'get_author_term' ) ) {
			return false;
		}

		if ( $this->term_has_posts( $coauthors_plus->get_author_term( $user ) ) ) {
			return true;
		}

		if (
			! method_exists( $coauthors_plus, 'is_guest_authors_enabled' )
			|| ! $coauthors_plus->is_guest_authors_enabled()
			|| empty( $user->user_login )
			|| ! isset( $coauthors_plus->guest_authors )
			|| ! is_object( $coauthors_plus->guest_authors )
		) {
			return false;
		}

		$guest = $coauthors_plus->guest_authors->get_guest_author_by( 'linked_account', $user->user_login );
		if ( ! is_object( $guest ) ) {
			return false;
		}

		return $this->term_has_posts( $coauthors_plus->get_author_term( $guest ) );
	}

	/**
	 * Whether an author term has a published post count.
	 *
	 * @param object|false|null $term Author term from get_author_term().
	 * @return bool
	 */
	private function term_has_posts( $term ): bool {
		return is_object( $term ) && isset( $term->count ) && (int) $term->count > 0;
	}
}
