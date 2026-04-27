<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

/**
 * Tests for issue #1102: WP_Query author parameters not finding co-authored posts.
 *
 * The `author` parameter with comma-separated IDs and the `author__in` parameter
 * with an array of IDs should both find posts where the queried user is a co-author,
 * not just the primary `post_author`.
 *
 * @see https://github.com/Automattic/co-authors-plus/issues/1102
 */
class AuthorMultiQueryTest extends TestCase {

	/**
	 * Using `author__in` with a single ID should find a post where the user is
	 * set as the co-author via the author taxonomy (but is also post_author).
	 *
	 * This is a regression guard — existing single-ID behaviour must be preserved.
	 */
	public function test_author_in_with_single_id_finds_post_when_user_is_post_author(): void {
		$author = $this->create_author( 'single_author' );
		$post   = $this->create_post( $author );
		$this->_cap->add_coauthors( $post->ID, array( $author->user_login ) );

		$query = new \WP_Query(
			array(
				'author__in' => array( $author->ID ),
			)
		);

		$this->assertCount( 1, $query->posts );
		$this->assertEquals( $post->ID, $query->posts[0]->ID );
	}

	/**
	 * Using `author__in` with an array of IDs should find a post where the
	 * queried user is a co-author (taxonomy term) but NOT the `post_author`.
	 *
	 * This is the core bug from issue #1102: the taxonomy JOIN/WHERE is not
	 * applied for `author__in`, so co-authored posts are missed.
	 */
	public function test_author_in_finds_post_where_user_is_coauthor_not_post_author(): void {
		$author1 = $this->create_author( 'multi_author1' );
		$author2 = $this->create_author( 'multi_author2' );
		$post    = $this->create_post( $author1 ); // post_author = author1
		$this->_cap->add_coauthors( $post->ID, array( $author1->user_login, $author2->user_login ) );

		// Query for author2, who is a co-author but NOT post_author.
		$query = new \WP_Query(
			array(
				'author__in' => array( $author2->ID ),
			)
		);

		$this->assertCount( 1, $query->posts, 'author__in should find post where user is co-author via taxonomy.' );
		$this->assertEquals( $post->ID, $query->posts[0]->ID );
	}

	/**
	 * Using `author__in` with multiple IDs should find posts for all specified
	 * co-authors, including those who are not the primary `post_author`.
	 */
	public function test_author_in_with_multiple_ids_finds_posts_for_all_coauthors(): void {
		$author1 = $this->create_author( 'multi_in_a1' );
		$author2 = $this->create_author( 'multi_in_a2' );
		$author3 = $this->create_author( 'multi_in_a3' );

		// Post owned by author1, with author2 as co-author.
		$post1 = $this->create_post( $author1 );
		$this->_cap->add_coauthors( $post1->ID, array( $author1->user_login, $author2->user_login ) );

		// Post owned by author3, with no co-authors.
		$post2 = $this->create_post( $author3 );
		$this->_cap->add_coauthors( $post2->ID, array( $author3->user_login ) );

		// Query for author2 and author3 — should return both posts.
		$query = new \WP_Query(
			array(
				'author__in' => array( $author2->ID, $author3->ID ),
				'orderby'    => 'ID',
				'order'      => 'ASC',
			)
		);

		$ids = wp_list_pluck( $query->posts, 'ID' );
		$this->assertContains( $post1->ID, $ids, 'Post co-authored by author2 should be found.' );
		$this->assertContains( $post2->ID, $ids, 'Post authored by author3 should be found.' );
	}

	/**
	 * `author__in` should NOT return posts that have none of the queried authors.
	 */
	public function test_author_in_does_not_return_unrelated_posts(): void {
		$author1 = $this->create_author( 'unrelated_a1' );
		$author2 = $this->create_author( 'unrelated_a2' );
		$author3 = $this->create_author( 'unrelated_a3' );

		// Post co-authored by author1 and author2.
		$post = $this->create_post( $author1 );
		$this->_cap->add_coauthors( $post->ID, array( $author1->user_login, $author2->user_login ) );

		// Query only for author3 — should return nothing.
		$query = new \WP_Query(
			array(
				'author__in' => array( $author3->ID ),
			)
		);

		$this->assertCount( 0, $query->posts, 'Unrelated author should not cause false positives.' );
	}

	/**
	 * Using `author` with a comma-separated string of IDs should find posts where
	 * any of the listed users is a co-author via the author taxonomy — even when
	 * the actual `post_author` is NOT one of the queried IDs.
	 *
	 * This is the second variant from issue #1102. The post_author ($other) is
	 * intentionally excluded from the query so the only way the post can be found
	 * is via the CAP taxonomy rewrite — WordPress's default post_author IN(...)
	 * clause alone would not match.
	 */
	public function test_author_comma_string_finds_post_where_user_is_coauthor_not_post_author(): void {
		$other     = $this->create_author( 'comma_other' );     // owns the post; NOT in query
		$author2   = $this->create_author( 'comma_a2' );         // co-author via taxonomy
		$unrelated = $this->create_author( 'comma_unrelated' );  // neither author nor co-author

		$post = $this->create_post( $other ); // post_author = $other (absent from comma list)
		$this->_cap->add_coauthors( $post->ID, array( $other->user_login, $author2->user_login ) );

		// Query for author2 and unrelated — post_author ($other) is NOT in this list.
		// The post can only be found if CAP rewrites the WHERE via the taxonomy term for author2.
		$query = new \WP_Query(
			array(
				'author' => $author2->ID . ',' . $unrelated->ID,
			)
		);

		$this->assertCount( 1, $query->posts, 'Comma-separated author IDs must find the post via taxonomy when post_author is not in the query list.' );
		$this->assertEquals( $post->ID, $query->posts[0]->ID );
	}

	/**
	 * A single integer in `author` (not comma-separated) should still work after
	 * our changes — this is a regression guard for the existing single-author path.
	 */
	public function test_single_author_id_still_finds_coauthored_post_after_fix(): void {
		$author1 = $this->create_author( 'single_id_a1' );
		$author2 = $this->create_author( 'single_id_a2' );
		$post    = $this->create_post( $author1 );
		$this->_cap->add_coauthors( $post->ID, array( $author1->user_login, $author2->user_login ) );

		// Query for author2 by single integer ID.
		$query = new \WP_Query(
			array(
				'author' => $author2->ID,
			)
		);

		$this->assertCount( 1, $query->posts, 'Single author ID query must still work after fix.' );
		$this->assertEquals( $post->ID, $query->posts[0]->ID );
	}
}
