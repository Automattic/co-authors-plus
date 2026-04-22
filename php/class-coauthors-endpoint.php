<?php

namespace CoAuthors\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_Post;

/**
 * Class Endpoint.
 */
class Endpoints {

	/**
	 * Namespace for our endpoints.
	 */
	const NS = 'coauthors/v1';

	/**
	 * Routes for various endpoints.
	 */
	const SEARCH_ROUTE  = 'search';
	const AUTHORS_ROUTE = 'authors';

	/**
	 * Link to remove from REST response to manage core author visibility in
	 * admin.
	 */
	const SUPPORT_LINK = 'https://api.w.org/action-assign-author';

	/**
	 * Regex to capture the query in a request.
	 */
	const ENDPOINT_POST_ID_REGEX = '/(?P<post_id>[\d]+)';

	/**
	 * An instance of the Co_Authors_Plus class.
	 */
	public $coauthors;

	/**
	 * WP_REST_API constructor.
	 */
	public function __construct( $coauthors_instance ) {
		$this->coauthors = $coauthors_instance;

		add_action( 'rest_api_init', array( $this, 'add_endpoints' ) );
		add_action( 'wp_loaded', array( $this, 'modify_responses' ) );
	}

	/**
	 * Register endpoints.
	 */
	public function add_endpoints(): void {
		register_rest_route(
			static::NS,
			static::SEARCH_ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_coauthors_search_results' ),
					'permission_callback' => array( $this, 'can_edit_coauthors' ),
					'args'                => array(
						'q'                => array(
							'description' => __( 'Text to search.', 'co-authors-plus' ),
							'required'    => false,
							'type'        => 'string',
						),
						'existing_authors' => array(
							'description' => __( 'Names of existing co-authors to exclude from search results.', 'co-authors-plus' ),
							'type'        => 'string',
							'required'    => false,
						),
					),
				),
			)
		);

		register_rest_route(
			static::NS,
			static::AUTHORS_ROUTE . static::ENDPOINT_POST_ID_REGEX,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_coauthors' ),
					'permission_callback' => array( $this, 'can_edit_coauthors' ),
					'args'                => array(
						'post_id' => array(
							'required'          => true,
							'type'              => 'number',
							'validate_callback' => array( $this, 'validate_numeric' ),
						),
					),
				),
			)
		);

		register_rest_route(
			static::NS,
			static::AUTHORS_ROUTE . static::ENDPOINT_POST_ID_REGEX,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_coauthors' ),
					'permission_callback' => array( $this, 'can_edit_coauthors' ),
					'args'                => array(
						'post_id'     => array(
							'required'          => true,
							'type'              => 'number',
							'validate_callback' => array( $this, 'validate_numeric' ),
						),
						'new_authors' => array(
							'description' => __( 'Names of co-authors to save.', 'co-authors-plus' ),
							'type'        => 'string',
							'required'    => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Search and return authors based on a text query.
	 *
	 * @param WP_REST_Request   $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_coauthors_search_results( $request ): WP_REST_Response {
		$response = array();

		$search  = strtolower( $request->get_param( 'q' ) );
		$ignorable = $request->get_param( 'existing_authors' ) ?? '';
		$ignore  = explode( ',', $ignorable );
		$authors = $this->coauthors->search_authors( $search, $ignore );

		if ( ! empty( $authors ) ) {
			foreach ( $authors as $author ) {
				$response[] = $this->_format_author_data( $author );
			}
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Return a single author.
	 *
	 * @param WP_REST_Request   $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_coauthors( $request ): WP_REST_Response {
		$response = array();

		if ( ! $this->request_is_for_wp_block_post_type( $request ) && ! $this->is_pattern_sync_operation() ) {
			$this->_build_authors_response( $response, $request );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Update co-authors.
	 *
	 * @param WP_REST_Request   $request Request object.
	 * @return WP_REST_Response
	 */
	public function update_coauthors( $request ): WP_REST_Response {

		$response = array();

		if ( ! empty( $request->get_param( 'new_authors' ) ) ) {
			$coauthors = explode( ',', $request->get_param( 'new_authors' ) );

			// Replace all existing authors
			$this->coauthors->add_coauthors( $request->get_param( 'post_id' ), $coauthors );

			$this->_build_authors_response( $response, $request );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Validate input arguments.
	 *
	 * @param mixed $param Value to validate.
	 * @return bool
	 */
	public function validate_numeric( $param ): bool {
		return is_numeric( $param );
	}

	/**
	 * Permissions for updating co-authors.
	 *
	 * @return bool
	 */
	public function can_edit_coauthors(): bool {
		return $this->coauthors->current_user_can_set_authors();
	}

	/**
	 * Helper function to consistently format the author data for
	 * the response.
	 *
	 * @param object  $author The result from co-authors methods.
	 * @return array
	 */
	public function _format_author_data( $author ): array {

		return array(
			'id'           => esc_html( $author->ID ),
			'userNicename' => esc_html( rawurldecode( $author->user_nicename ) ),
			'login'        => esc_html( $author->user_login ),
			'email'        => sanitize_email( $author->user_email ),
			'displayName'  => strip_tags( str_replace( '∣', '|', $author->display_name ) ),
			'avatar'       => esc_url( get_avatar_url( $author->ID ) ),
			'userType'     => esc_html( $author->type ),
		);
	}

	/**
	 * Get authors' data and add it to the response.
	 *
	 * @param array The response array.
	 * @param int   The post ID from the request.
	 */
	public function _build_authors_response( &$response, $request ): void {
		$authors = get_coauthors( $request->get_param( 'post_id' ) );

		if ( ! empty( $authors ) ) {
			foreach ( $authors as $author ) {
				$response[] = $this->_format_author_data( $author );
			}
		}
	}

	/**
	 * Check if the request is for a wp_block post type.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	private function request_is_for_wp_block_post_type( WP_REST_Request $request ): bool {
		return 'wp_block' === get_post_type( $request->get_param( 'post_id' ) );
	}

	/**
	 * Check if this is a pattern sync operation.
	 *
	 * @return bool
	 */
	private function is_pattern_sync_operation(): bool {
		$referer = wp_get_referer();
		if ( ! $referer ) {
			return false;
		}

		$query_string = wp_parse_url( $referer, PHP_URL_QUERY );
		if ( ! $query_string ) {
			return false;
		}

		parse_str( $query_string, $query_vars );
		return ! empty( $query_vars['post'] ) && 'wp_block' === get_post_type( $query_vars['post'] );
	}

	/**
	 * Add filters to REST endpoints for each post that
	 * supports co-authors.
	 */
	public function modify_responses(): void {

		$post_types = $this->coauthors->supported_post_types();

		if ( empty( $post_types ) || ! is_array( $post_types ) ) {
			return;
		}

		foreach ( $post_types as $post_type ) {
			add_filter(
				'rest_prepare_' . $post_type,
				array( $this, 'remove_author_link' ),
				10,
				3
			);
		}
	}

	/**
	 * Remove the link for wp:action-assign-author to remove the author
	 * select from the document sidebar.
	 *
	 * @see https://github.com/WordPress/gutenberg/pull/6630
	 *
	 * @param WP_REST_Response  $response Response object.
	 * @param WP_Post           $post     The current post object.
	 * @param WP_REST_Request   $request  Request object.
	 * @return WP_REST_Response
	 */
	public function remove_author_link( $response, $post, $request ): WP_REST_Response {
		if (
			! isset( $request['context'] )
			|| 'edit' !== $request['context']
		) {
			return $response;
		}

		require_once ABSPATH . '/wp-admin/includes/post.php';

		if ( ! \use_block_editor_for_post( $post ) ) {
			return $response;
		}

		$links = $response->get_links();

		if ( ! isset( $links[ static::SUPPORT_LINK ] ) ) {
			return $response;
		}

		$response->remove_link( static::SUPPORT_LINK );

		return $response;
	}
}
