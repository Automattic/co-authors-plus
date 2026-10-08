<?php
/**
 * Tests for the author-query taxonomy rewrite on custom post types.
 *
 * Sites that add `author` support to a custom post type (WooCommerce products
 * being the common case) rely on the same rewrite that powers author archives
 * for posts. These tests cover the query shapes such archives actually run.
 *
 * @see https://github.com/Automattic/co-authors-plus/issues/1366
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Queries;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_Query;

/**
 * @covers \CoAuthors_Plus::posts_where_filter
 * @covers \CoAuthors_Plus::posts_join_filter
 * @covers \CoAuthors_Plus::posts_groupby_filter
 */
class CustomPostTypeAuthorQueriesTest extends TestCase {

	/**
	 * Register a custom post type with author support, attached to the author
	 * taxonomy exactly as CoAuthors_Plus::action_init_late() would have done had
	 * the post type existed when the plugin booted.
	 *
	 * @param string $post_type Post type name.
	 */
	private function register_authored_post_type( string $post_type ): void {
		register_post_type(
			$post_type,
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor', 'author' ),
			)
		);

		register_taxonomy_for_object_type( 'author', $post_type );
	}

	/**
	 * Create a published post of the given type owned by $owner, with $coauthors
	 * assigned through the plugin.
	 *
	 * @param string   $post_type Post type name.
	 * @param \WP_User $owner     The post_author.
	 * @param string[] $coauthors Co-author user logins.
	 * @return int The new post ID.
	 */
	private function create_authored_post( string $post_type, \WP_User $owner, array $coauthors ): int {
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $owner->ID,
				'post_status' => 'publish',
				'post_type'   => $post_type,
				'post_title'  => rand_str(),
			)
		);

		$this->_cap->add_coauthors( $post_id, $coauthors );

		return $post_id;
	}

	/**
	 * The baseline: a co-author who is not the post_author must find the CPT post.
	 */
	public function test_author_name_finds_a_custom_post_type_post_for_a_coauthor(): void {
		$this->register_authored_post_type( 'book' );

		$owner  = $this->create_author( 'owner' );
		$writer = $this->create_author( 'writer' );

		$book_id = $this->create_authored_post( 'book', $owner, array( $owner->user_login, $writer->user_login ) );

		$query = new WP_Query(
			array(
				'post_type'   => 'book',
				'author_name' => $writer->user_login,
			)
		);

		$this->assertSame( array( $book_id ), wp_list_pluck( $query->posts, 'ID' ) );
	}

	/**
	 * A guest author has no post_author of their own, so the taxonomy rewrite is
	 * the only way their CPT posts can be found.
	 */
	public function test_author_name_finds_a_custom_post_type_post_for_a_guest_author(): void {
		$this->register_authored_post_type( 'book' );

		$owner = $this->create_author( 'owner' );
		$guest = $this->create_guest_author( 'guest_writer' );
		$guest = $this->_cap->get_coauthor_by( 'id', $guest );

		$book_id = $this->create_authored_post( 'book', $owner, array( $guest->user_nicename ) );

		$query = new WP_Query(
			array(
				'post_type'   => 'book',
				'author_name' => $guest->user_nicename,
			)
		);

		$this->assertSame( array( $book_id ), wp_list_pluck( $query->posts, 'ID' ) );
	}

	/**
	 * Archive templates commonly query several post types at once, only some of
	 * which are attached to the author taxonomy.
	 */
	public function test_author_name_finds_the_post_when_the_query_spans_several_post_types(): void {
		$this->register_authored_post_type( 'book' );
		register_post_type( 'book_variation', array( 'public' => false ) );

		$owner  = $this->create_author( 'owner' );
		$writer = $this->create_author( 'writer' );

		$book_id = $this->create_authored_post( 'book', $owner, array( $owner->user_login, $writer->user_login ) );

		$query = new WP_Query(
			array(
				'post_type'   => array( 'book', 'book_variation' ),
				'author_name' => $writer->user_login,
			)
		);

		$this->assertSame( array( $book_id ), wp_list_pluck( $query->posts, 'ID' ) );
	}

	/**
	 * WooCommerce always adds a `product_visibility` tax query to product queries,
	 * so the author rewrite has to survive alongside an unrelated taxonomy JOIN.
	 */
	public function test_author_name_finds_the_post_when_the_query_carries_an_unrelated_tax_query(): void {
		$this->register_authored_post_type( 'book' );
		register_taxonomy( 'book_visibility', 'book', array( 'public' => false ) );

		$owner  = $this->create_author( 'owner' );
		$writer = $this->create_author( 'writer' );

		$book_id = $this->create_authored_post( 'book', $owner, array( $owner->user_login, $writer->user_login ) );

		$hidden = $this->factory()->term->create(
			array(
				'taxonomy' => 'book_visibility',
				'name'     => 'hidden',
			)
		);

		$query = new WP_Query(
			array(
				'post_type'   => 'book',
				'author_name' => $writer->user_login,
				'tax_query'   => array(
					array(
						'taxonomy' => 'book_visibility',
						'field'    => 'term_id',
						'terms'    => array( $hidden ),
						'operator' => 'NOT IN',
					),
				),
			)
		);

		$this->assertSame(
			'',
			$GLOBALS['wpdb']->last_error,
			"The generated SQL must be valid:\n" . $query->request
		);
		$this->assertSame( array( $book_id ), wp_list_pluck( $query->posts, 'ID' ), $query->request );
	}

	/**
	 * The same, with an `IN` tax query, which WP_Tax_Query joins differently.
	 */
	public function test_author_name_finds_the_post_when_the_query_carries_an_in_tax_query(): void {
		$this->register_authored_post_type( 'book' );
		register_taxonomy( 'genre', 'book', array( 'public' => true ) );

		$owner  = $this->create_author( 'owner' );
		$writer = $this->create_author( 'writer' );

		$book_id = $this->create_authored_post( 'book', $owner, array( $owner->user_login, $writer->user_login ) );

		$genre = $this->factory()->term->create(
			array(
				'taxonomy' => 'genre',
				'name'     => 'fiction',
			)
		);
		wp_set_object_terms( $book_id, array( $genre ), 'genre' );

		$query = new WP_Query(
			array(
				'post_type'   => 'book',
				'author_name' => $writer->user_login,
				'tax_query'   => array(
					array(
						'taxonomy' => 'genre',
						'field'    => 'term_id',
						'terms'    => array( $genre ),
					),
				),
			)
		);

		$this->assertSame(
			'',
			$GLOBALS['wpdb']->last_error,
			"The generated SQL must be valid:\n" . $query->request
		);
		$this->assertSame( array( $book_id ), wp_list_pluck( $query->posts, 'ID' ), $query->request );
	}

	/**
	 * `post_type => 'any'` takes a different branch through the query filters.
	 */
	public function test_author_name_finds_the_post_for_a_post_type_any_query(): void {
		$this->register_authored_post_type( 'book' );

		$owner  = $this->create_author( 'owner' );
		$writer = $this->create_author( 'writer' );

		$book_id = $this->create_authored_post( 'book', $owner, array( $owner->user_login, $writer->user_login ) );

		$query = new WP_Query(
			array(
				'post_type'   => 'any',
				'author_name' => $writer->user_login,
			)
		);

		$this->assertSame( array( $book_id ), wp_list_pluck( $query->posts, 'ID' ) );
	}
}
