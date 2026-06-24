<?php
/**
 * WordPress-free unit tests for the Yoast integration's public filter callbacks.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Integrations;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use CoAuthors\Integrations\Yoast;

/*
 * The Yoast integration class lives in php/integrations/yoast.php, which runs
 * Yoast::init() (and therefore add_action()) at file scope when autoloaded.
 * Provide a no-op add_action() so the file can load without WordPress; Brain
 * Monkey is free to patch it again inside individual tests.
 */
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * No-op stub so php/integrations/yoast.php can be autoloaded under test.
	 *
	 * @return bool
	 */
	function add_action() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Stub for a WordPress core function.
		return true;
	}
}

/*
 * filter_graph() returns early unless get_coauthors() exists. Load the real
 * template-tags.php (it is plain functions, no WordPress required at load time)
 * so the method advances past its `function_exists()` guard to the context-post
 * guard under test. The guard returns before get_coauthors() is ever called.
 */
require_once dirname( __DIR__, 3 ) . '/template-tags.php';

/**
 * Unit coverage for the Yoast integration's public filter callbacks.
 *
 * Yoast SEO itself is not a test dependency (only `yoast/wp-test-utils` is, which
 * ships Mockery). Where the integration depends on Yoast types that are absent at
 * test time we use Mockery to generate them: a mock of the author-archive
 * presentation (so the `is_a()` guard is satisfied) and an `alias:` mock of the
 * static `\WPSEO_Options` accessor. The WordPress request functions the methods
 * call (`is_singular()`, `is_author()`, `get_post_type()`,
 * `get_queried_object_id()`) are replaced with Brain Monkey stubs.
 */
final class YoastFilterGraphTest extends TestCase {

	const PRESENTATION_CLASS = 'Yoast\\WP\\SEO\\Presentations\\Indexable_Author_Archive_Presentation';

	/**
	 * Regression coverage for issue #1113.
	 *
	 * Yoast's `wpseo_schema_graph` filter can run on a singular request whose post
	 * is not present in Yoast's indexable table, in which case the context carries
	 * no post. Before the guard, `filter_graph()` dereferenced `$context->post->ID`
	 * regardless, raising "Attempt to read property ID on null" and falling through
	 * into Yoast-only code. The method must instead return the graph untouched.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_returns_data_unchanged_when_context_post_is_null(): void {
		Functions\when( 'is_singular' )->justReturn( true );

		$context       = new \stdClass();
		$context->post = null;

		$data = array(
			array(
				'@type' => 'WebPage',
				'@id'   => 'https://example.com/#webpage',
			),
		);

		$this->assertSame(
			$data,
			Yoast::filter_graph( $data, $context ),
			'filter_graph() must return the graph unchanged when the context has no post.'
		);
	}

	/**
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_returns_data_unchanged_when_context_post_id_is_empty(): void {
		Functions\when( 'is_singular' )->justReturn( true );

		$context           = new \stdClass();
		$context->post     = new \stdClass();
		$context->post->ID = 0;

		$data = array(
			array(
				'@type' => 'WebPage',
			),
		);

		$this->assertSame(
			$data,
			Yoast::filter_graph( $data, $context ),
			'filter_graph() must return the graph unchanged when the context post has no ID.'
		);
	}

	/**
	 * Alias-mock the static `\WPSEO_Options` accessor so the integration reads a
	 * known value for the `noindex-author-wpseo` flag.
	 *
	 * @param mixed $value The value get() should return.
	 */
	private function mock_wpseo_option( $value ): void {
		\Mockery::mock( 'alias:WPSEO_Options' )
			->shouldReceive( 'get' )
			->with( 'noindex-author-wpseo', false )
			->zeroOrMoreTimes()
			->andReturn( $value );
	}

	/**
	 * Build a stand-in for Yoast's author-archive presentation so the `is_a()`
	 * guard in allow_indexing_guest_author_archive() is satisfied.
	 *
	 * @param int $is_robots_noindex The model's noindex flag (0 = indexable).
	 */
	private function mock_presentation( int $is_robots_noindex ) {
		$presentation        = \Mockery::mock( self::PRESENTATION_CLASS );
		$presentation->model = (object) array( 'is_robots_noindex' => $is_robots_noindex );
		return $presentation;
	}

