<?php
/**
 * WordPress-free unit tests for the Yoast integration's public filter callbacks.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Integrations;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Filters;
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

/*
 * Load the guarded Yoast stubs before the integration class is autoloaded, so
 * the `new Schema_Types()` and `new CoAuthor()` inside filter_graph() never
 * reach for an absent Yoast autoloader. filter_graph() also tells users from
 * guest authors with an instanceof check, which needs the WP_User stub.
 */
require_once __DIR__ . '/yoast-stubs.php';
require_once dirname( __DIR__ ) . '/wp-stubs.php';

/**
 * Unit coverage for the Yoast integration's public filter callbacks.
 *
 * Yoast SEO itself is not a test dependency (only `yoast/wp-test-utils` is, which
 * ships Mockery). Where the integration depends on Yoast types that are absent at
 * test time we use Mockery to generate them: a mock of the author-archive
 * presentation (so the `is_a()` guard is satisfied), an `alias:` mock of the
 * static `\WPSEO_Options` accessor, and guarded plain stub classes for the
 * Yoast classes filter_graph() relies on (see yoast-stubs.php). The WordPress
 * request functions the methods call (`is_singular()`, `is_author()`,
 * `get_post_type()`, `get_queried_object_id()`) are replaced with Brain Monkey
 * stubs.
 */
final class YoastFilterGraphTest extends TestCase {

	const PRESENTATION_CLASS = 'Yoast\\WP\\SEO\\Presentations\\Indexable_Author_Archive_Presentation';

	const PERSON_ID = 'https://example.com/#/schema/person/';

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
	 * A post can carry an author term whose slug matches no user and no guest
	 * author, so get_coauthors() resolves nobody. With no author to swap in,
	 * blanking the article's author and removing Yoast's Person node would leave
	 * the remaining nodes pointing at a Person that is no longer in the graph.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_returns_data_unchanged_when_no_author_resolves(): void {
		Functions\when( 'is_singular' )->justReturn( true );

		$this->stub_get_coauthors_dependencies();

		$data = $this->graph_with_person_node();

		$this->assertSame(
			$data,
			Yoast::filter_graph( $data, $this->context_for_site( 'company', 0 ) ),
			'filter_graph() must leave the graph untouched when no co-author resolves.'
		);
	}

