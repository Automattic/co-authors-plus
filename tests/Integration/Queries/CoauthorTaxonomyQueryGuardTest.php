<?php
/**
 * Tests for the post-type guard shared by the author-query SQL filters.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Queries;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_Query;

/**
 * Every author-query SQL filter must leave post types outside the author
 * taxonomy alone.
 *
 * Each of posts_join_filter(), posts_where_filter(),
 * posts_where_filter_multi_author() and posts_groupby_filter() rewrites a clause to read co-author terms
 * rather than post_author. CoAuthors_Plus registers the taxonomy against its
 * supported post types once, on init, so a post type registered later has
 * author support but no author terms — and the rewrite would join against an
 * empty relationship set.
 *
 * All four filters carried their own copy of the check. These tests drive the
 * guard through each of them, across the single-author and multi-author code
 * paths, so one shared implementation is held to the same behaviour.
 *
 * @covers \CoAuthors_Plus::query_targets_coauthor_taxonomy
 * @covers \CoAuthors_Plus::posts_join_filter
 * @covers \CoAuthors_Plus::posts_where_filter
 * @covers \CoAuthors_Plus::posts_where_filter_multi_author
 * @covers \CoAuthors_Plus::posts_groupby_filter
 */
class CoauthorTaxonomyQueryGuardTest extends TestCase {

	const UNSUPPORTED_TYPE = 'cap_no_author_tax';
	const SUPPORTED_TYPE   = 'cap_with_author_tax';

	/**
	 * @var \WP_User
	 */
	private $author;

	/**
	 * @var \WP_User
	 */
	private $coauthor;

	public function set_up() {
		parent::set_up();

		// Author support, but registered too late to have been passed to
		// register_taxonomy() — the shape the guard exists for.
		register_post_type(
			self::UNSUPPORTED_TYPE,
			array(
				'public'   => true,
				'supports' => array( 'title', 'author' ),
			)
		);

		// The control: identical, but attached to the taxonomy.
		register_post_type(
			self::SUPPORTED_TYPE,
			array(
				'public'   => true,
				'supports' => array( 'title', 'author' ),
			)
		);
		register_taxonomy_for_object_type( $this->_cap->coauthor_taxonomy, self::SUPPORTED_TYPE );

		$this->author   = $this->create_author( 'guard_author' );
		$this->coauthor = $this->create_author( 'guard_coauthor' );
	}

	public function tear_down() {
		unregister_post_type( self::UNSUPPORTED_TYPE );
		unregister_post_type( self::SUPPORTED_TYPE );

		parent::tear_down();
	}

	/**
	 * The three author query shapes that reach the SQL filters.
	 *
	 * Named rather than built here, because the user IDs do not exist until
	 * set_up() has run.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_author_query_shapes(): array {
		return array(
			'single author id'         => array( 'single' ),
			'comma-separated authors'  => array( 'comma' ),
			'author__in array'         => array( 'author__in' ),
		);
	}

	/**
	 * Build the author query vars for a named shape.
	 *
	 * @param string $shape One of the keys in data_author_query_shapes().
	 * @return array
	 */
	private function author_vars( string $shape ): array {
		switch ( $shape ) {
			case 'comma':
				return array( 'author' => $this->author->ID . ',' . $this->coauthor->ID );

			case 'author__in':
				return array( 'author__in' => array( $this->author->ID, $this->coauthor->ID ) );

			default:
				return array( 'author' => $this->author->ID );
		}
	}

	/**
	 * Create a published post of the given type and run an author query for it.
	 *
	 * @param string      $post_type       Post type to create, and to query unless overridden.
	 * @param string      $shape           Author query shape.
	 * @param string|null $query_post_type Optional post_type query var to use instead of
	 *                                     $post_type. Pass 'any' or '' to exercise the
	 *                                     guard's expansion and empty branches.
	 * @return WP_Query
	 */
	private function query_for( string $post_type, string $shape, ?string $query_post_type = null ): WP_Query {
		$post_id = $this->factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_author' => $this->author->ID,
				'post_status' => 'publish',
				'post_title'  => rand_str(),
			)
		);

		// Give a taxonomy-supported post a real co-author term, so the rewrite
		// it is meant to demonstrate has something to join against.
		if ( is_object_in_taxonomy( $post_type, $this->_cap->coauthor_taxonomy ) ) {
			$this->_cap->add_coauthors( $post_id, array( $this->author->user_login ) );
		}

		return new WP_Query(
			array_merge(
				array(
					'post_type'      => $query_post_type ?? $post_type,
					'posts_per_page' => 10,
				),
				$this->author_vars( $shape )
			)
		);
	}

	/**
	 * No taxonomy JOIN or HAVING is added for a post type outside the taxonomy.
	 *
	 * @dataProvider data_author_query_shapes
	 *
	 * @param string $shape Author query shape.
	 */
	public function test_leaves_sql_alone_for_an_unsupported_post_type( string $shape ): void {
		$query = $this->query_for( self::UNSUPPORTED_TYPE, $shape );

		$this->assertStringNotContainsString( 'term_relationships', $query->request );
		$this->assertStringNotContainsString( 'term_taxonomy', $query->request );
		$this->assertStringNotContainsString( 'HAVING', $query->request );
	}

	/**
	 * The post is still returned, rather than filtered out entirely.
	 *
	 * @dataProvider data_author_query_shapes
	 *
	 * @param string $shape Author query shape.
	 */
	public function test_still_returns_posts_of_an_unsupported_post_type( string $shape ): void {
		$query = $this->query_for( self::UNSUPPORTED_TYPE, $shape );

		$this->assertCount( 1, $query->posts );
	}

	/**
	 * The control: a post type inside the taxonomy is still rewritten.
	 *
	 * Without this, every assertion above would pass just as happily against a
	 * guard that rejected every query it was given.
	 *
	 * @dataProvider data_author_query_shapes
	 *
	 * @param string $shape Author query shape.
	 */
	public function test_still_rewrites_a_supported_post_type( string $shape ): void {
		$query = $this->query_for( self::SUPPORTED_TYPE, $shape );

		$this->assertStringContainsString( 'term_relationships', $query->request );
		$this->assertCount( 1, $query->posts );
	}

	/**
	 * A post_type => 'any' query still gets the co-author rewrite.
	 *
	 * The guard cannot ask the taxonomy about the literal string 'any', so it
	 * expands it to the searchable post types first. 'post' is registered
	 * against the author taxonomy, so the SQL must be rewritten exactly as it
	 * is for a concrete supported type — without the expansion branch the
	 * lookup fails and every 'any' author query silently loses its co-authors.
	 *
	 * @dataProvider data_author_query_shapes
	 *
	 * @param string $shape Author query shape.
	 */
	public function test_still_rewrites_an_any_post_type_query( string $shape ): void {
		$query = $this->query_for( 'post', $shape, 'any' );

		$this->assertStringContainsString( 'term_relationships', $query->request );
		$this->assertCount( 1, $query->posts );
	}

	/**
	 * A query with no post type set still gets the co-author rewrite.
	 *
	 * At filter time an author archive query can carry an empty post_type
	 * query var. WordPress has not narrowed the query to anything the
	 * taxonomy could exclude, so the guard must treat it as participating and
	 * leave the rewrite in place.
	 *
	 * @dataProvider data_author_query_shapes
	 *
	 * @param string $shape Author query shape.
	 */
	public function test_still_rewrites_an_empty_post_type_query( string $shape ): void {
		$query = $this->query_for( 'post', $shape, '' );

		$this->assertStringContainsString( 'term_relationships', $query->request );
		$this->assertCount( 1, $query->posts );
	}
}
