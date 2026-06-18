<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use WP_REST_Request;

/**
 * Regression coverage for issue #1297 (VIPPLUG-26).
 *
 * Reordering the co-authors of a post in the block editor so that a *different*
 * existing co-author becomes first must move the core `post_author` column to
 * that new first co-author. Before the fix, post_author tracked membership
 * only: as long as the previous primary author remained somewhere in the
 * co-author list, post_author was left pointing at them, so a reorder left
 * post_author stale.
 *
 * @covers \CoAuthors_Plus::set_post_author_for_rest_save
 */
class RestSaveReorderPostAuthorTest extends TestCase {

	/**
	 * Save the given ordered co-author terms via the REST API, as the block
	 * editor does, and return the resulting post_author.
	 *
	 * @param int   $post_id  Post being saved.
	 * @param int[] $term_ids Ordered author term IDs.
	 * @return int post_author after the save.
	 */
	private function rest_save_coauthors( int $post_id, array $term_ids ): int {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'coauthors', $term_ids );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		return (int) get_post( $post_id )->post_author;
	}

	public function test_reordering_coauthors_moves_post_author_to_new_first(): void {
		$author_a = $this->create_author( 'cap-1297-a' );
		$author_b = $this->create_author( 'cap-1297-b' );
		$post     = $this->create_post( $author_a );

		$this->_cap->update_author_term( $author_a );
		$this->_cap->update_author_term( $author_b );
		$term_a = $this->_cap->get_author_term( $author_a );
		$term_b = $this->_cap->get_author_term( $author_b );

		$admin = $this->factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );

		// Establish A, then B (A primary).
		$this->assertSame(
			$author_a->ID,
			$this->rest_save_coauthors( $post->ID, array( $term_a->term_id, $term_b->term_id ) ),
			'post_author should be A while A is the first co-author.'
		);

		// Reorder so B is first; A remains a co-author.
		$this->assertSame(
			$author_b->ID,
			$this->rest_save_coauthors( $post->ID, array( $term_b->term_id, $term_a->term_id ) ),
			'post_author should move to B after B becomes the first co-author.'
		);

		// The author terms should be stored in the new order, B then A.
		$terms = wp_get_object_terms(
			$post->ID,
			$this->_cap->coauthor_taxonomy,
			array(
				'orderby' => 'term_order',
				'order'   => 'ASC',
				'fields'  => 'slugs',
			)
		);
		$this->assertSame(
			array(
				$this->_cap->get_author_term( $author_b )->slug,
				$this->_cap->get_author_term( $author_a )->slug,
			),
			$terms,
			'Author terms should be ordered B, A after the reorder.'
		);
	}

	/**
	 * The block editor's REST save sets post_author correctly, but Gutenberg then
	 * fires a second, non-REST "meta-box-loader" save (action=editpost) that carries
	 * no co-author data. Core's edit_post() falls back to the editing user for
	 * post_author on that request, reverting the value the REST save just stored.
	 * CAP must re-derive post_author from the first co-author term on that save.
	 */
	public function test_non_rest_save_reasserts_post_author_from_first_coauthor(): void {
		$author_a = $this->create_author( 'cap-1297-mbl-a' );
		$author_b = $this->create_author( 'cap-1297-mbl-b' );
		$post     = $this->create_post( $author_a );

		// Store co-authors as B, A. add_coauthors() keeps post_author as A here
		// (A is still a co-author), reproducing the stale state after a reorder.
		$this->_cap->add_coauthors(
			$post->ID,
			array( $author_b->user_nicename, $author_a->user_nicename )
		);

		$admin = $this->factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );

		// Simulate the meta-box-loader save: a non-REST update with no co-author
		// data, where core would set post_author to the editing user.
		unset( $_POST['coauthors'], $_REQUEST['coauthors-nonce'] );
		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_author' => $admin->ID,
			)
		);

		$this->assertSame(
			$author_b->ID,
			(int) get_post( $post->ID )->post_author,
			'A non-REST save must re-assert post_author from the first co-author, not revert it.'
		);
	}

	public function test_reordering_back_moves_post_author_back(): void {
		$author_a = $this->create_author( 'cap-1297-back-a' );
		$author_b = $this->create_author( 'cap-1297-back-b' );
		$post     = $this->create_post( $author_a );

		$this->_cap->update_author_term( $author_a );
		$this->_cap->update_author_term( $author_b );
		$term_a = $this->_cap->get_author_term( $author_a );
		$term_b = $this->_cap->get_author_term( $author_b );

		$admin = $this->factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );

		$this->rest_save_coauthors( $post->ID, array( $term_b->term_id, $term_a->term_id ) );
		$this->assertSame(
			$author_a->ID,
			$this->rest_save_coauthors( $post->ID, array( $term_a->term_id, $term_b->term_id ) ),
			'post_author should move back to A when A becomes the first co-author again.'
		);
	}
}
