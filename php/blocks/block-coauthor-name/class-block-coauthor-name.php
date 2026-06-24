<?php
/**
 * Co-Author Name Block
 * 
 * @package Automattic\CoAuthorsPlus
 * @since 3.6.0
 */

namespace CoAuthors\Blocks; 

use WP_Block;

/**
 * Block CoAuthor Name
 * 
 * @package CoAuthors
 */
class Block_CoAuthor_Name {
	/**
	 * Register Block
	 *
	 * @since 3.6.0
	 */
	public static function register_block(): void {
		register_block_type(
			dirname( COAUTHORS_PLUS_FILE ) . '/build/blocks/block-coauthor-name',
			array(
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
	}

	/**
	 * Render Block
	 *
	 * @since 3.6.0
	 * @param array    $attributes
	 * @param string   $content
	 * @param WP_Block $block
	 * @return string
	 */
	public static function render_block( array $attributes, string $content, WP_Block $block ): string {

		$author = $block->context['co-authors-plus/author'] ?? array();

		if ( empty( $author ) ) {
			return '';
		}

		$display_name = esc_html( $author['display_name'] ?? '' );
		$link         = esc_url( $author['link'] ?? '' );

		if ( '' === $display_name ) {
			return '';
		}

		$attributes = array_merge(
			array(
				'isLink'    => false,
				'rel'       => '',
				'tagName'   => 'p',
				'textAlign' => '',
			),
			$attributes
		);

		if ( '' !== $link && true === $attributes['isLink'] ) {
			$link_attributes = Templating::render_attributes(
				array(
					'href'  => $link,
					'rel'   => $attributes['rel'],
					/* translators: %s: author display name */
					'title' => sprintf( __( 'Posts by %s', 'co-authors-plus' ), $display_name ),
				)
			);
			$inner_content   = Templating::render_element( 'a', $link_attributes, $display_name );
		} else {
			$inner_content = $display_name;
		}

		$tag_name = self::sanitize_tag_name( $attributes['tagName'] );

		return Templating::render_element(
			$tag_name,
			get_block_wrapper_attributes(
				self::get_custom_block_wrapper_attributes( $attributes )
			),
			$inner_content
		);
	}

	/**
	 * Sanitize Tag Name
	 *
	 * @since 3.6.0
	 * @param string $tag_name
	 * @return string
	 */
	public static function sanitize_tag_name( string $tag_name ): string {
		if ( in_array( $tag_name, array_keys( wp_kses_allowed_html( 'post' ) ), true ) ) {
			return $tag_name;
		}
		return 'p';
	}

	/**
	 * Get Custom Block Wrapper Attributes
	 *
	 * @since 3.6.0
	 * @param array $attributes
	 * @return array
	 */
	public static function get_custom_block_wrapper_attributes( array $attributes ): array {

		$text_align = $attributes['textAlign'] ?? '';

		if ( empty( $text_align ) ) {
			return array();
		}
		return array(
			'class' => sanitize_html_class( "has-text-align-{$text_align}" ),
		);
	}
}