	/**
	 * Place the request on a guest-author archive so the method reaches its
	 * Yoast-option and post-type guards.
	 *
	 * The integration only needs `is_author()` to be true and the queried object's
	 * post type to be `guest-author`, so we stub those WordPress functions rather
	 * than building a real query.
	 */
	private function go_to_guest_author_archive(): void {
		Functions\when( 'is_author' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 123 );
		Functions\when( 'get_post_type' )->justReturn( 'guest-author' );
	}

	/**
	 * The core of the fix: when Yoast is configured to keep author archives out of
	 * search results (`noindex-author-wpseo` = true), the robots directives must be
	 * left untouched even on a guest-author archive that would otherwise be forced
	 * to index.
	 *
	 * Before the fix this value was read with get_option(), which always returned
	 * false (Yoast stores it inside `wpseo_titles`), so the early return never
	 * fired and guest archives were always forced to index.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::allow_indexing_guest_author_archive
	 */
	public function test_allow_indexing_preserves_noindex_when_yoast_author_archives_are_disabled(): void {
		$this->go_to_guest_author_archive();
		$this->mock_wpseo_option( true );

		// is_robots_noindex = 0 means that, absent the option guard, the method
		// would otherwise force this archive to index.
		$presentation = $this->mock_presentation( 0 );

		$robots = array(
			'index'  => 'noindex',
			'follow' => 'follow',
		);

		$this->assertSame(
			$robots,
			Yoast::allow_indexing_guest_author_archive( $robots, $presentation ),
			'Robots directives must be left untouched when Yoast keeps author archives out of search results.'
		);
	}

	/**
	 * When Yoast allows author archives in search results
	 * (`noindex-author-wpseo` = false), a guest-author archive that has not been
	 * manually set to noindex must be forced to index/follow.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::allow_indexing_guest_author_archive
	 */
	public function test_allow_indexing_forces_index_when_yoast_allows_author_archives(): void {
		$this->go_to_guest_author_archive();
		$this->mock_wpseo_option( false );

		$presentation = $this->mock_presentation( 0 );

		$robots = array(
			'index'  => 'noindex',
			'follow' => 'noindex',
		);

		$result = Yoast::allow_indexing_guest_author_archive( $robots, $presentation );

		$this->assertSame( 'index', $result['index'], 'Guest author archives must be forced to index when Yoast allows author archives.' );
		$this->assertSame( 'follow', $result['follow'], 'Guest author archives must be forced to follow when Yoast allows author archives.' );
	}

	/**
	 * A guest author manually marked as noindex must keep that directive even when
	 * Yoast allows author archives generally.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::allow_indexing_guest_author_archive
	 */
	public function test_allow_indexing_respects_manual_noindex_on_guest_author(): void {
		$this->go_to_guest_author_archive();
		$this->mock_wpseo_option( false );

		$presentation = $this->mock_presentation( 1 );

		$robots = array(
			'index'  => 'noindex',
			'follow' => 'follow',
		);

		$this->assertSame(
			$robots,
			Yoast::allow_indexing_guest_author_archive( $robots, $presentation ),
			'A guest author manually set to noindex must not be forced to index.'
		);
	}

	/**
	 * The filter is a no-op on non-author requests, before any Yoast lookup.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::allow_indexing_guest_author_archive
	 */
	public function test_allow_indexing_is_noop_when_not_an_author_archive(): void {
		Functions\when( 'is_author' )->justReturn( false );

		$presentation = $this->mock_presentation( 0 );

		$robots = array( 'index' => 'noindex' );

		$this->assertSame(
			$robots,
			Yoast::allow_indexing_guest_author_archive( $robots, $presentation ),
			'The filter must return robots untouched when the request is not an author archive.'
		);
	}

	/**
	 * On a regular (non-guest) author archive the index override does not apply, so
	 * robots are returned untouched.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::allow_indexing_guest_author_archive
	 */
	public function test_allow_indexing_is_noop_for_regular_author_archive(): void {
		Functions\when( 'is_author' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 456 );
		Functions\when( 'get_post_type' )->justReturn( false );

		$this->mock_wpseo_option( false );
		$presentation = $this->mock_presentation( 0 );

		$robots = array( 'index' => 'noindex' );

		$this->assertSame(
			$robots,
			Yoast::allow_indexing_guest_author_archive( $robots, $presentation ),
			'A regular author archive must not be forced to index by the guest-author override.'
		);
	}
}
