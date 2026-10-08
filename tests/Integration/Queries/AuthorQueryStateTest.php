<?php
/**
 * Tests that the author-query rewrite keeps no state between queries.
 *
 * The taxonomy conditions built in posts_where_filter() are handed to
 * posts_join_filter() and posts_groupby_filter() through instance state. These
 * tests pin down that the handoff cannot reach a later, unrelated query.
 *
 * @see https://github.com/Automattic/co-authors-plus/issues/1371
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
class AuthorQueryStateTest extends TestCase {

	/**
	 * A query the rewrite declines to touch must not inherit the previous query's
	 * HAVING clause.
	 *
	 * The second query asks for an author ID that no longer resolves to a user —
	 * an orphaned post, which this plugin has WP-CLI tooling to repair — so there
	 * is nothing for the rewrite to substitute and the WHERE clause is left as a
	 * plain post_author lookup. Before the fix, the JOIN and GROUP BY filters
	 * still fired using the first query's terms, so the post was dropped for
	 * carrying a different author's term: a post the query plainly asked for.
	 */
	public function test_a_query_left_unrewritten_does_not_inherit_the_previous_having(): void {
		global $wpdb;

		$writer = $this->create_author( 'writer' );
		$other  = $this->create_author( 'other' );

		// A post that resolves terms, to populate the state.
		$writer_post = $this->create_post( $writer );
		$this->_cap->add_coauthors( $writer_post->ID, array( $writer->user_login ) );

		// A post carrying a third author's term, whose post_author no longer
		// resolves to a user. Written directly, so the plugin's own save hooks do
		// not put post_author back.
		$orphan_id     = 99999;
		$orphaned_post = $this->create_post( $other );
		$this->_cap->add_coauthors( $orphaned_post->ID, array( $other->user_login ) );
		$wpdb->update( $wpdb->posts, array( 'post_author' => $orphan_id ), array( 'ID' => $orphaned_post->ID ) );
		clean_post_cache( $orphaned_post->ID );

		$this->assertFalse( get_userdata( $orphan_id ), 'The orphaned post_author must not resolve to a user.' );

		// Query one: resolves the writer's term and populates having_terms.
		$first = new WP_Query( array( 'author_name' => $writer->user_login ) );
		$this->assertSame( array( $writer_post->ID ), wp_list_pluck( $first->posts, 'ID' ) );

		// Query two: nothing to rewrite, so the WHERE clause is left alone.
		$second = new WP_Query( array( 'author__in' => array( $orphan_id ) ) );

		$this->assertStringNotContainsString(
			'HAVING',
			$second->request,
			'A query the rewrite declined to touch must not carry a HAVING clause from an earlier query.'
		);
		$this->assertSame(
			array( $orphaned_post->ID ),
			wp_list_pluck( $second->posts, 'ID' ),
			"The second query must still return the posts it asked for.\nSQL:\n" . $second->request
		);
	}

	/**
	 * The same leak, reached through a guest author's ID.
	 *
	 * A guest author's ID is a `guest-author` post ID, so it never resolves
	 * through get_userdata() and the rewrite bails. That is its own limitation
	 * (see #1377), but the query must at least be left alone rather than
	 * filtered against whichever author happened to be queried beforehand.
	 */
	public function test_a_guest_author_id_query_does_not_inherit_the_previous_having(): void {
		global $coauthors_plus;

		$writer      = $this->create_author( 'writer' );
		$writer_post = $this->create_post( $writer );
		$this->_cap->add_coauthors( $writer_post->ID, array( $writer->user_login ) );

		$guest_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Guest Writer',
				'user_login'   => 'guest-writer',
			)
		);

		$first = new WP_Query( array( 'author_name' => $writer->user_login ) );
		$this->assertSame( array( $writer_post->ID ), wp_list_pluck( $first->posts, 'ID' ) );

		$second = new WP_Query( array( 'author__in' => array( $guest_id ) ) );

		$this->assertStringNotContainsString(
			'HAVING',
			$second->request,
			'A guest author ID query must not carry a HAVING clause from an earlier query.'
		);
	}

	/**
	 * The handoff itself must keep working: a query the rewrite does act on still
	 * needs its JOIN and HAVING, however many queries ran before it.
	 */
	public function test_the_rewrite_still_applies_after_an_earlier_query(): void {
		$writer1 = $this->create_author( 'writer1' );
		$writer2 = $this->create_author( 'writer2' );

		$post1 = $this->create_post( $writer1 );
		$this->_cap->add_coauthors( $post1->ID, array( $writer1->user_login ) );

		// writer2 is a co-author of post2, but not its post_author.
		$post2 = $this->create_post( $writer1 );
		$this->_cap->add_coauthors( $post2->ID, array( $writer1->user_login, $writer2->user_login ) );

		new WP_Query( array( 'author_name' => $writer1->user_login ) );

		$second = new WP_Query( array( 'author_name' => $writer2->user_login ) );

		$this->assertStringContainsString( 'HAVING', $second->request );
		$this->assertSame(
			array( $post2->ID ),
			wp_list_pluck( $second->posts, 'ID' ),
			"The rewrite must still find co-authored posts after an earlier query.\nSQL:\n" . $second->request
		);
	}
}
