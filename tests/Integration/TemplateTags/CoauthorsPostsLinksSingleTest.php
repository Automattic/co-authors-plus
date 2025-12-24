<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use Yoast\PHPUnitPolyfills\Polyfills\AssertStringContains;

/**
 * @covers ::coauthors_posts_links_single()
 */
class CoauthorsPostsLinksSingleTest extends TestCase {

	use AssertStringContains;

	/**
	 * Checks _doing_it_wrong() is called if author object is incomplete.
	 */
	public function test_null_is_returned_if_author_object_is_incomplete(): void {
		$author = $this->create_author();
		$this->setExpectedIncorrectUsage( 'coauthors_posts_links_single' );
		unset( $author->ID, $author->user_nicename, $author->display_name );

		$return = coauthors_posts_links_single( $author );
	}

	/**
	 * Checks single co-author linked to their post archive.
	 */
	public function test_single_link_is_returned_with_default_args(): void {
		$author = $this->create_author();

		$author_link = coauthors_posts_links_single( $author );

		$this->assertStringContainsString( 'href="' . get_author_posts_url( $author->ID, $author->user_nicename ) . '"', $author_link, 'Author link not found.' );
		$this->assertStringContainsString( $author->display_name, $author_link, 'Author name not found.' );
		// Here we are checking author name should not be more than one time.
		// Asserting ">{$this->author1->display_name}<" because "$this->author1->display_name" can be multiple times like in href, title, etc.
		$this->assertEquals( 1, substr_count( $author_link, ">{$author->display_name}<" ) );
	}

	/**
	 * Checks single co-author linked to their post archive.
	 */
	public function test_single_link_is_returned_with_filtered_args(): void {
		$author = $this->create_author( 'filtered-author' );
		add_filter(
			'coauthors_posts_link',
			function ( $args ) use ( $author ) {
				return array(
					'before_html' => 'Before',
					'href'        => 'https://example.com/',
					'rel'         => '',
					'title'       => null, // Would be nice for the attribute not to appear at all for this.
					'class'       => 'my-author-link',
					'text'        => apply_filters( 'the_author', $author->display_name ),
					'after_html'  => 'After',
				);
			}
		);

		$author_link = coauthors_posts_links_single( $author );

		$this->assertEquals(
			'Before<a href="https://example.com/" title="" class="my-author-link" rel="">' . $author->display_name . '</a>After',
			$author_link,
			'Author link parameters were not filtered correctly.'
		);
	}
}
