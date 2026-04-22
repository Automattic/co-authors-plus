<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use CoAuthors\API\Endpoints\CoAuthors_Controller;
use WP_Error;
use WP_REST_Request;

/**
 * @coversDefaultClass \CoAuthors\API\Endpoints\CoAuthors_Controller
 */
class CoAuthorsControllerTest extends TestCase {

	/**
	 * @var CoAuthors_Controller
	 */
	private $controller;

	public function set_up() {

		parent::set_up();

		global $coauthors_plus;

		$this->controller = new CoAuthors_Controller( $coauthors_plus );
	}

	private function make_request( string $path, array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * @covers ::get_items_permissions_check
	 */
	public function test_get_items_allows_anonymous_on_published_post(): void {

		$author = $this->create_author( 'public-author' );
		$post   = $this->create_post( $author );

		wp_set_current_user( 0 );

		$result = $this->controller->get_items_permissions_check(
			$this->make_request( '/coauthors/v1/coauthors', array( 'post_id' => $post->ID ) )
		);

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::get_items_permissions_check
	 */
	public function test_get_items_blocks_anonymous_on_draft_post(): void {

		$author  = $this->create_author( 'draft-author' );
		$post_id = self::factory()->post->create(
			array(
				'post_author' => $author->ID,
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( 0 );

		$result = $this->controller->get_items_permissions_check(
			$this->make_request( '/coauthors/v1/coauthors', array( 'post_id' => $post_id ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * @covers ::get_items_permissions_check
	 */
	public function test_get_items_blocks_anonymous_on_private_post(): void {

		$author  = $this->create_author( 'private-author' );
		$post_id = self::factory()->post->create(
			array(
				'post_author' => $author->ID,
				'post_status' => 'private',
			)
		);

		wp_set_current_user( 0 );

		$result = $this->controller->get_items_permissions_check(
			$this->make_request( '/coauthors/v1/coauthors', array( 'post_id' => $post_id ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * @covers ::get_items_permissions_check
	 */
	public function test_get_items_allows_editor_on_draft_post(): void {

		$author  = $this->create_author( 'draft-author-editor' );
		$editor  = $this->create_editor( 'editor-for-draft' );
		$post_id = self::factory()->post->create(
			array(
				'post_author' => $author->ID,
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( $editor->ID );

		$result = $this->controller->get_items_permissions_check(
			$this->make_request( '/coauthors/v1/coauthors', array( 'post_id' => $post_id ) )
		);

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::get_items_permissions_check
	 */
	public function test_get_items_returns_404_for_missing_post(): void {

		wp_set_current_user( 0 );

		$result = $this->controller->get_items_permissions_check(
			$this->make_request( '/coauthors/v1/coauthors', array( 'post_id' => 999999 ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
	}

	/**
	 * @covers ::get_item_permissions_check
	 */
	public function test_get_item_allows_anonymous_for_author_with_public_post(): void {

		$author = $this->create_author( 'published-author' );
		$post   = $this->create_post( $author );
		$this->_cap->add_coauthors( $post->ID, array( $author->user_nicename ) );

		wp_set_current_user( 0 );

		$result = $this->controller->get_item_permissions_check(
			$this->make_request( '/coauthors/v1/coauthors/published-author', array( 'user_nicename' => 'published-author' ) )
		);

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::get_item_permissions_check
	 */
	public function test_get_item_blocks_anonymous_for_author_without_public_posts(): void {

		$author  = $this->create_author( 'hidden-author' );
		$post_id = self::factory()->post->create(
			array(
				'post_author' => $author->ID,
				'post_status' => 'draft',
			)
		);
		$this->_cap->add_coauthors( $post_id, array( $author->user_nicename ) );

		wp_set_current_user( 0 );

		$result = $this->controller->get_item_permissions_check(
			$this->make_request( '/coauthors/v1/coauthors/hidden-author', array( 'user_nicename' => 'hidden-author' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * @covers ::get_item_permissions_check
	 */
	public function test_get_item_allows_editor_for_any_author(): void {

		$author = $this->create_author( 'any-author' );
		$editor = $this->create_editor( 'editor-for-any' );

		wp_set_current_user( $editor->ID );

		$result = $this->controller->get_item_permissions_check(
			$this->make_request( '/coauthors/v1/coauthors/any-author', array( 'user_nicename' => 'any-author' ) )
		);

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::get_item_permissions_check
	 */
	public function test_get_item_blocks_anonymous_for_unknown_author(): void {

		wp_set_current_user( 0 );

		$result = $this->controller->get_item_permissions_check(
			$this->make_request( '/coauthors/v1/coauthors/does-not-exist', array( 'user_nicename' => 'does-not-exist' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}
}
