<?php
/**
 * Tests for what core's REST routes expose about co-authors.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Rest;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The author term name is the co-author's user login, and core keeps logins
 * out of anonymous REST responses. The author term routes are therefore
 * limited to users who can edit posts, and the guest author post type is not
 * exposed through core REST at all.
 *
 * @covers \CoAuthors\API\Endpoints\Author_Terms_Controller
 */
class CoreRestExposureTest extends TestCase {

	private function get( string $path, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	private function create_post_with_author_term( \WP_User $author ): array {
		$post = $this->create_post( $author );
		$this->_cap->add_coauthors( $post->ID, array( $author->user_nicename ) );

		return array( $post, $this->_cap->get_author_term( $author ) );
	}

	public function test_anonymous_cannot_list_author_terms(): void {
		$this->create_post_with_author_term( $this->create_author( 'secret_login' ) );
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->get( '/wp/v2/coauthors' )->get_status() );
	}

	public function test_anonymous_cannot_read_an_author_term(): void {
		list( , $term ) = $this->create_post_with_author_term( $this->create_author( 'secret_login' ) );
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->get( '/wp/v2/coauthors/' . $term->term_id )->get_status() );
	}

	public function test_subscriber_cannot_list_or_read_author_terms(): void {
		list( , $term ) = $this->create_post_with_author_term( $this->create_author( 'secret_login' ) );
		wp_set_current_user( $this->create_subscriber()->ID );

		$this->assertSame( 403, $this->get( '/wp/v2/coauthors' )->get_status() );
		$this->assertSame( 403, $this->get( '/wp/v2/coauthors/' . $term->term_id )->get_status() );
	}

	public function test_contributor_can_list_and_read_author_terms(): void {
		list( , $term ) = $this->create_post_with_author_term( $this->create_author( 'visible_login' ) );
		wp_set_current_user( $this->create_contributor()->ID );

		$list = $this->get( '/wp/v2/coauthors' );
		$this->assertSame( 200, $list->get_status() );
		$this->assertContains( $term->term_id, wp_list_pluck( $list->get_data(), 'id' ) );

		$single = $this->get( '/wp/v2/coauthors/' . $term->term_id );
		$this->assertSame( 200, $single->get_status() );
		$this->assertSame( 'visible_login', $single->get_data()['name'] );
	}

	public function test_embedding_a_post_does_not_leak_author_term_names_to_anonymous(): void {
		// A nicename unlike the login, so only the term name can carry the login.
		$author = self::factory()->user->create_and_get(
			array(
				'role'          => 'author',
				'user_login'    => 'secret_login',
				'user_nicename' => 'public-name',
			)
		);
		list( $post ) = $this->create_post_with_author_term( $author );
		wp_set_current_user( 0 );

		$response = $this->get( '/wp/v2/posts/' . $post->ID );
		$data     = rest_get_server()->response_to_data( $response, array( 'wp:term' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( 'secret_login', (string) wp_json_encode( $data ) );
	}

	public function test_anonymous_still_gets_coauthors_term_ids_on_posts(): void {
		list( $post, $term ) = $this->create_post_with_author_term( $this->create_author( 'secret_login' ) );
		wp_set_current_user( 0 );

		$response = $this->get( '/wp/v2/posts/' . $post->ID );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $term->term_id ), $response->get_data()['coauthors'] );
	}

	public function test_guest_author_post_type_has_no_core_rest_route(): void {
		$this->create_guest_author( 'secret_guest' );
		wp_set_current_user( 0 );

		$this->assertSame( 404, $this->get( '/wp/v2/guest-author' )->get_status() );
	}
}
