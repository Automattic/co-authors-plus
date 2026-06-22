<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

/**
 * Regression coverage for issue #1310.
 *
 * Previously, coauthors__echo() applied the default "between" delimiter only
 * when the separator was unset. A caller passing an empty string — coauthors( '' ) —
 * left the middle authors run together ("AnnaBenCara and Dan"), because
 * isset( '' ) is true. A caller-supplied empty string should fall back to the
 * default, while an empty separator set deliberately through the
 * coauthors_default_between filter must still be honoured.
 *
 * @covers \coauthors__echo
 */
class BylineSeparatorTest extends TestCase {

	/**
	 * Create four named authors and assign them, in order, to a published post.
	 */
	private function post_with_four_authors() {
		$authors = array(
			'sep_a' => 'Anna',
			'sep_b' => 'Ben',
			'sep_c' => 'Cara',
			'sep_d' => 'Dan',
		);
		foreach ( $authors as $login => $display ) {
			$this->factory()->user->create_and_get(
				array(
					'role'         => 'author',
					'user_login'   => $login,
					'display_name' => $display,
				)
			);
		}
		$post = $this->factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$this->_cap->add_coauthors( $post->ID, array( 'sep_a', 'sep_b', 'sep_c', 'sep_d' ) );

		return $post;
	}

	public function test_empty_between_string_falls_back_to_default_delimiter(): void {
		$post            = $this->post_with_four_authors();
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		$byline = coauthors( '', null, null, null, false );

		wp_reset_postdata();

		$this->assertStringContainsString(
			'Anna, Ben, Cara and Dan',
			$byline,
			'An empty-string between delimiter must fall back to the default ", " rather than dropping the middle separators.'
		);
	}

	public function test_filter_supplied_empty_between_is_still_honoured(): void {
		$post = $this->post_with_four_authors();

		add_filter( 'coauthors_default_between', '__return_empty_string' );
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		$byline = coauthors( null, null, null, null, false );

		remove_filter( 'coauthors_default_between', '__return_empty_string' );
		wp_reset_postdata();

		$this->assertStringNotContainsString(
			'Anna, Ben',
			$byline,
			'An empty separator set deliberately via the coauthors_default_between filter must still be honoured.'
		);
	}
}
