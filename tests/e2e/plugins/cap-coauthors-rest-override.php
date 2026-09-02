<?php
/**
 * Plugin Name: CAP Coauthors REST Override
 * Description: Reproduces the widespread third-party snippet that overrides the
 *              `coauthors` REST field on posts to return full author objects
 *              (carrying user_id, no term_id) instead of the author taxonomy's
 *              default term-ID array. This is the shape that broke the block
 *              editor Authors panel after 4.x moved to the core entity store.
 * Version:     1.0.0
 *
 * @package CoAuthorsPlus\Tests
 */

add_action(
	'rest_api_init',
	function () {
		register_rest_field(
			'post',
			'coauthors',
			array(
				'get_callback' => function ( $post_arr ) {
					if ( ! function_exists( 'get_coauthors' ) ) {
						return array();
					}

					return array_map(
						function ( $coauthor ) {
							// Deliberately object-shaped, with a user_id and NO
							// term_id — the exact payload reported in #1277.
							return array(
								'user_id'       => (int) $coauthor->ID,
								'display_name'  => $coauthor->display_name,
								'user_nicename' => $coauthor->user_nicename,
							);
						},
						get_coauthors( $post_arr['id'] )
					);
				},
				'schema'       => null,
			)
		);
	},
	20
);
