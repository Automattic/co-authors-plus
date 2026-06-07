<?php
/**
 * Co-Author Avatar
 *
 * @package Automattic\CoAuthorsPlus
 * @since 3.6.0
 */

namespace CoAuthors\Blocks;

use WP_Block;
/**
 * Block CoAuthor Avatar
 *
 * @package CoAuthors
 */
class Block_CoAuthor_Avatar {

	/**
	 * Register Block
	 *
	 * @since 3.6.0
	 */
	public static function register_block(): void {
		register_block_type(
			dirname( COAUTHORS_PLUS_FILE ) . '/build/blocks/block-coauthor-avatar',
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

		$avatar_urls = $author['avatar_urls'] ?? array();

		if ( empty( $avatar_urls ) ) {
			return '';
		}

		$display_name = esc_html( $author['display_name'] ?? '' );
		$link         = esc_url( $author['link'] ?? '' );
		$is_link      = '' !== $link && $attributes['isLink'] ?? false;
		$rel          = $attributes['rel'] ?? '';
		$size         = $attributes['size'] ?? array_key_first( $avatar_urls );
		$align        = esc_attr( $attributes['align'] ?? '' );

		$srcset = array_map(
			function( $size, $url ) {
				return "{$url} {$size}w";
			},
			array_keys( $avatar_urls ),
			array_values( $avatar_urls )
		);

		$image_attributes = array_merge(
			array(
				'src'    => $avatar_urls[ $size ],
				'width'  => $size,
				'height' => $size,
				'sizes'  => "{$size}px",
				'srcset' => implode( ', ', $srcset ),
				'style'  => '',
				'class'  => '',
			),
			get_block_core_post_featured_image_border_attributes( $attributes )
		);

		$style_attribute_key_map = array(
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

		$image_attributes['style'] .= implode( '', $styles );

		$image = Templating::render_self_closing_element(
			'img',
			Templating::render_attributes( $image_attributes )
		);

		if ( $is_link ) {
			$link_attributes = Templating::render_attributes(
				array(
					'href'  => $link,
					'rel'   => $rel,
					/* translators: %s: author display name */
					'title' => sprintf( __( 'Posts by %s', 'co-authors-plus' ), $display_name ),
				)
			);
			$inner_content   = Templating::render_element( 'a', $link_attributes, $image );
		} else {
			$inner_content = $image;
		}

		return Templating::render_element(
			'div',
			get_block_wrapper_attributes(
				array(
					'class' => ( 'default' !== $layout && ! empty( $align ) && 'none' !== $align ) ? "align{$align}" : ''
				)
			),
			$inner_content
		);
	}
}
