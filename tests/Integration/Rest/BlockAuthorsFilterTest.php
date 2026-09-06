<?php
/**
 * Tests for the rest_coauthors_prepare_items filter on the coauthors/v1/coauthors collection endpoint.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Rest;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use CoAuthors\Blocks;
use WP_REST_Request;

/**
 * @coversDefaultClass \CoAuthors\API\Endpoints\CoAuthors_Controller
 */
class BlockAuthorsFilterTest extends TestCase {

	private function fetch_authors( int $post_id ): array {
		$request = new WP_REST_Request( 'GET', '/coauthors/v1/coauthors' );
		$request->set_param( 'post_id', $post_id );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		return (array) $response->get_data();
	}

	/**
	 * @covers ::get_items
	 */
	public function test_default_behavior_returns_all_coauthors(): void {
		$a1   = $this->create_author( 'cap-1051-default-one' );
		$a2   = $this->create_author( 'cap-1051-default-two' );
		$post = $this->create_post( $a1 );
		$this->_cap->add_coauthors( $post->ID, array( $a1->user_nicename, $a2->user_nicename ) );

		wp_set_current_user( 0 );

		$data = $this->fetch_authors( $post->ID );

		$this->assertCount( 2, $data );
	}

	/**
	 * @covers ::get_items
	 */
	public function test_filter_can_limit_to_primary_author(): void {
		$a1   = $this->create_author( 'cap-1051-primary' );
		$a2   = $this->create_author( 'cap-1051-secondary' );
		$post = $this->create_post( $a1 );
		$this->_cap->add_coauthors( $post->ID, array( $a1->user_nicename, $a2->user_nicename ) );

		wp_set_current_user( 0 );

		$callback = static function ( $coauthors ) {
			return array_slice( $coauthors, 0, 1 );
		};
		add_filter( 'rest_coauthors_prepare_items', $callback );

		$data = $this->fetch_authors( $post->ID );

		$this->assertCount( 1, $data );
		$this->assertSame( $a1->user_nicename, $data[0]['user_nicename'] );

		remove_filter( 'rest_coauthors_prepare_items', $callback );
	}

	/**
	 * @covers ::get_items
	 */
	public function test_filter_receives_post_id(): void {
		$a1   = $this->create_author( 'cap-1051-post-id' );
		$post = $this->create_post( $a1 );
		$this->_cap->add_coauthors( $post->ID, array( $a1->user_nicename ) );

		wp_set_current_user( 0 );

		$captured = null;
		$callback = static function ( $coauthors, $post_id ) use ( &$captured ) {
			$captured = (int) $post_id;
			return $coauthors;
		};
		add_filter( 'rest_coauthors_prepare_items', $callback, 10, 2 );

		$this->fetch_authors( $post->ID );

		$this->assertSame( $post->ID, $captured );

		remove_filter( 'rest_coauthors_prepare_items', $callback );
	}

	/**
	 * @covers ::get_items
	 */
	public function test_filter_receives_request_object(): void {
		$a1   = $this->create_author( 'cap-1051-request' );
		$post = $this->create_post( $a1 );
		$this->_cap->add_coauthors( $post->ID, array( $a1->user_nicename ) );

		wp_set_current_user( 0 );

		$captured = null;
		$callback = static function ( $coauthors, $post_id, $request ) use ( &$captured ) {
			$captured = $request;
			return $coauthors;
		};
		add_filter( 'rest_coauthors_prepare_items', $callback, 10, 3 );

		$this->fetch_authors( $post->ID );

		$this->assertInstanceOf( WP_REST_Request::class, $captured );
		$this->assertSame( $post->ID, (int) $captured->get_param( 'post_id' ) );

		remove_filter( 'rest_coauthors_prepare_items', $callback );
	}

	/**
	 * @covers ::get_items
	 */
	public function test_non_array_filter_response_is_coerced_to_empty(): void {
		$a1   = $this->create_author( 'cap-1051-bad-return' );
		$post = $this->create_post( $a1 );
		$this->_cap->add_coauthors( $post->ID, array( $a1->user_nicename ) );

		wp_set_current_user( 0 );

		$callback = static function ( $coauthors ) {
			return 'not-an-array';
		};
		add_filter( 'rest_coauthors_prepare_items', $callback );

		$request  = new WP_REST_Request( 'GET', '/coauthors/v1/coauthors' );
		$request->set_param( 'post_id', $post->ID );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );

