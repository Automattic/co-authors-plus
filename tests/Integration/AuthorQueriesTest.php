<?php
/**
 * Tests for the author-query taxonomy rewrite.
 *
 * WP_Query author parameters must find posts where the queried user is a
 * co-author via the author taxonomy, not just the primary post_author. This
 * covers the `author` (single ID and comma-separated IDs), `author_name`
 * (login) and `author__in` (array) query variables, plus the opt-out filter.
 *
 * @see https://github.com/Automattic/co-authors-plus/issues/508
 * @see https://github.com/Automattic/co-authors-plus/issues/1102
 * @see https://github.com/Automattic/co-authors-plus/issues/1296
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use WP_Query;

/**
 * @covers \CoAuthors_Plus::posts_where_filter
 * @covers \CoAuthors_Plus::posts_join_filter
 * @covers \CoAuthors_Plus::posts_groupby_filter
 * @covers \CoAuthors_Plus::posts_where_filter_multi_author
 */
class AuthorQueriesTest extends TestCase {

	/**
	 * Assert that a query returns exactly the expected post IDs, regardless of order.
	 *
	 * @param int[]    $expected_ids Post IDs the query must return, and no others.
	 * @param WP_Query $query        The executed query.
	 * @param string   $message      Optional failure message.
	 */
	private function assertQueryReturns( array $expected_ids, WP_Query $query, string $message = '' ): void {
		$actual = wp_list_pluck( $query->posts, 'ID' );
		sort( $expected_ids );
		sort( $actual );
		$this->assertSame( $expected_ids, $actual, $message );
	}

	// -- author_name (login) ------------------------------------------------

	public function test_author_name_finds_post_for_the_post_author(): void {
		$author = $this->create_author();
		$post   = $this->create_post( $author );
		$this->_cap->add_coauthors( $post->ID, array( $author->user_login ) );

		$query = new WP_Query( array( 'author_name' => $author->user_login ) );

		$this->assertQueryReturns( array( $post->ID ), $query );
	}

	public function test_author_name_finds_post_for_a_coauthor_who_is_not_the_post_author(): void {
		$author1 = $this->create_author( 'author1' );
		$author2 = $this->create_author( 'author2' );
		$post    = $this->create_post( $author1 );
		$this->_cap->add_coauthors( $post->ID, array( $author1->user_login, $author2->user_login ) );

		$query = new WP_Query( array( 'author_name' => $author2->user_login ) );

		$this->assertQueryReturns( array( $post->ID ), $query );
	}

	public function test_author_name_with_a_tag_finds_the_post_author_post(): void {
		$author = $this->create_author();
		$post   = $this->create_post( $author );
		$this->_cap->add_coauthors( $post->ID, array( $author->user_login ) );
		wp_set_post_terms( $post->ID, 'test' );

		$query = new WP_Query(
			array(
				'author_name' => $author->user_login,
				'tag'         => 'test',
			)
		);

		$this->assertQueryReturns( array( $post->ID ), $query );
	}

	public function test_author_name_with_a_tag_finds_a_coauthor_post(): void {
		$author1 = $this->create_author( 'author1' );
		$author2 = $this->create_author( 'author2' );
		$post    = $this->create_post( $author1 );
		$this->_cap->add_coauthors( $post->ID, array( $author1->user_login, $author2->user_login ) );
		wp_set_post_terms( $post->ID, 'test' );

		$query = new WP_Query(
			array(
				'author_name' => $author2->user_login,
				'tag'         => 'test',
			)
		);

		$this->assertQueryReturns( array( $post->ID ), $query );
	}

	public function test_author_name_finds_post_for_a_guest_author_coauthor(): void {
		$this->create_guest_author( 'guest_byliner' );
		$post = $this->create_post();
		$this->_cap->add_coauthors( $post->ID, array( 'guest_byliner' ) );

		$query = new WP_Query( array( 'author_name' => 'guest_byliner' ) );

		$this->assertQueryReturns( array( $post->ID ), $query );
	}

	// -- author (single ID) -------------------------------------------------

	public function test_author_id_finds_post_for_the_post_author_as_admin(): void {
		$author = $this->create_author();
		$post   = $this->create_post( $author );
		$this->_cap->add_coauthors( $post->ID, array( $author->user_login ) );

		$query = new WP_Query( array( 'author' => $author->ID ) );

		$this->assertQueryReturns( array( $post->ID ), $query );
	}

	/**
	 * The query rewrite must also work for a non-administrator current user.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/508
	 */
	public function test_author_id_finds_post_when_current_user_is_a_non_admin(): void {
		$author = $this->create_author();
		$post   = $this->create_post( $author );
		$this->_cap->add_coauthors( $post->ID, array( $author->user_login ) );

		wp_set_current_user( $author->ID );

		$query = new WP_Query( array( 'author' => $author->ID ) );

		$this->assertQueryReturns( array( $post->ID ), $query );
	}

