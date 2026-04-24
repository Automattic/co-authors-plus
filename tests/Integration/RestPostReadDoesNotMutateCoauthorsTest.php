<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use WP_REST_Request;

/**
 * Regression coverage for issue #1241.
 *
 * A REST read of a post must never mutate the post's coauthor taxonomy
 * assignments. 4.0.0 shipped a dynamic backfill filter hooked into
 * rest_prepare_{post_type} that called add_coauthors() — with replace
 * semantics — whenever it believed the response lacked coauthor data.
 * The response-based guard was fooled by narrowing filters such as
 * `_fields=id`, causing authenticated reads to silently replace guest
 * author assignments with the post_author on every affected request.
 */
class RestPostReadDoesNotMutateCoauthorsTest extends TestCase {

	public function test_rest_post_read_with_fields_filter_preserves_guest_author(): void {
		$editor          = $this->create_editor( 'rest-read-editor' );
		$post            = $this->create_post( $editor );
		$guest_author_id = $this->create_guest_author( 'rest_read_ga' );
		$guest_author    = $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_author_id );

		$this->_cap->add_coauthors( $post->ID, array( $guest_author->user_login ) );

		$terms_before = wp_list_pluck(
			wp_get_object_terms( $post->ID, $this->_cap->coauthor_taxonomy ),
			'slug'
		);
		$this->assertContains(
			'cap-' . $guest_author->user_login,
			$terms_before,
			'Guest author term should be assigned before the REST read.'
		);

		wp_set_current_user( $editor->ID );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post->ID );
		$request->set_param( 'context', 'edit' );
		$request->set_param( '_fields', 'id' );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$terms_after = wp_list_pluck(
			wp_get_object_terms( $post->ID, $this->_cap->coauthor_taxonomy ),
			'slug'
		);

		sort( $terms_before );
		sort( $terms_after );

		$this->assertSame(
			$terms_before,
			$terms_after,
			'A REST read with _fields=id must not mutate the coauthor taxonomy assignments.'
		);
	}
}
