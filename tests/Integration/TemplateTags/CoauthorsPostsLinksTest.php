<?php
/**
 * Tests for the coauthors_posts_links() template tag.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

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
	 * Test that a guest author byline links to the guest author archive rather
	 * than the user who originally published the post, with pretty permalinks.
	 *
	 * The plugin links guest authors via the author_name query argument, which
	 * matches how guest author links are built elsewhere in the plugin.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/1351
	 */
	public function test_coauthors_posts_links_for_single_guest_author_with_pretty_permalinks(): void {
		$this->assert_single_guest_author_byline_links_to_guest_archive( '/%postname%/' );
	}

	/**
	 * Test that a guest author byline links to the guest author archive rather
	 * than the user who originally published the post, with plain permalinks.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/1351
	 */
	public function test_coauthors_posts_links_for_single_guest_author_with_plain_permalinks(): void {
		$this->assert_single_guest_author_byline_links_to_guest_archive( '' );
	}

	/**
	 * Assert that a post whose single coauthor is a guest author renders a byline
	 * pointing to the guest author's own archive, and not to the user who
	 * originally published the post.
	 *
	 * The expected URL is built from the guest author's nicename directly rather
	 * than by re-running the production author_link filter, so expected and actual
	 * cannot share a source of truth.
	 *
	 * @param string $permalink_structure Permalink structure to test with.
	 */
	private function assert_single_guest_author_byline_links_to_guest_archive( string $permalink_structure ): void {
		global $coauthors_plus;

		$this->set_permalink_structure( $permalink_structure );

		$publisher    = $this->create_author( 'publisher-bob' );
		$guest_author = $this->create_guest_author( 'jane-guest' );
		$guest_object = $coauthors_plus->guest_authors->get_guest_author_by( 'ID', $guest_author );
		$post         = $this->create_post( $publisher );
		$coauthors_plus->add_coauthors( $post->ID, array( 'jane-guest' ), false );

		$coauthors = get_coauthors( $post->ID );
		$this->assertCount( 1, $coauthors );
		$this->assertIsGuestAuthorNotWpUser( $coauthors[0] );
		$this->assertSame( 'jane-guest', $coauthors[0]->user_nicename );

		// Simulate the frontend rendering of the byline from a fresh URL context.
		$this->go_to( get_permalink( $post->ID ) );

		// The plugin links guest authors through the author_name query argument,
		// regardless of the site's permalink style.
		$expected_href = add_query_arg( 'author_name', rawurlencode( $guest_object->user_nicename ), home_url() );

		$byline = coauthors_posts_links( null, null, null, null, false );

		$this->assertSame(
			'<a href="' . $expected_href . '" title="Posts by jane-guest" class="author url fn" rel="author">jane-guest</a>',
			$byline,
			'Guest author post link does not point to the guest author archive.'
		);
		$this->assertStringNotContainsString(
			'publisher-bob',
			$byline,
			'Byline must not link to the user who originally published the post.'
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