	public function test_author_id_finds_post_for_a_coauthor_who_is_not_the_post_author(): void {
		$author1 = $this->create_author( 'author1' );
		$author2 = $this->create_author( 'author2' );
		$post    = $this->create_post( $author1 );
		$this->_cap->add_coauthors( $post->ID, array( $author1->user_login, $author2->user_login ) );

		$query = new WP_Query( array( 'author' => $author2->ID ) );

		$this->assertQueryReturns( array( $post->ID ), $query );
	}

	// -- author (comma-separated IDs), issue #1102 --------------------------

	/**
	 * A comma-separated `author` list must find a post via the taxonomy term of a
	 * queried co-author even when the actual post_author is absent from the list.
	 */
	public function test_author_comma_string_finds_post_via_coauthor_not_post_author(): void {
		$other     = $this->create_author( 'comma_other' );     // owns the post; NOT in query
		$author2   = $this->create_author( 'comma_a2' );        // co-author via taxonomy
		$unrelated = $this->create_author( 'comma_unrelated' );  // neither author nor co-author

		$post = $this->create_post( $other );
		$this->_cap->add_coauthors( $post->ID, array( $other->user_login, $author2->user_login ) );

		$query = new WP_Query( array( 'author' => $author2->ID . ',' . $unrelated->ID ) );

		$this->assertQueryReturns(
			array( $post->ID ),
			$query,
			'Comma-separated author IDs must find the post via taxonomy when post_author is not in the list.'
		);
	}

	// -- author__in (array), issue #1102 ------------------------------------

	public function test_author_in_with_a_single_id_finds_the_post_author_post(): void {
		$author = $this->create_author( 'single_author' );
		$post   = $this->create_post( $author );
		$this->_cap->add_coauthors( $post->ID, array( $author->user_login ) );

		$query = new WP_Query( array( 'author__in' => array( $author->ID ) ) );

		$this->assertQueryReturns( array( $post->ID ), $query );
	}

	public function test_author_in_finds_post_for_a_coauthor_who_is_not_the_post_author(): void {
		$author1 = $this->create_author( 'multi_author1' );
		$author2 = $this->create_author( 'multi_author2' );
		$post    = $this->create_post( $author1 );
		$this->_cap->add_coauthors( $post->ID, array( $author1->user_login, $author2->user_login ) );

		$query = new WP_Query( array( 'author__in' => array( $author2->ID ) ) );

		$this->assertQueryReturns(
			array( $post->ID ),
			$query,
			'author__in must find a post where the user is a co-author via taxonomy.'
		);
	}

	public function test_author_in_with_multiple_ids_finds_every_matching_post_and_no_others(): void {
		$author1 = $this->create_author( 'multi_in_a1' );
		$author2 = $this->create_author( 'multi_in_a2' );
		$author3 = $this->create_author( 'multi_in_a3' );

		$post1 = $this->create_post( $author1 );
		$this->_cap->add_coauthors( $post1->ID, array( $author1->user_login, $author2->user_login ) );

		$post2 = $this->create_post( $author3 );
		$this->_cap->add_coauthors( $post2->ID, array( $author3->user_login ) );

		// A third post by none of the queried authors, to prove it is excluded.
		$author4 = $this->create_author( 'multi_in_a4' );
		$post3   = $this->create_post( $author4 );
		$this->_cap->add_coauthors( $post3->ID, array( $author4->user_login ) );

		$query = new WP_Query( array( 'author__in' => array( $author2->ID, $author3->ID ) ) );

		$this->assertQueryReturns(
			array( $post1->ID, $post2->ID ),
			$query,
			'author__in must return exactly the matching posts — no misses and no extras.'
		);
	}

	public function test_author_in_excludes_posts_with_none_of_the_queried_authors(): void {
		$author1 = $this->create_author( 'unrelated_a1' );
		$author2 = $this->create_author( 'unrelated_a2' );
		$author3 = $this->create_author( 'unrelated_a3' );

		$post = $this->create_post( $author1 );
		$this->_cap->add_coauthors( $post->ID, array( $author1->user_login, $author2->user_login ) );

		$query = new WP_Query( array( 'author__in' => array( $author3->ID ) ) );

		$this->assertQueryReturns( array(), $query, 'An unrelated author must not cause false positives.' );
	}

	// -- opt-out filter, issue #1296 ----------------------------------------

	/**
	 * The `coauthors_plus_is_author_query` filter can opt a query out of the CAP
	 * rewrite, leaving it as a standard post_author query.
	 *
	 * @covers \CoAuthors_Plus::is_author_query
	 */
	public function test_is_author_query_filter_can_opt_out_of_the_rewrite(): void {
		$author1 = $this->create_author( 'opt_out_a1' );
		$author2 = $this->create_author( 'opt_out_a2' );

		$post = $this->create_post( $author1 );
		$this->_cap->add_coauthors( $post->ID, array( $author1->user_login, $author2->user_login ) );

		add_filter( 'coauthors_plus_is_author_query', '__return_false' );

		$query = new WP_Query( array( 'author__in' => array( $author2->ID ) ) );

		remove_filter( 'coauthors_plus_is_author_query', '__return_false' );

		$this->assertQueryReturns(
			array(),
			$query,
			'Opting out must leave the query as a post_author query and skip co-authored posts.'
		);
	}
}
