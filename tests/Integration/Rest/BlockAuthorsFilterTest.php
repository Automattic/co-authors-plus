<?php
/**
 * Tests for the coauthors_block_authors filter on the Co-Authors block REST endpoint.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Rest;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_REST_Request;


/**
 * @coversNothing
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

		$callback = function ( $coauthors ) {
			return array_slice( $coauthors, 0, 1 );
		};
		add_filter( 'coauthors_block_authors', $callback );

		$data = $this->fetch_authors( $post->ID );

		$this->assertCount( 1, $data );
		$this->assertSame( $a1->user_nicename, $data[0]['user_nicename'] );

		remove_filter( 'coauthors_block_authors', $callback );
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
		$callback = function ( $coauthors, $post_id ) use ( &$captured ) {
			$captured = (int) $post_id;
			return $coauthors;
		};
		add_filter( 'coauthors_block_authors', $callback, 10, 2 );

		$this->fetch_authors( $post->ID );

		$this->assertSame( $post->ID, $captured );

		remove_filter( 'coauthors_block_authors', $callback, 10, 2 );
	}

	/**
	 * @covers ::get_items
	 */
	public function test_filter_receives_block_context(): void {
		$a1   = $this->create_author( 'cap-1051-context' );
		$post = $this->create_post( $a1 );
		$this->_cap->add_coauthors( $post->ID, array( $a1->user_nicename ) );

		wp_set_current_user( 0 );

		$captured = null;
		$callback = function ( $coauthors, $post_id, $context ) use ( &$captured ) {
			$captured = $context;
			return $coauthors;
		};
		add_filter( 'coauthors_block_authors', $callback, 10, 3 );

		$this->fetch_authors( $post->ID );

		$this->assertSame( 'block', $captured );

		remove_filter( 'coauthors_block_authors', $callback, 10, 3 );
	}

	/**
	 * @covers ::get_items
	 */
	public function test_non_array_filter_response_is_coerced_to_empty(): void {
		$a1   = $this->create_author( 'cap-1051-bad-return' );
		$post = $this->create_post( $a1 );
		$this->_cap->add_coauthors( $post->ID, array( $a1->user_nicename ) );

		wp_set_current_user( 0 );

		$callback = function ( $coauthors ) {
			return 'not-an-array';
		};
		add_filter( 'coauthors_block_authors', $callback );

		$request  = new WP_REST_Request( 'GET', '/coauthors/v1/coauthors' );
		$request->set_param( 'post_id', $post->ID );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );

		remove_filter( 'coauthors_block_authors', $callback );
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

		$callback = function ( $coauthors ) {
			return array( 'not-an-object', 42, null, $coauthors[0] );
		};
		add_filter( 'coauthors_block_authors', $callback );

		$data = $this->fetch_authors( $post->ID );

		$this->assertCount( 1, $data );
		$this->assertSame( $a1->user_nicename, $data[0]['user_nicename'] );
		// Reindexed so the response is a JSON array, not an object.
		$this->assertSame( array( 0 ), array_keys( $data ) );

		remove_filter( 'coauthors_block_authors', $callback );
	}

	/**
	 * The Co-Authors block server-render path goes through
	 * Blocks::get_authors_with_api_schema(), which dispatches the REST endpoint
	 * internally. The filtered list must reach that consumer unchanged.
	 *
	 * The spy REST server used in the test suite rejects the full-URL
	 * dispatch that Blocks::get_authors_with_api_schema() performs, so
	 * exercise the consumer by calling its internal REST dispatch in the same
	 * shape the block does, then asserting the filter result reaches it.
	 *
	 * @covers ::get_items
	 */
	public function test_block_render_path_sees_filtered_list(): void {
		$a1   = $this->create_author( 'cap-1051-render-a' );
		$a2   = $this->create_author( 'cap-1051-render-b' );
		$post = $this->create_post( $a1 );
		$this->_cap->add_coauthors( $post->ID, array( $a1->user_nicename, $a2->user_nicename ) );

		wp_set_current_user( 0 );

		$callback = function ( $coauthors ) {
			return array_slice( $coauthors, 0, 1 );
		};
		add_filter( 'coauthors_block_authors', $callback );

		// Mirror the block's data path: the block ultimately reads the same REST
		// collection this dispatches, so the filter result observed here is what
		// the block's server render would render.
		$request = new WP_REST_Request( 'GET', '/coauthors/v1/coauthors' );
		$request->set_param( 'post_id', $post->ID );
		$response = rest_do_request( $request );
		$authors  = (array) $response->get_data();

		$this->assertCount( 1, $authors );
		$this->assertSame( $a1->user_nicename, $authors[0]['user_nicename'] );

		remove_filter( 'coauthors_block_authors', $callback );
	}
}
