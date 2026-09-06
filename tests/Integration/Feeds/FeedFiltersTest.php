<?php
/**
 * Tests for co-author bylines in feeds.
 *
 * @see https://github.com/Automattic/Co-Authors-Plus/issues/736
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Feeds;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use CoAuthors_Feed_Filters;

/**
 * @covers \CoAuthors_Feed_Filters
 */
class FeedFiltersTest extends TestCase {

	use \Yoast\PHPUnitPolyfills\Polyfills\AssertStringContains;

	/**
	 * The WordPress user who published the posts under test.
	 *
	 * Deliberately not one of the co-authors, so that a byline resolved from
	 * `post_author` is visibly different from one resolved from the co-authors.
	 *
	 * @var \WP_User
	 */
	private $publisher;

	public function set_up() {
		parent::set_up();

		$this->publisher = $this->factory()->user->create_and_get(
			array(
				'role'         => 'editor',
				'user_login'   => 'publishing-editor',
				'display_name' => 'Publishing Editor',
			)
		);
	}

	/**
	 * Render the RSS2 feed template for the current query and return its markup.
	 *
	 * `do_feed()` cannot be used here because it calls `die()`. Including the core
	 * template directly is the same approach core's own feed tests take, including
	 * the silencing: the template calls `header()` after PHPUnit has already
	 * produced output, which would otherwise raise "headers already sent".
	 */
	private function render_rss2(): string {
		ob_start();

		try {
			@require ABSPATH . WPINC . '/feed-rss2.php'; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Suppresses the unavoidable header() warning; see above.
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}

		return (string) ob_get_clean();
	}

	/**
	 * Extract the contents of every dc:creator element, in document order.
	 *
	 * @param string $feed Rendered feed markup.
	 * @return string[]
	 */
	private function dc_creators( string $feed ): array {
		preg_match_all( '#<dc:creator><!\[CDATA\[(.*?)\]\]></dc:creator>#s', $feed, $matches );

		return $matches[1];
	}

	/**
	 * Create a standalone guest author (no linked WordPress user) and return it.
	 *
	 * @param string $user_login   Guest author login.
	 * @param string $display_name Guest author display name.
	 * @return object
	 */
	private function create_guest_author_with_name( string $user_login, string $display_name ) {
		$guest_author_id = $this->_cap->guest_authors->create(
			array(
				'user_login'   => $user_login,
				'display_name' => $display_name,
			)
		);

		return $this->_cap->get_coauthor_by( 'id', $guest_author_id );
	}

	/**
	 * Re-run the composition root's feed-filter registration.
	 *
	 * The `coauthors_filter_feed_authors` gate is read once, during
	 * `CoAuthors_Plus::action_init()`, which has already run by the time a test
	 * starts. Unhooking and re-running that registration is what lets a test
	 * observe the gate rather than just assert that a filter it added returns false.
	 */
	private function reregister_feed_filters(): void {
		global $coauthors_plus_feed_filters;

		if ( $coauthors_plus_feed_filters instanceof CoAuthors_Feed_Filters ) {
			remove_filter( 'the_author', array( $coauthors_plus_feed_filters, 'filter_the_author_rss' ), 15 );
			remove_action( 'rss2_item', array( $coauthors_plus_feed_filters, 'action_add_rss_guest_authors' ) );
		}

		$coauthors_plus_feed_filters = null;

		$this->_cap->action_init();
	}

	/**
	 * The regression test for the actual bug.
	 *
	 * No opt-in filter is set anywhere: this is a default install. The feed must
	 * still credit the assigned guest author rather than the user who published.
	 */
	public function test_guest_author_is_credited_in_feed_without_template_tag_opt_in(): void {
		$this->assertFalse(
			(bool) apply_filters( 'coauthors_auto_apply_template_tags', false ),
			'This test is only meaningful while the template-tag opt-in defaults to false.'
		);

		$post        = $this->create_post( $this->publisher );
		$guest_author = $this->create_guest_author_with_name( 'freelancer', 'Freelance Contributor' );

		$this->_cap->add_coauthors( $post->ID, array( $guest_author->user_login ) );

		$this->go_to( home_url( '/?feed=rss2' ) );

		$this->assertSame(
			array( 'Freelance Contributor' ),
			$this->dc_creators( $this->render_rss2() )
		);
	}

	/**
	 * Every co-author gets an element, and byline order is preserved.
	 */
	public function test_each_coauthor_gets_a_dc_creator_in_byline_order(): void {
		$post = $this->create_post( $this->publisher );

		$first  = $this->create_guest_author_with_name( 'first-byline', 'First Byline' );
		$second = $this->create_guest_author_with_name( 'second-byline', 'Second Byline' );
		$third  = $this->create_guest_author_with_name( 'third-byline', 'Third Byline' );

		$this->_cap->add_coauthors(
			$post->ID,
			array( $first->user_login, $second->user_login, $third->user_login )
		);

		$this->go_to( home_url( '/?feed=rss2' ) );

		$this->assertSame(
			array( 'First Byline', 'Second Byline', 'Third Byline' ),
			$this->dc_creators( $this->render_rss2() )
		);
	}

