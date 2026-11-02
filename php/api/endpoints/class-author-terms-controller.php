<?php
/**
 * Author Terms Controller
 *
 * @package Automattic\CoAuthorsPlus
 * @since 4.2.0
 */

namespace CoAuthors\API\Endpoints;

use WP_Error;
use WP_REST_Request;
use WP_REST_Terms_Controller;

/**
 * Core terms controller for the `author` taxonomy (`/wp/v2/coauthors`).
 *
 * Each author term's name is the co-author's user login, which core keeps out
 * of anonymous REST responses. Reading these terms is therefore limited to
 * users who can edit posts. The `coauthors` field on post responses is not
 * affected; front ends should resolve bylines through `coauthors/v1`.
 */
class Author_Terms_Controller extends WP_REST_Terms_Controller {

	/**
	 * Checks if a request has access to read author terms.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->forbidden();
		}

		return parent::get_items_permissions_check( $request );
	}

	/**
	 * Checks if a request has access to read an author term.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->forbidden();
		}

		return parent::get_item_permissions_check( $request );
	}

	/**
	 * Error returned to users who cannot read author terms.
	 *
	 * @return WP_Error
	 */
	private function forbidden(): WP_Error {
		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to view co-authors.', 'co-authors-plus' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
