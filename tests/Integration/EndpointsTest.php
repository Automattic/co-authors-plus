<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use CoAuthors\API\Endpoints;
use DMS\PHPUnitExtensions\ArraySubset\ArraySubsetAsserts;

/*
 * @coversDefaultClass \CoAuthors\API\Endpoints
 */
class EndpointsTest extends TestCase {

	use ArraySubsetAsserts;

	/**
	 * @var Endpoints
	 */
	private $_api;

	public function set_up() {

		parent::set_up();

		global $coauthors_plus;

		$this->_api = new Endpoints( $coauthors_plus );
	}

	/**
	 * @covers \CoAuthors\API\Endpoints::__construct
	 */
	public function test_construct(): void {

		$this->assertEquals(
			10,
			has_action(
				'rest_api_init',
				array(
					$this->_api,
					'add_endpoints',
				)
			)
		);

		$this->assertEquals(
			10,
			has_action(
				'wp_loaded',
				array(
					$this->_api,
					'modify_responses',
				)
			)
		);
	}

	/**
	 * @covers \CoAuthors\API\Endpoints::add_endpoints
	 */
	public function test_add_endpoints(): void {

		$rest_server = rest_get_server();

		$this->assertContains(
			Endpoints::NS,
			$rest_server->get_namespaces()
		);

		$routes = $rest_server->get_routes( Endpoints::NS );

		$authors_route = sprintf(
			'/%1$s/%2$s%3$s',
			Endpoints::NS,
			Endpoints::AUTHORS_ROUTE,
			'/(?P<post_id>[\d]+)'
		);

		$search_route = sprintf(
			'/%1$s/%2$s',
			Endpoints::NS,
			Endpoints::SEARCH_ROUTE
		);

		$this->assertArrayHasKey(
			$authors_route,
			$routes,
			'Failed to assert that authors endpoint is registered'
		);

		$this->assertArrayHasKey(
			$search_route,
			$routes,
			'Failed to assert that search endpoint is registered'
		);

		$this->assertArrayHasKey(
			'GET',
			$routes[ $search_route ][0]['methods'],
			'Failed to assert that search endpoint has GET method.'
		);

		$this->assertArrayHasKey(
			'GET',
			$routes[ $authors_route ][0]['methods'],
			'Failed to assert that authors endpoint has GET method.'
		);

		$this->assertArrayHasKey(
			'POST',
			$routes[ $authors_route ][1]['methods'],
			'Failed to assert that authors endpoint has POST method.'
		);
	}

	/**
	 * @covers \CoAuthors\API\Endpoints::get_coauthors_search_results
	 */
	public function test_get_coauthors_search_results(): void {
		$author1 = $this->create_author( 'author1' );
		$author2 = $this->create_author( 'author2' );
		$guest_author1 = $this->create_guest_author( 'guest_author1' );
		$guest_author2 = $this->create_guest_author( 'guest_author2' );

		$get_request = new \WP_REST_Request( 'GET' );
		$get_request->set_url_params(
			array(
				'q'                => 'auth',
				'existing_authors' => 'author1,guest_author2',
			)
		);

		$get_response = $this->_api->get_coauthors_search_results( $get_request );

		$this->assertArraySubset(
			array(
				array(
					'displayName' => 'author2',
				),
				array(
					'displayName' => 'guest_author1',
				),
			),
			$get_response->data,
			false,
			'Failed to assert that coauthors search returns results matching the query.'
		);

		$not_found_get_request = new \WP_REST_Request( 'GET' );
		$not_found_get_request->set_url_params(
			array(
				'q' => 'nonexistent',
			)
		);

		$not_found_get_response = $this->_api->get_coauthors_search_results( $not_found_get_request );

		$this->assertEmpty(
			$not_found_get_response->data,
			'Failed to assert that coauthors search returns an empty array when no coauthors match query.'
		);
	}

	/**
	 * @covers \CoAuthors\API\Endpoints::get_coauthors
	 */
	public function test_authors_get_coauthors(): void {
		$author = $this->create_author();
		$post   = $this->create_post( $author );

		$get_request = new \WP_REST_Request( 'GET' );
		$get_request->set_url_params(
			array(
				'post_id' => $post->ID,
			)
		);

		$get_response = $this->_api->get_coauthors( $get_request );
		$this->assertEquals( 'author', $get_response->data[0]['userNicename'] );
	}

