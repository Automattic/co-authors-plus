<?php
/**
 * For themes where it's easily doable, add support for Co-Authors Plus on the frontend
 * by filtering the common template tags
 */

class CoAuthors_Template_Filters {

	/**
	 * Register the template filter hooks.
	 *
	 * Called from the composition root after construction so that creating an
	 * instance has no global side effects.
	 */
	public function register_hooks(): void {
		add_filter( 'the_author', array( $this, 'filter_the_author' ) );
		add_filter( 'the_author_posts_link', array( $this, 'filter_the_author_posts_link' ) );
	}

	public function filter_the_author(): string {
		return coauthors( null, null, null, null, false );
	}

	public function filter_the_author_posts_link(): string {
		return coauthors_posts_links( null, null, null, null, false );
	}
}
