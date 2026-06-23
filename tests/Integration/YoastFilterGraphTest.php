<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use CoAuthors\Integrations\Yoast;

/**
 * Unit coverage for the Yoast integration's public filter callbacks.
 *
 * Yoast SEO itself is not a test dependency (only `yoast/wp-test-utils` is, which
 * ships Mockery). Where the integration depends on Yoast types that are absent at
 * test time we use Mockery to generate them: a mock of the author-archive
 * presentation (so the `is_a()` guard is satisfied) and an `alias:` mock of the
 * static `\WPSEO_Options` accessor.
 */
class YoastFilterGraphTest extends TestCase {

	const PRESENTATION_CLASS = 'Yoast\\WP\\SEO\\Presentations\\Indexable_Author_Archive_Presentation';

	public function tear_down() {
		\Mockery::close();
		parent::tear_down();
	}

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
		$post_id = $this->factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular(), 'The guard is only reached on a singular request.' );

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
		$post_id = $this->factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular(), 'The guard is only reached on a singular request.' );

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
	 * @return int The guest author post ID.
	 */
	private function go_to_guest_author_archive( string $user_login ): int {
		global $wp_query, $coauthors_plus;

		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'user_login'   => $user_login,
				'display_name' => $user_login,
			)
		);

		$wp_query->is_author         = true;
		$wp_query->is_archive        = true;
		$wp_query->queried_object    = get_post( $guest_author_id );
		$wp_query->queried_object_id = $guest_author_id;

		$this->assertTrue( is_author(), 'The request must be an author archive for the guard to be reached.' );
		$this->assertSame( 'guest-author', get_post_type( $guest_author_id ) );

		return $guest_author_id;
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
		$this->go_to_guest_author_archive( 'guest-noindex-on' );
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
		$this->go_to_guest_author_archive( 'guest-noindex-off' );
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
		$this->go_to_guest_author_archive( 'guest-manual-noindex' );
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
		$post_id = $this->factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );
		$this->assertFalse( is_author(), 'This request must not be an author archive.' );

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
		global $wp_query;

		$user_id = $this->create_author()->ID;

		$wp_query->is_author         = true;
		$wp_query->is_archive        = true;
		$wp_query->queried_object    = get_user_by( 'id', $user_id );
		$wp_query->queried_object_id = $user_id;

		$this->assertTrue( is_author() );
		$this->assertNotSame( 'guest-author', get_post_type( $user_id ) );

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
