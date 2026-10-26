<?php
/**
 * Co-author caps only apply to the post being checked.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Capabilities;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A cap check that names no post, such as edit_others_posts, must not pick up
 * the global $post and treat a co-author of that post as an editor of others'
 * posts.
 *
 * @covers \CoAuthors_Plus::filter_map_meta_cap
 */
class CoauthorCapsWithoutPostTest extends TestCase {

	private function create_via_rest( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	public function test_coauthor_of_global_post_cannot_edit_others_posts(): void {
		global $post;

		$author = $this->create_author( 'global-coauthor' );
		$post   = $this->create_post( $author ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( $author->ID );

		$this->assertFalse( current_user_can( 'edit_others_posts' ) );
	}

	/**
	 * Core's REST controller leaves the last post it prepared in the global
	 * $post, so a batch request could create a post and then publish another
	 * as someone else.
	 */
	public function test_author_cannot_publish_as_another_user_after_creating_a_post(): void {
		$admin  = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$author = $this->create_author( 'batch-author' );
		wp_set_current_user( $author->ID );

		$this->assertSame( 201, $this->create_via_rest( array( 'title' => 'First' ) )->get_status() );

		$response = $this->create_via_rest(
			array(
				'title'  => 'As someone else',
				'status' => 'publish',
				'author' => $admin,
			)
		);

		$this->assertSame( 403, $response->get_status() );
	}
}
