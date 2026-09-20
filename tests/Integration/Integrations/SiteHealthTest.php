<?php
/**
 * Tests for the Site Health check on posts with no author term.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Integrations;

use Automattic\CoAuthorsPlus\Integrations\Site_Health;
use Automattic\CoAuthorsPlus\Services\Missing_Author_Terms_Service;
use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Covers issue #1444.
 *
 * While the plugin is inactive WordPress keeps saving posts with a post_author
 * and no author term, and nobody is told to run the backfill. These tests pin
 * the two outcomes the check has to get right: silence on a healthy site, and a
 * recommended item naming the affected post types on a broken one.
 *
 * @covers \Automattic\CoAuthorsPlus\Integrations\Site_Health
 * @covers \Automattic\CoAuthorsPlus\Services\Missing_Author_Terms_Service
 */
class SiteHealthTest extends TestCase {

	/**
	 * The integration under test.
	 *
	 * @var Site_Health
	 */
	private Site_Health $site_health;

	/**
	 * Set up the test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->site_health = new Site_Health( $this->_cap );
		$this->site_health->init();

		$this->clear_count_cache();
	}

	/**
	 * Tear down the test.
	 */
	public function tear_down(): void {
		$this->clear_count_cache();
		unregister_post_type( 'cap_book' );
		// Drop the memo taken over the test post type, so later tests see the
		// list they would have got without this one.
		$this->_cap->supported_post_types = array();

		parent::tear_down();
	}

	/**
	 * Drop the cached count so each test counts for itself.
	 *
	 * @return void
	 */
	private function clear_count_cache(): void {
		delete_transient( Missing_Author_Terms_Service::COUNT_TRANSIENT );
	}

	/**
	 * Strip the author terms from a post, reproducing what happens while the
	 * plugin is inactive.
	 *
	 * @param int $post_id The post to break.
	 * @return void
	 */
	private function strip_author_terms( int $post_id ): void {
		wp_delete_object_term_relationships( $post_id, $this->_cap->coauthor_taxonomy );
	}

	public function test_the_test_is_registered_as_an_async_rest_test(): void {
		$tests = $this->site_health->register_tests( array() );

		$this->assertArrayHasKey( Site_Health::TEST_SLUG, $tests['async'] );

		$test = $tests['async'][ Site_Health::TEST_SLUG ];

		$this->assertTrue( $test['has_rest'], 'The count must run in its own request, not hold up the page.' );
		$this->assertStringContainsString(
			Site_Health::REST_NAMESPACE . '/' . Site_Health::REST_PATH,
			$test['test']
		);
		$this->assertTrue(
			is_callable( $test['async_direct_test'] ),
			'The cron path runs the test directly, so that callback has to be callable.'
		);
	}

	public function test_a_site_whose_posts_all_have_terms_is_good(): void {
		$author = $this->create_author( 'healthy_author' );
		$this->create_post( $author );

		$result = $this->site_health->get_missing_author_terms_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( Site_Health::TEST_SLUG, $result['test'] );
	}

	/**
	 * The whole point of the check: a site with term-less posts is told, and the
	 * count it is told is the real one.
	 */
	public function test_a_site_with_term_less_posts_is_told_the_count(): void {
		$author = $this->create_author( 'affected_author' );
		for ( $i = 0; $i < 3; $i++ ) {
			$post = $this->create_post( $author );
			$this->strip_author_terms( $post->ID );
		}

		$result = $this->site_health->get_missing_author_terms_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( '3', $result['label'] );
		$this->assertStringContainsString(
			'wp co-authors-plus create-author-terms-for-posts',
			$result['description'],
			'The point of the check is that the reader is told which command repairs it.'
		);
	}

