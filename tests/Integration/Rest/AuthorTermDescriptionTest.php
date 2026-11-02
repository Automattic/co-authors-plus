<?php
/**
 * Who can see an author term's description through core's REST API.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Rest;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_REST_Request;

/**
 * The description is the plugin's search index for a co-author, including their
 * email address, so only users who can list users see it.
 *
 * @covers \CoAuthors_Plus::conditionally_hide_author_term_description
 */
class AuthorTermDescriptionTest extends TestCase {

	/** @var \WP_User */
	private $coauthor;

	public function set_up() {
		parent::set_up();
		$this->coauthor = $this->create_editor( 'described' );
		$this->_cap->update_author_term( $this->coauthor );
	}

	private function get_author_term_via_rest(): array {
		$term_id = (int) $this->_cap->get_author_term( $this->coauthor )->term_id;

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/coauthors/' . $term_id ) );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	public static function data_users_who_cannot_list_users(): array {
		return array(
			'contributor' => array( 'contributor' ),
			'author'      => array( 'author' ),
			'editor'      => array( 'editor' ),
		);
	}

	/**
	 * @dataProvider data_users_who_cannot_list_users
	 */
	public function test_description_is_hidden_from_user_who_cannot_list_users( string $role ): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => $role ) ) );

		$this->assertArrayNotHasKey( 'description', $this->get_author_term_via_rest() );
	}

	public function test_description_is_shown_to_user_who_can_list_users(): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertStringContainsString( $this->coauthor->user_email, $this->get_author_term_via_rest()['description'] );
	}
}
