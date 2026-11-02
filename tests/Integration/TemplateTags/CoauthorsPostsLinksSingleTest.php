<?php
/**
 * Tests for the coauthors_posts_links_single() template tag.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use Yoast\PHPUnitPolyfills\Polyfills\AssertStringContains;

/**
 * @covers ::coauthors_posts_links_single()
 */
class CoauthorsPostsLinksSingleTest extends TestCase {

	use AssertStringContains;

	/**
	 * Unhook and unset the template filters some tests register, so the global
	 * instance does not leak into later tests.
	 */
	public function tear_down() {
		global $coauthors_plus_template_filters;

		if ( $coauthors_plus_template_filters instanceof \CoAuthors_Template_Filters ) {
			remove_filter( 'the_author', array( $coauthors_plus_template_filters, 'filter_the_author' ) );
			remove_filter( 'the_author_posts_link', array( $coauthors_plus_template_filters, 'filter_the_author_posts_link' ) );
			remove_filter( 'the_author', array( $coauthors_plus_template_filters, 'filter_the_author_rss' ), 15 );
		}

		$coauthors_plus_template_filters = null;

		parent::tear_down();
	}

	/**
	 * Register the template filters, as coauthors_auto_apply_template_tags does,
	 * and set up a post with two co-authors.
	 *
	 * @return \WP_User The second co-author.
	 */
	private function auto_apply_with_two_coauthors(): \WP_User {
		global $coauthors_plus, $coauthors_plus_template_filters;

		$coauthors_plus_template_filters = new \CoAuthors_Template_Filters();
		$coauthors_plus_template_filters->register_hooks();

		$author1         = $this->create_author( 'author1' );
		$author2         = $this->create_author( 'author2' );
		$GLOBALS['post'] = $this->create_post( $author1 );
		$coauthors_plus->add_coauthors( $GLOBALS['post']->ID, array( $author2->user_login ), true );

		return $author2;
	}

	/**
	 * With template tags auto-applied, a link built directly must be labelled
	 * with that co-author's own name, not the whole co-author list.
	 */
	public function test_link_uses_own_name_when_template_tags_are_auto_applied(): void {
		$author = $this->auto_apply_with_two_coauthors();

		$this->assertSame(
			'<a href="' . get_author_posts_url( $author->ID, $author->user_nicename ) . '" title="Posts by author2" class="author url fn" rel="author">author2</a>',
			coauthors_posts_links_single( $author )
		);
	}

	/**
	 * Other the_author filters still apply to the link text and title.
	 */
	public function test_link_still_applies_other_the_author_filters(): void {
		$author = $this->auto_apply_with_two_coauthors();
		add_filter(
			'the_author',
			static function ( $name ) {
				return 'Dr ' . $name;
			},
			20
		);

		$link = coauthors_posts_links_single( $author );

		$this->assertStringContainsString( 'title="Posts by Dr author2"', $link );
		$this->assertStringContainsString( '>Dr author2</a>', $link );
	}

	/**
	 * A coauthors_posts_link callback that runs the_author gets this co-author's
	 * name, as it already does when called through coauthors_posts_links().
	 */
	public function test_coauthors_posts_link_callback_sees_own_name(): void {
		$author = $this->auto_apply_with_two_coauthors();
		add_filter(
			'coauthors_posts_link',
			static function ( $args, $author ) {
				$args['text'] = apply_filters( 'the_author', $author->display_name );
				return $args;
			},
			10,
			2
		);

		$this->assertStringContainsString( '>author2</a>', coauthors_posts_links_single( $author ) );
		$this->assertStringContainsString( '>author2</a>', coauthors_posts_links( null, null, null, null, false ) );
	}

	/**
	 * The template filter is back in place, at its priority, after the link is built.
	 */
	public function test_template_filter_is_restored_after_link_is_built(): void {
		global $coauthors_plus_template_filters;

		$author = $this->auto_apply_with_two_coauthors();
		coauthors_posts_links_single( $author );
		coauthors_posts_links( null, null, null, null, false );

		$this->assertSame( 10, has_filter( 'the_author', array( $coauthors_plus_template_filters, 'filter_the_author' ) ) );
	}

	/**
	 * A template filter the site has removed is not added back.
	 */
	public function test_removed_template_filter_is_not_added_back(): void {
		global $coauthors_plus_template_filters;

		$author = $this->auto_apply_with_two_coauthors();
		remove_filter( 'the_author', array( $coauthors_plus_template_filters, 'filter_the_author' ) );

		coauthors_posts_links_single( $author );
		coauthors_posts_links( null, null, null, null, false );

		$this->assertFalse( has_filter( 'the_author', array( $coauthors_plus_template_filters, 'filter_the_author' ) ) );
	}

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
