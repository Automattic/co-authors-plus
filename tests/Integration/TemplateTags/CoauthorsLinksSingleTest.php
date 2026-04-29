<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Tests for coauthors_links_single() using the author object directly.
 *
 * @see https://github.com/Automattic/co-authors-plus/issues/1131
 *
 * @covers ::coauthors_links_single()
 */
class CoauthorsLinksSingleTest extends TestCase {

	use \Yoast\PHPUnitPolyfills\Polyfills\AssertStringContains;

	/**
	 * Tear down test state.
	 *
	 * `CoAuthors_Template_Filters::__construct()` registers `the_author` and
	 * `the_author_posts_link` filters globally. Tests in this class instantiate
	 * it, so we must unhook those filters and unset the global instance to
	 * prevent state from leaking into later tests in the suite.
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
	 * Test that coauthors_links() outputs each guest author's display name
	 * exactly once when a post has multiple guest authors.
	 *
	 * Regression test for issue #1131 where all co-authors displayed the first
	 * author's name because coauthors_links_single() read from global $authordata
	 * rather than the passed $author object.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/1131
	 */
	public function test_coauthors_links_shows_each_guest_author_name_once(): void {
		global $coauthors_plus, $coauthors_plus_template_filters;

		// Activate the template filters (as a theme using coauthors_auto_apply_template_tags would).
		$coauthors_plus_template_filters = new \CoAuthors_Template_Filters();

		// Create two distinct guest authors.
		$guest_author_1_id = $this->create_guest_author( 'Jane Doe' );
		$guest_author_2_id = $this->create_guest_author( 'John Doe' );

		$this->assertIsInt( $guest_author_1_id, 'First guest author creation failed.' );
		$this->assertIsInt( $guest_author_2_id, 'Second guest author creation failed.' );

		$guest_author_1 = $coauthors_plus->get_coauthor_by( 'user_login', 'Jane Doe' );
		$guest_author_2 = $coauthors_plus->get_coauthor_by( 'user_login', 'John Doe' );

		$this->assertIsObject( $guest_author_1, 'Could not retrieve first guest author object.' );
		$this->assertIsObject( $guest_author_2, 'Could not retrieve second guest author object.' );

		// Create a post and assign both guest authors as co-authors.
		$post            = $this->create_post();
		$GLOBALS['post'] = $post;

		$coauthors_plus->add_coauthors(
			$post->ID,
			array( $guest_author_1->user_login, $guest_author_2->user_login )
		);

		$output = coauthors_links( null, null, null, null, false );

		// Each guest author's name must appear in the output exactly once.
		// These guest authors have no website so coauthors_links_single() returns
		// plain text (no anchor tag). We therefore check for the display name
		// string directly rather than for ">Name<" markup.
		$this->assertStringContainsString(
			$guest_author_1->display_name,
			$output,
			'First guest author display name not found in output.'
		);
		$this->assertStringContainsString(
			$guest_author_2->display_name,
			$output,
			'Second guest author display name not found in output.'
		);
		$this->assertEquals(
			1,
			substr_count( $output, $guest_author_1->display_name ),
			'First guest author display name must appear exactly once.'
		);
		$this->assertEquals(
			1,
			substr_count( $output, $guest_author_2->display_name ),
			'Second guest author display name must appear exactly once.'
		);
	}

	/**
	 * Test that coauthors_links_single() uses the $author object's display_name
	 * rather than reading from the global $authordata, so guest authors are
	 * rendered with their own name regardless of the current $authordata state.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/1131
	 */
	public function test_coauthors_links_single_uses_passed_author_display_name(): void {
		global $authordata;

		$author  = $this->create_author( 'lead_author' );
		$post    = $this->create_post( $author );
		$GLOBALS['post'] = $post;

		// Set $authordata to lead_author — simulating the start of a loop.
		$authordata = $author;

		// Create a distinct guest author.
		$guest_id     = $this->create_guest_author( 'Guest Writer' );
		global $coauthors_plus;
		$guest_author = $coauthors_plus->get_coauthor_by( 'user_login', 'Guest Writer' );

		$this->assertIsObject( $guest_author );

		// Even though $authordata points to lead_author, the output for the guest
		// must reflect the guest's own display_name, not the lead author's.
		$link = coauthors_links_single( $guest_author );

		$this->assertStringContainsString(
			$guest_author->display_name,
			$link,
			'coauthors_links_single() must use the $author object, not global $authordata.'
		);
		$this->assertStringNotContainsString(
			$author->display_name,
			$link,
			'coauthors_links_single() must not output the lead author name for a different guest author.'
		);
	}

	/**
	 * Test that coauthors_links_single() correctly uses the website field
	 * from the guest author object when present.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/1131
	 */
	public function test_coauthors_links_single_uses_guest_author_website(): void {
		global $coauthors_plus;

		$website_url = 'https://example.com/jane';

		$guest_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Jane With Website',
				'user_login'   => 'jane_with_website',
				'website'      => $website_url,
			)
		);

		$this->assertIsInt( $guest_id, 'Guest author with website creation failed.' );

		$guest_author = $coauthors_plus->get_coauthor_by( 'user_login', 'jane_with_website' );

		$this->assertIsObject( $guest_author );
		$this->assertEquals( 'guest-author', $guest_author->type );
		$this->assertEquals( $website_url, $guest_author->website, 'Guest author website property not set.' );

		$link = coauthors_links_single( $guest_author );

		$this->assertStringContainsString(
			$website_url,
			$link,
			'Guest author website URL must appear in the link href.'
		);
		$this->assertStringContainsString(
			$guest_author->display_name,
			$link,
			'Guest author display name must appear in the link text.'
		);
	}
}