	/**
	 * The opt-out must fully restore core's `post_author` behaviour, so a site
	 * relying on the old output can pin it with one line.
	 */
	public function test_opt_out_filter_restores_core_post_author_behaviour(): void {
		$post         = $this->create_post( $this->publisher );
		$guest_author = $this->create_guest_author_with_name( 'freelancer', 'Freelance Contributor' );

		$this->_cap->add_coauthors( $post->ID, array( $guest_author->user_login ) );

		add_filter( 'coauthors_filter_feed_authors', '__return_false' );
		$this->reregister_feed_filters();

		global $coauthors_plus_feed_filters;
		$this->assertNull( $coauthors_plus_feed_filters, 'The feed filters must not be constructed when opted out.' );

		$this->go_to( home_url( '/?feed=rss2' ) );

		$this->assertSame(
			array( 'Publishing Editor' ),
			$this->dc_creators( $this->render_rss2() )
		);
	}

	/**
	 * The inverse of the test above: with no opt-out, the composition root does
	 * register the hooks.
	 */
	public function test_feed_filters_are_registered_by_default(): void {
		$this->reregister_feed_filters();

		global $coauthors_plus_feed_filters;

		$this->assertInstanceOf( CoAuthors_Feed_Filters::class, $coauthors_plus_feed_filters );
		$this->assertSame(
			15,
			has_filter( 'the_author', array( $coauthors_plus_feed_filters, 'filter_the_author_rss' ) )
		);
		$this->assertSame(
			10,
			has_action( 'rss2_item', array( $coauthors_plus_feed_filters, 'action_add_rss_guest_authors' ) )
		);
	}

	/**
	 * Proves the split did not leak feed filtering into template output. Outside a
	 * feed, with the template-tag opt-in at its default of false, `the_author` must
	 * resolve exactly as core would.
	 */
	public function test_the_author_is_unchanged_outside_a_feed(): void {
		$post         = $this->create_post( $this->publisher );
		$guest_author = $this->create_guest_author_with_name( 'freelancer', 'Freelance Contributor' );

		$this->_cap->add_coauthors( $post->ID, array( $guest_author->user_login ) );

		$this->go_to( get_permalink( $post->ID ) );

		$this->assertTrue( is_single(), 'Expected a single post view, not a feed.' );
		$this->assertFalse( is_feed() );
		$this->assertTrue( have_posts() );

		the_post();

		$this->assertSame( 'Publishing Editor', get_the_author() );
	}

	/**
	 * Regression test for the double-encoding fix. CDATA content is literal, so an
	 * ampersand in a display name must survive to the feed exactly as stored.
	 */
	public function test_ampersand_in_display_name_is_not_encoded_inside_cdata(): void {
		$post = $this->create_post( $this->publisher );

		$first  = $this->create_guest_author_with_name( 'smith-jones', 'Smith & Jones' );
		$second = $this->create_guest_author_with_name( 'fish-chips', 'Fish & Chips' );

		$this->_cap->add_coauthors( $post->ID, array( $first->user_login, $second->user_login ) );

		$this->go_to( home_url( '/?feed=rss2' ) );

		$feed = $this->render_rss2();

		/*
		 * Assert against the stored display names rather than the literals above, so
		 * the test measures "no encoding was added on the way out" regardless of how
		 * WordPress sanitised the name on the way in.
		 */
		$this->assertStringContainsString( '&', $first->display_name );
		$this->assertStringContainsString( '&', $second->display_name );

		$this->assertSame(
			array( $first->display_name, $second->display_name ),
			$this->dc_creators( $feed )
		);

		$this->assertStringNotContainsString(
			'&amp;amp;',
			$feed,
			'Escaping inside CDATA double-encodes the ampersand.'
		);
	}

	/**
	 * Author archive feeds already worked, because fix_author_page() pins
	 * $authordata. Confirm the new filter co-operates with it rather than
	 * fighting it.
	 */
	public function test_guest_author_archive_feed_still_renders_the_guest_author(): void {
		$post         = $this->create_post( $this->publisher );
		$guest_author = $this->create_guest_author_with_name( 'freelancer', 'Freelance Contributor' );

		$this->_cap->add_coauthors( $post->ID, array( $guest_author->user_login ) );

		$this->go_to( home_url( '/?author_name=freelancer&feed=rss2' ) );

		$this->assertTrue( is_feed() );
		$this->assertTrue( is_author() );

		$this->assertSame(
			array( 'Freelance Contributor' ),
			$this->dc_creators( $this->render_rss2() )
		);
	}
}
