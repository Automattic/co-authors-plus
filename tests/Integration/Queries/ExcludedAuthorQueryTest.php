<?php
/**
 * Tests for author queries that also exclude authors.
 *
 * WP_Query emits `post_author NOT IN (...)` instead of `post_author IN (...)`
 * when author__not_in is set, leaving no author clause for the rewrite to
 * replace, so these queries keep the results WP_Query gives them.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Queries;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_Query;
use WP_REST_Request;

/**
 * @covers \CoAuthors_Plus::is_author_query
 * @covers \CoAuthors_Plus::posts_where_filter
 * @covers \CoAuthors_Plus::posts_where_filter_multi_author
 */
class ExcludedAuthorQueryTest extends TestCase {

	/**
	 * @var \WP_User
	 */
	private $author;

	/**
	 * @var \WP_User
	 */
	private $other;

	/**
	 * @var \WP_User
	 */
	private $reader;

	/**
	 * @var \WP_Post
	 */
	private $private_post;

	public function set_up() {
		parent::set_up();

		$this->author = $this->create_author( 'excluded_author' );
		$this->other  = $this->create_author( 'excluded_other' );
		$this->reader = $this->create_subscriber( 'excluded_reader' );

		$this->private_post = $this->create_private_post( $this->author );
		$this->_cap->add_coauthors( $this->private_post->ID, array( $this->author->user_login ) );

		wp_set_current_user( $this->reader->ID );
	}

	private function create_private_post( \WP_User $author ): \WP_Post {
		$post = $this->create_post( $author );
		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'private',
			)
		);

		return get_post( $post->ID );
	}

	/**
	 * Author query vars that leave WP_Query with no positive author clause.
	 *
	 * @return array<string, array{callable(int, int): array<string, mixed>}>
	 */
	public function data_queries_without_an_author_clause(): array {
		return array(
			'excluded ID in the author string' => array(
				static function ( int $author, int $other ): array {
					return array( 'author' => "{$author},-{$other}" );
				},
			),
			'empty ID in the author string'    => array(
				static function ( int $author, int $other ): array {
					return array( 'author' => "{$author},{$other}," );
				},
			),
			'non-numeric ID in the author string' => array(
				static function ( int $author, int $other ): array {
					return array( 'author' => "{$author},{$other},x" );
				},
			),
			'author__in with author__not_in'   => array(
				static function ( int $author, int $other ): array {
					return array(
						'author__in'     => array( $author ),
						'author__not_in' => array( $other ),
					);
				},
			),
			'author with author__not_in'       => array(
				static function ( int $author, int $other ): array {
					return array(
						'author'         => $author,
						'author__not_in' => array( $other ),
					);
				},
			),
		);
	}

	/**
	 * @dataProvider data_queries_without_an_author_clause
	 *
	 * @param callable $query_args Builds the query vars from the author and other user IDs.
	 */
	public function test_query_without_an_author_clause_is_not_rewritten( callable $query_args ): void {
		$query = new WP_Query( $query_args( $this->author->ID, $this->other->ID ) );

		$this->assertNotContains( $this->private_post->ID, wp_list_pluck( $query->posts, 'ID' ) );
	}

	public function test_author_url_without_an_author_clause_is_not_rewritten(): void {
		$this->go_to( add_query_arg( 'author', $this->author->ID . ',-' . $this->other->ID, home_url( '/' ) ) );

		$this->assertNotContains( $this->private_post->ID, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	/**
	 * WP_Query's own results stand, which are every readable post not by the
	 * excluded author, including the reader's own private posts.
	 */
	public function test_query_keeps_the_results_core_would_return(): void {
		$author_public_post = $this->create_post( $this->author );
		$other_public_post  = $this->create_post( $this->other );
		$own_private_post   = $this->create_private_post( $this->reader );

		$query = new WP_Query( array( 'author' => $this->author->ID . ',-' . $this->other->ID ) );
		$ids   = wp_list_pluck( $query->posts, 'ID' );

		$this->assertContains( $author_public_post->ID, $ids );
		$this->assertContains( $own_private_post->ID, $ids );
		$this->assertNotContains( $other_public_post->ID, $ids );
	}

	/**
	 * A query with author_name still gets a positive author clause, so the rewrite
	 * must apply alongside an exclusion, and must rewrite that clause alone.
	 */
	public function test_author_name_with_an_exclusion_is_still_rewritten(): void {
		$coauthored_post = $this->create_post( $this->other );
		$this->_cap->add_coauthors( $coauthored_post->ID, array( $this->other->user_login, $this->author->user_login ) );

		$query = new WP_Query(
			array(
				'author_name'    => $this->author->user_nicename,
				'author__not_in' => array( $this->reader->ID ),
			)
		);
		$ids   = wp_list_pluck( $query->posts, 'ID' );

		$this->assertContains( $coauthored_post->ID, $ids );
		$this->assertNotContains( $this->private_post->ID, $ids );
	}

	public function test_comma_separated_authors_without_an_exclusion_are_rewritten(): void {
		$coauthored_post = $this->create_post( $this->other );
		$this->_cap->add_coauthors( $coauthored_post->ID, array( $this->other->user_login, $this->author->user_login ) );
		$unrelated = $this->create_author( 'excluded_unrelated' );

		$query = new WP_Query( array( 'author' => $this->author->ID . ',' . $unrelated->ID ) );
		$ids   = wp_list_pluck( $query->posts, 'ID' );

		$this->assertContains( $coauthored_post->ID, $ids );
		$this->assertNotContains( $this->private_post->ID, $ids );
	}

	/**
	 * REST maps author and author_exclude to the same query vars, but only someone
	 * who can edit posts may ask for private ones, and each result is then checked.
	 */
	public function test_rest_author_exclude_only_returns_readable_posts(): void {
		wp_set_current_user( $this->create_contributor( 'excluded_contributor' )->ID );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'author', array( $this->author->ID ) );
		$request->set_param( 'author_exclude', array( $this->other->ID ) );
		$request->set_param( 'status', array( 'private' ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotContains( $this->private_post->ID, wp_list_pluck( $response->get_data(), 'id' ) );
	}
}
