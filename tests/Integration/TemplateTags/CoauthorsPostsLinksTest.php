<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers ::coauthors_posts_links()
 */
class CoauthorsPostsLinksTest extends TestCase {

	/**
	 * Test the author filter is retained.
	 */
	public function test_the_author_filter_is_retained(): void {
		global $coauthors_plus_template_filters;
		$coauthors_plus_template_filters = new \CoAuthors_Template_Filters();
		$coauthors_plus_template_filters->register_hooks();
		$this->assertEquals( 10, has_filter( 'the_author', array( $coauthors_plus_template_filters, 'filter_the_author' ) ) );
	}

	/**
	 * Test that single author posts link is retrieved via coauthors_posts_links_single(),
	 * and suitably prefixed / suffixed.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/279
	 */
	public function test_coauthors_posts_links_for_single_author(): void {
		$author = $this->create_author();
		$post   = $this->create_post( $author );
		$GLOBALS['post'] = $post;

		$coauthors_posts_links = coauthors_posts_links( null, null, null, null, false );

		$this->assertEquals(
			'<a href="' . get_author_posts_url( $author->ID, $author->user_nicename ) . '" title="Posts by author" class="author url fn" rel="author">author</a>',
			$coauthors_posts_links,
			'Single author post link incorrect.'
		);
	}

	/**
	 * Test co-author posts links are retrieved for multiple authors and default args.
	 */
	public function test_coauthors_posts_links_for_multiple_authors_with_default_args(): void {
		global $coauthors_plus;

		$author = $this->create_author();
		$editor = $this->create_editor();
		$post   = $this->create_post( $author );
		$GLOBALS['post'] = $post;
		$coauthors_plus->add_coauthors( $post->ID, array( $editor->user_login ), true );

		$coauthors_posts_links = coauthors_posts_links( null, null, null, null, false );

		$this->assertEquals(
			'<a href="' . get_author_posts_url( $author->ID, $author->user_nicename ) . '" title="Posts by author" class="author url fn" rel="author">author</a> and <a href="' . get_author_posts_url( $editor->ID, $editor->user_nicename ) . '" title="Posts by editor" class="author url fn" rel="author">editor</a>',
			$coauthors_posts_links,
			'Multiple author post links incorrect.'
		);
	}

	/**
	 * Test that co-author posts link is retrieved via coauthors_posts_links_single() but for multiple authors.
	 */
	public function test_coauthors_posts_links_for_multiple_authors_with_amended_args(): void {
		global $coauthors_plus;

		$author1 = $this->create_author( 'author1' );
		$author2 = $this->create_author( 'author2' );
		$editor = $this->create_editor();
		$post   = $this->create_post( $author1 );
		$GLOBALS['post'] = $post;
		$coauthors_plus->add_coauthors( $post->ID, array( $author2->user_login, $editor->user_login ), true );

		$coauthors_posts_links = coauthors_posts_links( ' and ', ' & ', 'By ', '.', false );

		$this->assertEquals(
			'By <a href="' . get_author_posts_url( $author1->ID, $author1->user_nicename ) . '" title="Posts by author1" class="author url fn" rel="author">author1</a> and <a href="' . get_author_posts_url( $author2->ID, $author2->user_nicename ) . '" title="Posts by author2" class="author url fn" rel="author">author2</a> & <a href="' . get_author_posts_url( $editor->ID, $editor->user_nicename ) . '" title="Posts by editor" class="author url fn" rel="author">editor</a>.',
			$coauthors_posts_links,
			'Multiple author post links incorrect.'
		);
	}
}
