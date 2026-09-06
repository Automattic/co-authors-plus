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

	const SCHEMA_TYPES_CLASS = 'Yoast\\WP\\SEO\\Config\\Schema_Types';

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
	 * Regression coverage for issue #1360.
	 *
	 * The PHP array_filter() function preserves keys, so removing the
	 * Yoast-generated Person node from the middle of the graph used to leave a
	 * numeric key gap behind it. With the key gap in place, json_encode()
	 * serialized @graph as a JSON object rather than an array, and JSON-LD
	 * consumers expanding that object discarded every node. The filtered graph
	 * must come back as a contiguous list instead.
	 *
	 * The merge at the end of filter_graph() re-indexes as a side effect, but it is
	 * skipped whenever no author resolves, which is the path this test drives: a
	 * post carrying an author term whose slug matches no user and no guest author.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_reindexes_the_graph_when_a_person_node_is_removed(): void {
		Functions\when( 'is_singular' )->justReturn( true );

		$this->stub_get_coauthors_dependencies();
		$this->mock_schema_types();

		$context           = new \stdClass();
		$context->post     = new \stdClass();
		$context->post->ID = 42;

		// Yoast's typical piece order on a post: the Person node sits before the
		// trailing block schema, so removing it leaves a gap behind it.
		$data = array(
			array(
				'@type' => 'Article',
				'@id'   => 'https://example.com/#article',
			),
			array(
				'@type' => 'WebPage',
				'@id'   => 'https://example.com/#webpage',
			),
			array(
				'@type' => 'ImageObject',
				'@id'   => 'https://example.com/#mainimage',
			),
			array(
				'@type' => 'BreadcrumbList',
				'@id'   => 'https://example.com/#breadcrumb',
			),
			array(
				'@type' => 'WebSite',
				'@id'   => 'https://example.com/#website',
			),
			array(
				'@type' => 'Organization',
				'@id'   => 'https://example.com/#organization',
			),
			array(
				'@type' => 'Person',
				'@id'   => 'https://example.com/#person',
			),
			array(
				'@type' => 'HowTo',
				'@id'   => 'https://example.com/#howto',
			),
		);

		$result = Yoast::filter_graph( $data, $context );

		$this->assertSame(
			array_values( $result ),
			$result,
			'filter_graph() must return the graph as a contiguous list so @graph stays a JSON array.'
		);

		$decoded = json_decode( json_encode( $result ) );
		$this->assertIsArray(
			$decoded,
			'@graph must serialize as a JSON array, not a JSON object.'
		);

		$this->assertSame(
			array( 'Article', 'WebPage', 'ImageObject', 'BreadcrumbList', 'WebSite', 'Organization', 'HowTo' ),
			array_column( $result, '@type' ),
			'The Person node must be removed and every other node kept, in order.'
		);

		$this->assertSame(
			array(),
			$result[0]['author'],
			'The article node must carry the (empty) co-author reference list.'
		);
	}

	/**
	 * Let get_coauthors() run to completion and return an empty list, the way it
	 * does when a post carries an author term whose slug matches no user and no
	 * guest author. With guest authors forced, it skips the post_author fallback
	 * and so needs no $wpdb.
	 */
	private function stub_get_coauthors_dependencies(): void {
		Functions\when( 'cap_get_coauthor_terms_for_post' )->justReturn( array() );

		$GLOBALS['coauthors_plus'] = (object) array( 'force_guest_authors' => true );
	}

	/**
	 * Stand in for Yoast's Schema_Types service, constructed inside filter_graph().
	 *
	 * Overload replaces the class definition for the remainder of the process, so
	 * only one test in a run may use this.
	 */
	private function mock_schema_types(): void {
		$types = \Mockery::mock( 'overload:' . self::SCHEMA_TYPES_CLASS );
		$types->shouldReceive( 'get_article_type_options' )
			->zeroOrMoreTimes()
			->andReturn(
				array(
					array( 'value' => 'Article' ),
				)
			);
	}

	/**
	 * Clean up the global seeded by stub_get_coauthors_dependencies().
	 */
	protected function tear_down(): void {
		unset( $GLOBALS['coauthors_plus'] );

		parent::tear_down();
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