	/**
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_replaces_the_person_node_with_the_resolved_author(): void {
		$this->stub_resolved_author();

		$result = Yoast::filter_graph(
			$this->graph_with_person_node(),
			$this->context_for_site( 'company', 0 )
		);

		$this->assertSame(
			array( 'Article', 'WebPage', 'ImageObject', 'BreadcrumbList', 'WebSite', 'Organization', 'HowTo', 'Person' ),
			array_column( $result, '@type' ),
			'The resolved author must replace the Person node Yoast generated.'
		);

		$this->assertSame(
			array( array( '@id' => 'https://example.com/#/schema/person/7' ) ),
			$result[0]['author'],
			'The article node must reference the resolved author.'
		);

		$this->assertSame(
			'https://example.com/#/schema/person/7',
			$result[7]['@id'],
			'The graph must carry the resolved author\'s own Person node.'
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
	 * The merge at the end of filter_graph() re-indexes as a side effect, but it
	 * adds nothing when the author is the person the site represents and Yoast
	 * already output their node, which is the path this test drives.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_reindexes_the_graph_when_a_person_node_is_removed(): void {
		$this->stub_resolved_author();

		$data    = $this->graph_with_person_node();
		$data[5] = array(
			'@type' => array( 'Person', 'Organization' ),
			'@id'   => self::PERSON_ID . '7',
		);

		$result = Yoast::filter_graph( $data, $this->context_for_site( 'person', 7 ) );

		$this->assertSame(
			array_values( $result ),
			$result,
			'filter_graph() must return the graph as a contiguous list so @graph stays a JSON array.'
		);

		$this->assertSame(
			array( 'Article', 'WebPage', 'ImageObject', 'BreadcrumbList', 'WebSite', array( 'Person', 'Organization' ), 'HowTo' ),
			array_column( $result, '@type' ),
			'The Person node must be removed and every other node kept, in order.'
		);
	}

	/**
	 * Every co-author must get their own Person node, and Yoast's own author
	 * node must be removed.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_adds_a_person_node_for_every_co_author(): void {
		$this->stub_coauthors( array( $this->user( 7 ), $this->user( 9 ) ) );

		$result = Yoast::filter_graph( $this->yoast_graph( false ), $this->context_for_site( 'company', 0 ) );

		$this->assertSame(
			array( array( '@id' => self::PERSON_ID . '7' ), array( '@id' => self::PERSON_ID . '9' ) ),
			$result[0]['author'],
			'The article node must reference every co-author.'
		);

		$this->assertSame(
			array( self::PERSON_ID . '7', self::PERSON_ID . '9' ),
			$this->person_ids( $result ),
			'Each co-author must have one Person node, and Yoast\'s author node must be gone.'
		);
	}

	/**
	 * Regression coverage for issue #1562.
	 *
	 * When the site person is the last co-author, the other co-authors must still
	 * get their Person nodes. The site person keeps Yoast's own node.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_keeps_other_co_authors_when_site_person_is_last(): void {
		$this->stub_coauthors( array( $this->user( 7 ), $this->user( 9 ) ) );

		$result = Yoast::filter_graph( $this->yoast_graph( true ), $this->context_for_site( 'person', 9 ) );

		$this->assertSame(
			array( array( '@id' => self::PERSON_ID . '7' ), array( '@id' => self::PERSON_ID . '9' ) ),
			$result[0]['author'],
			'The article node must reference every co-author, the site person included.'
		);

		$this->assertSame(
			array( self::PERSON_ID . '9', self::PERSON_ID . '7' ),
			$this->person_ids( $result ),
			'The site person node must be kept and the other co-author added once.'
		);
	}

	/**
	 * Regression coverage for issue #1562.
	 *
	 * When the site person is not the last co-author, no second Person node may
	 * be added for them.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_skips_site_person_node_when_site_person_is_first(): void {
		$this->stub_coauthors( array( $this->user( 9 ), $this->user( 7 ) ) );

		$result = Yoast::filter_graph( $this->yoast_graph( true ), $this->context_for_site( 'person', 9 ) );

		$this->assertSame(
			array( self::PERSON_ID . '9', self::PERSON_ID . '7' ),
			$this->person_ids( $result ),
			'The site person must not get a second Person node.'
		);
	}

	/**
	 * Regression coverage for issue #1562.
	 *
	 * A guest author post ID is not a user ID, so a guest author whose ID equals
	 * the site user ID is not the site person.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_does_not_treat_a_guest_author_as_the_site_person(): void {
		$this->stub_coauthors( array( $this->guest_author( 9 ) ) );

		$result = Yoast::filter_graph( $this->yoast_graph( true ), $this->context_for_site( 'person', 9 ) );

		$guest_id = 'https://example.com/#/schema/person/image/guest-99guest';

		$this->assertSame(
			array( array( '@id' => $guest_id ) ),
			$result[0]['author'],
			'The article node must reference the guest author.'
		);

		$this->assertSame(
			array( self::PERSON_ID . '9', $guest_id ),
			$this->person_ids( $result ),
			'The guest author must get their own Person node.'
		);
	}

	/**
	 * Regression coverage for issue #1562.
	 *
	 * A last co-author that produces no Person node must not drop the nodes of
	 * the co-authors before it.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_keeps_earlier_co_authors_when_last_one_generates_nothing(): void {
		$this->stub_coauthors( array( $this->user( 7 ), $this->user( 0 ) ) );

		$result = Yoast::filter_graph( $this->yoast_graph( false ), $this->context_for_site( 'company', 0 ) );

		$this->assertSame(
			array( array( '@id' => self::PERSON_ID . '7' ) ),
			$result[0]['author'],
			'Only the co-author with a Person node may be referenced.'
		);

		$this->assertSame(
			array( self::PERSON_ID . '7' ),
			$this->person_ids( $result ),
			'The earlier co-author must keep their Person node.'
		);
	}

	/**
	 * Regression coverage for issue #1562.
	 *
	 * An object that is neither a user nor a guest author must not repeat the
	 * previous co-author's Person node.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_does_not_repeat_a_person_node_for_an_unknown_object(): void {
		$this->stub_coauthors( array( $this->user( 7 ), (object) array( 'ID' => 11 ) ) );

		$result = Yoast::filter_graph( $this->yoast_graph( false ), $this->context_for_site( 'company', 0 ) );

		$this->assertSame(
			array( array( '@id' => self::PERSON_ID . '7' ) ),
			$result[0]['author'],
			'The unknown object must not add a reference.'
		);

		$this->assertSame(
			array( self::PERSON_ID . '7' ),
			$this->person_ids( $result ),
			'The previous co-author\'s Person node must not be added twice.'
		);
	}

	/**
	 * A graph node without an @type must not raise a warning and must be kept.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_handles_a_node_without_a_type(): void {
		$this->stub_resolved_author();

		$data = $this->yoast_graph( false );
		array_unshift( $data, array( '@id' => 'https://example.com/#untyped' ) );

		$result = Yoast::filter_graph( $data, $this->context_for_site( 'company', 0 ) );

		$this->assertSame( 'https://example.com/#untyped', $result[0]['@id'], 'The node without an @type must be kept.' );
		$this->assertSame(
			array( array( '@id' => self::PERSON_ID . '7' ) ),
			$result[1]['author'],
			'The article node must still reference the co-author.'
		);
	}

	/**
	 * If the graph has no node for the site person, their own Person node must be
	 * added so the article reference does not dangle.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_adds_site_person_node_when_graph_has_none(): void {
		$this->stub_coauthors( array( $this->user( 9 ) ) );

		$result = Yoast::filter_graph( $this->yoast_graph( false ), $this->context_for_site( 'person', 9 ) );

		$this->assertSame(
			array( self::PERSON_ID . '9' ),
			$this->person_ids( $result ),
			'The site person must get a Person node when Yoast did not output one.'
		);
	}

	/**
	 * An article node can carry several types, which Yoast leaves as an array, and
	 * must still have its author reference replaced.
	 *
	 * @covers \CoAuthors\Integrations\Yoast::filter_graph
	 */
	public function test_filter_graph_handles_an_article_with_several_types(): void {
		$this->stub_resolved_author();

		$data             = $this->yoast_graph( false );
		$data[0]['@type'] = array( 'Article', 'Report' );

		$result = Yoast::filter_graph( $data, $this->context_for_site( 'company', 0 ) );

		$this->assertSame(
			array( array( '@id' => self::PERSON_ID . '7' ) ),
			$result[0]['author'],
			'The article node must reference the co-author.'
		);

		$this->assertSame(
			array( self::PERSON_ID . '7' ),
			$this->person_ids( $result ),
			'The co-author node must replace Yoast\'s author node.'
		);
	}

