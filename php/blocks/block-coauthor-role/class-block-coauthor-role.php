<?php
/**
 * Co-Author Role Block
 *
 * @package Automattic\CoAuthorsPlus
 * @since 4.2.0
 */

namespace CoAuthors\Blocks;

use WP_Block;

/**
 * Block CoAuthor Role
 *
 * @package CoAuthors
 */
class Block_CoAuthor_Role {
	/**
	 * Register Block
	 *
	 * @since 4.2.0
	 */
	public static function register_block(): void {
		register_block_type(
			dirname( COAUTHORS_PLUS_FILE ) . '/build/blocks/block-coauthor-role',
			array(
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
	}

	/**
	 * Render Block
	 *
	 * @since 4.2.0
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block content.
	 * @param WP_Block $block      Block instance, with the author in its context.
	 * @return string
	 */
	public static function render_block( array $attributes, string $content, WP_Block $block ): string {

		$author = $block->context['co-authors-plus/author'] ?? array();

		if ( empty( $author ) ) {
			return '';
		}

		$role = esc_html( $author['role'] ?? '' );

		if ( '' === $role ) {
			return '';
		}

		$text_align         = $attributes['textAlign'] ?? '';
		$wrapper_attributes = empty( $text_align ) ? array() : array( 'class' => sanitize_html_class( "has-text-align-{$text_align}" ) );

		return Templating::render_element(
			'p',
			get_block_wrapper_attributes( $wrapper_attributes ),
			$role
		);
	}
}