	/**
	 * A post type other than post must be counted, and named, or the reader
	 * runs the command and it does nothing for the posts the check found.
	 */
	public function test_a_post_type_other_than_post_is_counted_and_named(): void {
		register_post_type(
			'cap_book',
			array(
				'public'  => true,
				'supports' => array( 'author' ),
			)
		);

		// supported_post_types() memoises once wp_loaded has fired, which it has
		// by now, so the list has to be dropped for the new type to be seen.
		$this->_cap->supported_post_types = array();

		$author = $this->create_author( 'book_author' );
		$post   = $this->factory()->post->create_and_get(
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
				'post_type'   => 'cap_book',
			)
		);
		$this->strip_author_terms( $post->ID );

		$result = $this->site_health->get_missing_author_terms_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( '--post-types=cap_book', $result['description'] );
	}

	/**
	 * Drafts and other statuses are out of scope for the check, which is about
	 * what the site is publishing.
	 */
	public function test_a_draft_with_no_terms_is_not_counted(): void {
		$author = $this->create_author( 'draft_author' );
		$post   = $this->create_post( $author );

		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'draft',
			)
		);
		$this->strip_author_terms( $post->ID );

		$result = $this->site_health->get_missing_author_terms_test();

		$this->assertSame( 'good', $result['status'] );
	}

	/**
	 * Posts the backfill has marked as unrepairable are left out, so the check
	 * does not keep recommending a command that cannot fix them.
	 */
	public function test_a_post_marked_as_skipped_is_not_counted(): void {
		$author = $this->create_author( 'skipped_author' );
		$post   = $this->create_post( $author );
		$this->strip_author_terms( $post->ID );

		add_post_meta(
			$post->ID,
			Missing_Author_Terms_Service::SKIP_POST_FOR_BACKFILL_META_KEY,
			'nonexistent_post_author_id',
			true
		);

		$result = $this->site_health->get_missing_author_terms_test();

		$this->assertSame( 'good', $result['status'] );
	}

	public function test_the_count_is_cached(): void {
		$author = $this->create_author( 'cached_author' );
		$post   = $this->create_post( $author );
		$this->strip_author_terms( $post->ID );

		$this->site_health->get_missing_author_terms_test();

		$this->assertNotFalse(
			get_transient( Missing_Author_Terms_Service::COUNT_TRANSIENT ),
			'The query behind this does not get cheaper on a large site, so the result has to be cached.'
		);
	}

	/**
	 * Repairing a site then reloading Site Health must not keep showing the old
	 * figure until the cache happens to expire.
	 */
	public function test_clearing_the_cache_makes_the_next_run_recount(): void {
		$author = $this->create_author( 'recount_author' );
		$post   = $this->create_post( $author );
		$this->strip_author_terms( $post->ID );

		$this->assertSame( 'recommended', $this->site_health->get_missing_author_terms_test()['status'] );

		// Put the terms back, the way the backfill command does.
		$this->_cap->add_coauthors( $post->ID, array( $author->user_nicename ) );
		( new Missing_Author_Terms_Service( $this->_cap ) )->clear_count_cache();

		$this->assertSame( 'good', $this->site_health->get_missing_author_terms_test()['status'] );
	}

	/**
	 * Core does not catch an exception thrown by a test callback, so a count that
	 * fails must come back as a result rather than a fatal. Saying nothing was
	 * found would be worse: the reader would think the site was clean.
	 */
	public function test_a_failed_count_is_reported_rather_than_fatal(): void {
		$author = $this->create_author( 'failed_count_author' );
		$post   = $this->create_post( $author );
		$this->strip_author_terms( $post->ID );

		// Break only the count query, by handing wpdb an empty one for it.
		$break_count_query = static function ( $query ) {
			return ( false !== strpos( $query, 'SELECT COUNT(*)' ) ) ? '' : $query;
		};

		add_filter( 'query', $break_count_query );

		try {
			$result = $this->site_health->get_missing_author_terms_test();
		} finally {
			remove_filter( 'query', $break_count_query );
		}

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringNotContainsString( 'create-author-terms-for-posts', $result['description'] );
		$this->assertSame( Site_Health::TEST_SLUG, $result['test'] );
	}
}