	/**
	 * Person @ids from a graph, in order.
	 *
	 * @param array $graph The schema graph.
	 * @return string[]
	 */
	private function person_ids( array $graph ): array {
		$ids = array();
		foreach ( $graph as $piece ) {
			if ( ! empty( $piece['@type'] ) && in_array( 'Person', (array) $piece['@type'], true ) ) {
				$ids[] = $piece['@id'];
			}
		}

		return $ids;
	}

	/**
	 * A graph shaped like the one Yoast passes to its wpseo_schema_graph filter.
	 * Yoast has already flattened single-item types to strings by then, so only the
	 * site person, typed [ 'Person', 'Organization' ], still has an array.
	 *
	 * @param bool $site_is_person Whether the site represents user 9.
	 * @return array
	 */
	private function yoast_graph( bool $site_is_person ): array {
		$graph = array(
			array(
				'@type'  => 'Article',
				'@id'    => 'https://example.com/#article',
				'author' => array( '@id' => self::PERSON_ID . 'post-author' ),
			),
			array(
				'@type' => 'WebPage',
				'@id'   => 'https://example.com/#webpage',
			),
		);

		if ( $site_is_person ) {
			$graph[] = array(
				'@type' => array( 'Person', 'Organization' ),
				'@id'   => self::PERSON_ID . '9',
			);
		}

		$graph[] = array(
			'@type' => 'Person',
			'@id'   => self::PERSON_ID . 'post-author',
		);

		return $graph;
	}

	/**
	 * Yoast's typical piece order on a post: the Person node sits before the
	 * trailing block schema, so removing it leaves a gap behind it.
	 *
	 * @return array
	 */
	private function graph_with_person_node(): array {
		return array(
			array(
				'@type'  => 'Article',
				'@id'    => 'https://example.com/#article',
				'author' => array( '@id' => 'https://example.com/#person' ),
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
	}

	/**
	 * Build the Yoast context filter_graph() reads for a post.
	 *
	 * @param string $site_represents Whether the site represents a person or company.
	 * @param int    $site_user_id    The ID of the user the site represents.
	 */
	private function context_for_site( string $site_represents, int $site_user_id ): \stdClass {
		$context                  = new \stdClass();
		$context->post            = new \stdClass();
		$context->post->ID        = 42;
		$context->site_represents = $site_represents;
		$context->site_user_id    = $site_user_id;
		$context->site_url        = 'https://example.com/';

		return $context;
	}

	/**
	 * Make get_coauthors() resolve one user, with ID 7, and let the real CoAuthor
	 * generator build their Person node.
	 */
	private function stub_resolved_author(): void {
		$this->stub_coauthors( array( $this->user( 7 ) ) );
	}

	/**
	 * Make get_coauthors() resolve the given co-authors, and let the real CoAuthor
	 * generator build their Person nodes.
	 *
	 * @param array $authors The co-author objects get_coauthors() returns.
	 */
	private function stub_coauthors( array $authors ): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'wp_hash' )->returnArg();
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );

		$this->stub_get_coauthors_dependencies();

		Filters\expectApplied( 'get_coauthors' )->andReturn( $authors );

		$options = \Mockery::mock();
		$options->shouldReceive( 'get' )->with( 'disable-author' )->andReturn( true );

		$html = \Mockery::mock();
		$html->shouldReceive( 'smart_strip_tags' )->andReturnUsing(
			static function ( $text ) {
				return $text;
			}
		);

		Functions\when( 'YoastSEO' )->justReturn(
			(object) array(
				'helpers' => (object) array(
					'options' => $options,
					'schema'  => (object) array( 'html' => $html ),
				),
			)
		);
	}

	/**
	 * Build a WordPress user co-author.
	 *
	 * @param int $id The user ID.
	 */
	private function user( int $id ): \WP_User {
		$user = new class() extends \WP_User {
			/**
			 * The user ID.
			 *
			 * @var int
			 */
			public $ID = 0;
		};

		$user->ID = $id;

		return $user;
	}

	/**
	 * Build a guest author co-author.
	 *
	 * @param int $id The guest author post ID.
	 */
	private function guest_author( int $id ): \stdClass {
		return (object) array(
			'ID'            => $id,
			'type'          => 'guest-author',
			'user_login'    => 'guest-' . $id,
			'user_nicename' => 'guest-' . $id,
			'display_name'  => 'Guest ' . $id,
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
