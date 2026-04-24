<?php
/**
 * Co-Author Image
 * 
 * @package Automattic\CoAuthorsPlus
 * @since 3.6.0
 */

namespace CoAuthors\Blocks; 

use WP_Block;

/**
 * Block CoAuthor Image
 *
 * @package CoAuthors
 */
class Block_CoAuthor_Image {
	/**
	 * Register Block
	 *
	 * @since 3.6.0
	 */
	public static function register_block(): void {
		register_block_type(
			dirname( COAUTHORS_PLUS_FILE ) . '/build/blocks/block-coauthor-image',
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
		$layout = $block->context['co-authors-plus/layout'] ?? '';

		if ( empty( $author ) ) {
			return '';
		}

		$featured_media_id = absint( $author['featured_media'] ?? 0 );
		$display_name      = esc_html( $author['display_name'] ?? '' );
		$link              = esc_url( $author['link'] ?? '' );
		$align             = esc_attr( $attributes['align'] ?? '' );

		if ( 0 === $featured_media_id ) {
			return '';
		}

		$attributes = array_merge(
			array(
				'width'         => '',
				'height'        => '',
				'sizeSlug'      => 'thumbnail',
				'scale'         => '',
				'aspectRatio'   => '',
				'isLink'        => false,
				'rel'           => '',
				'verticalAlign' => '',
			),
			$attributes
		);

		if ( empty( $attributes['width'] ) && ! empty( $attributes['height'] ) ) {
			$attributes['width'] = 'auto';
		}

		$style_attribute_key_map = array(
			'width'         => 'width',
			'height'        => 'height',
			'scale'         => 'object-fit',
			'aspectRatio'   => 'aspect-ratio',
			'verticalAlign' => 'vertical-align',
		);

		$styles = array_map(
			function( string $key, string $style ) use ( $attributes ) : string {
				if ( empty( $attributes[ $key ] ) ) {
					return '';
				}
				return sprintf(
					'%s;',
					safecss_filter_attr(
						"{$style}:{$attributes[$key]}"
					)
				);
			},
			array_keys( $style_attribute_key_map ),
			array_values( $style_attribute_key_map )
		);

		$image_attributes = array_merge(
			array(
				'class' => '',
				'style' => '',
			),
			get_block_core_post_featured_image_border_attributes( $attributes )
		);

		$image_attributes['style'] .= implode( '', $styles );

		$feature_image = wp_get_attachment_image( $featured_media_id, $attributes['sizeSlug'], false, $image_attributes );

		if ( '' !== $link && true === $attributes['isLink'] ) {
			$link_attributes = Templating::render_attributes(
				array(
					'href'  => $link,
					'rel'   => $attributes['rel'],
					'title' => sprintf( __( 'Posts by %s', 'co-authors-plus' ), $display_name ),
				)
			);
			$inner_content   = Templating::render_element( 'a', $link_attributes, $feature_image );
		} else {
			$inner_content = $feature_image;
		}

		return Templating::render_element(
			'figure',
			get_block_wrapper_attributes(
				array(
					'class' => ( 'default' !== $layout && ! empty( $align ) && 'none' !== $align ) ? "align{$align}" : ''
				)
			),
			$inner_content
		);
	}
}