		remove_filter( 'rest_coauthors_prepare_items', $callback );
	}

	/**
	 * @covers ::get_items
	 */
	public function test_invalid_entries_and_sparse_keys_are_dropped(): void {
		$a1   = $this->create_author( 'cap-1051-sparse-a' );
		$a2   = $this->create_author( 'cap-1051-sparse-b' );
		$post = $this->create_post( $a1 );
		$this->_cap->add_coauthors( $post->ID, array( $a1->user_nicename, $a2->user_nicename ) );

		wp_set_current_user( 0 );

		$callback = static function ( $coauthors ) {
			return array( 'not-an-object', 42, null, $coauthors[0] );
		};
		add_filter( 'rest_coauthors_prepare_items', $callback );

		$data = $this->fetch_authors( $post->ID );

		$this->assertCount( 1, $data );
		$this->assertSame( $a1->user_nicename, $data[0]['user_nicename'] );
		// Reindexed so the response is a JSON array, not an object.
		$this->assertSame( array( 0 ), array_keys( $data ) );

		remove_filter( 'rest_coauthors_prepare_items', $callback );
	}

	/**
	 * A bare object that is not a valid co-author (missing ID, description,
	 * display_name and user_nicename) must be dropped by the guard rather than
	 * reaching prepare_item_for_response(), which reads those properties.
	 *
	 * @covers ::get_items
	 */
	public function test_non_coauthor_object_is_dropped(): void {
		$a1   = $this->create_author( 'cap-1051-stdclass' );
		$post = $this->create_post( $a1 );
		$this->_cap->add_coauthors( $post->ID, array( $a1->user_nicename ) );

		wp_set_current_user( 0 );

		$callback = static function ( $coauthors ) {
			return array( new \stdClass(), $coauthors[0] );
		};
		add_filter( 'rest_coauthors_prepare_items', $callback );

		$data = $this->fetch_authors( $post->ID );

		$this->assertCount( 1, $data );
		$this->assertSame( $a1->user_nicename, $data[0]['user_nicename'] );

		remove_filter( 'rest_coauthors_prepare_items', $callback );
	}

	/**
	 * The Co-Authors block server render goes through
	 * Blocks::get_authors_with_api_schema(), which dispatches the REST endpoint
	 * from a full URL. Under plain permalinks `rest_url()` produces a
	 * `?rest_route=` query string that `WP_REST_Request::from_url()` cannot
	 * resolve to a route, so pretty permalinks are required here.
	 *
	 * @covers ::get_items
	 */
	public function test_block_render_path_sees_filtered_list(): void {
		$this->set_permalink_structure( '/%postname%/' );

		$a1   = $this->create_author( 'cap-1051-render-a' );
		$a2   = $this->create_author( 'cap-1051-render-b' );
		$post = $this->create_post( $a1 );
		$this->_cap->add_coauthors( $post->ID, array( $a1->user_nicename, $a2->user_nicename ) );

		wp_set_current_user( 0 );

		$callback = static function ( $coauthors ) {
			return array_slice( $coauthors, 0, 1 );
		};
		add_filter( 'rest_coauthors_prepare_items', $callback );

		$authors = Blocks::get_authors_with_api_schema( $post->ID );

		$this->assertCount( 1, $authors );
		$this->assertSame( $a1->user_nicename, $authors[0]['user_nicename'] );

		remove_filter( 'rest_coauthors_prepare_items', $callback );
	}

	/**
	 * The duplicate objects produced by get_coauthors() on posts with repeated
	 * co-author terms used to serialise as a JSON object because array_map()
	 * preserves non-sequential keys. The reindex must fix that at the source.
	 *
	 * @covers ::get_items
	 */
	public function test_duplicate_coauthors_response_is_reindexed(): void {
		$a1   = $this->create_author( 'cap-1051-duplicate-a' );
		$a2   = $this->create_author( 'cap-1051-duplicate-b' );
		$post = $this->create_post( $a1 );
		$this->_cap->add_coauthors( $post->ID, array( $a1->user_nicename, $a2->user_nicename, $a1->user_nicename ) );

		wp_set_current_user( 0 );

		$data = $this->fetch_authors( $post->ID );

		$this->assertCount( 2, $data );
		$this->assertSame( array( 0, 1 ), array_keys( $data ) );
		$this->assertSame(
			array( $a1->user_nicename, $a2->user_nicename ),
			array_column( $data, 'user_nicename' )
		);
	}
}