	/**
	 * @covers CoAuthors\API\Endpoints::get_coauthors
	 */
	public function test_get_coauthors_wp_block_post_type(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'wp_block' ) );
		$request = new \WP_REST_Request( 'GET', '/coauthors/v1/authors/' . $post_id );
		$request->set_param( 'post_id', $post_id );

		$response = $this->_api->get_coauthors( $request );
		$this->assertEmpty( $response->get_data() );
	}

	/**
	 * @covers CoAuthors\API\Endpoints::get_coauthors
	 */
	public function test_get_coauthors_pattern_sync(): void {
		$post_id = self::factory()->post->create();
		$block_id = self::factory()->post->create( array( 'post_type' => 'wp_block' ) );
		$_SERVER['HTTP_REFERER'] = admin_url( sprintf( 'post.php?post=%d&action=edit', $block_id ) );

		$request = new \WP_REST_Request( 'GET', '/coauthors/v1/authors/' . $post_id );
		$request->set_param( 'post_id', $post_id );

		$response = $this->_api->get_coauthors( $request );
		$this->assertEmpty( $response->get_data() );

		unset( $_SERVER['HTTP_REFERER'] );
	}

	/**
	 * @covers \CoAuthors\API\Endpoints::update_coauthors
	 */
	public function test_update_coauthors(): void {
		$author       = $this->create_author();
		$editor       = $this->create_editor();
		$guest_author = $this->create_guest_author();
		$post   = $this->create_post( $author );
		wp_set_current_user( $editor->ID );

		$post_request = new \WP_REST_Request( 'POST' );
		$post_request->set_url_params(
			array(
				'post_id'     => $post->ID,
				'new_authors' => $author->user_nicename . ',guest_author',
			)
		);

		$update_response = $this->_api->update_coauthors( $post_request );

		$this->assertCount( 2, $update_response->data );
	}

	public function data_only_editor_role_can_edit_coauthors(): array {
		return array(
			'Subscriber' => array(
				'subscriber',
				false,
			),
			'Contributor' => array(
				'contributor',
				false,
			),
			'Author' => array(
				'author',
				false,
			),
			'Editor' => array(
				'editor',
				true,
			),
		);
	}

	/**
	 * @dataProvider data_only_editor_role_can_edit_coauthors
	 * @param $role_name
	 * @param $outcome
	 *
	 * @return void
	 */
	public function test_which_role_can_edit_coauthors( $role_name, $outcome ): void {
		$role = $this->{"create_$role_name"}();
		wp_set_current_user( $role->ID );
		$this->assertEquals( $outcome, $this->_api->can_edit_coauthors() );
	}

	/**
	 * @covers \CoAuthors\API\Endpoints::remove_author_link
	 */
	public function test_remove_author_link(): void {
		$editor = $this->create_editor();
		$post   = $this->create_post( $editor );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post->ID );

		wp_set_current_user( $editor->ID );

		$request->set_param( 'context', 'edit' );

		$response = rest_do_request( $request );

		$this->_api->remove_author_link( $response, $post, $request );

		$this->assertArrayNotHasKey(
			Endpoints::SUPPORT_LINK,
			$response->get_links(),
			'Failed to assert that link is removed when the block editor is loaded.'
		);

		add_filter( 'use_block_editor_for_post', '__return_false' );

		$response = rest_do_request( $request );

		$this->_api->remove_author_link( $response, $post, $request );

		$this->assertArrayHasKey(
			Endpoints::SUPPORT_LINK,
			$response->get_links(),
			'Failed to assert that links are unchanged when block editor is disabled.'
		);
	}

	/**
	 * @covers \CoAuthors\API\Endpoints::modify_responses
	 */
	public function test_modify_response(): void {
		$this->_api->modify_responses();

		foreach ( $this->_cap->supported_post_types() as $post_type ) {
			$this->assertEquals(
				10,
				has_filter(
					'rest_prepare_' . $post_type,
					array(
						$this->_api,
						'remove_author_link',
					)
				)
			);
		}
	}
}
