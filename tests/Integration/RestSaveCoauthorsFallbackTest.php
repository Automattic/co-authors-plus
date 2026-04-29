<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use CoAuthors\API\Endpoints;
use WP_REST_Request;

/**
 * Regression coverage for issue #1252.
 *
 * Defends two related guards introduced after the OP reported the editor
 * silently unassigning the current user:
 *
 *   1. `Endpoints::_format_author_data()` returns null when an author has
 *      no resolvable taxonomy term, so the editor never receives a row
 *      with `termId: null` (which `wp_set_object_terms()` would skip).
 *   2. `CoAuthors_Plus::sync_coauthors_on_rest_save()` falls back to the
 *      post_author when a REST save leaves the post with zero coauthor
 *      terms (e.g. all submitted IDs were invalid), so the post is never
 *      persisted termless.
 */
class RestSaveCoauthorsFallbackTest extends TestCase {

	/**
	 * @covers \CoAuthors\API\Endpoints::get_coauthors_by_term_ids
	 */
	public function test_authors_by_term_ids_skips_terms_with_no_resolvable_author(): void {
		$editor = $this->create_editor( 'rest-fallback-editor' );
		wp_set_current_user( $editor->ID );

		// Real term that does resolve to the editor user.
		$this->_cap->update_author_term( $editor );
		$editor_term = $this->_cap->get_author_term( $editor );

		// An orphan term whose slug points at no WP user or guest author.
		$orphan_term = wp_insert_term(
			'orphan-author',
			$this->_cap->coauthor_taxonomy,
			array(
				'slug'        => 'cap-no-such-author',
				'description' => 'Orphan author term',
			)
		);
		$this->assertIsArray( $orphan_term );

		$request = new WP_REST_Request( 'GET', '/coauthors/v1/' . Endpoints::AUTHORS_BY_TERMS_ROUTE );
		$request->set_param( 'ids', $editor_term->term_id . ',' . $orphan_term['term_id'] );

		$response = ( new Endpoints( $this->_cap ) )->get_coauthors_by_term_ids( $request );
		$data     = $response->get_data();

		$this->assertCount(
			1,
			$data,
			'Orphan term should be skipped, so only the editor is returned.'
		);
		$this->assertSame( (int) $editor_term->term_id, $data[0]['termId'] );
		$this->assertIsInt( $data[0]['termId'], 'Returned termId must be an int, never null.' );
	}

	/**
	 * @covers \CoAuthors_Plus::sync_coauthors_on_rest_save
	 */
	public function test_rest_save_with_no_resolvable_term_ids_falls_back_to_post_author(): void {
		$editor = $this->create_editor( 'rest-fallback-author' );
		$post   = $this->create_post( $editor );

		// Sanity check: the editor is the only coauthor before we save.
		$before = wp_get_object_terms( $post->ID, $this->_cap->coauthor_taxonomy, array( 'fields' => 'slugs' ) );
		$this->assertSame( array( 'cap-' . $editor->user_login ), $before );

		wp_set_current_user( $editor->ID );

		// Simulate the editor saving with a bogus coauthor term ID. WP core's
		// handle_terms() will replace existing terms with this set; the bogus
		// ID gets dropped by wp_set_object_terms(), leaving the post termless.
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post->ID );
		$request->set_param( 'id', $post->ID );
		$request->set_param( 'coauthors', array( 999999 ) );
		$request->set_param( 'title', 'After bogus save' );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$after = wp_get_object_terms( $post->ID, $this->_cap->coauthor_taxonomy, array( 'fields' => 'slugs' ) );

		$this->assertNotEmpty(
			$after,
			'After a save that drops every submitted term, the post must not be termless.'
		);
		$this->assertSame(
			array( 'cap-' . $editor->user_login ),
			$after,
			'The fallback should restore the post_author as the sole coauthor.'
		);
	}
}
