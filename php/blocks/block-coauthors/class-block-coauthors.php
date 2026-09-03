<?php
/**
 * Co-Authors Block
 * 
 * @package Automattic\CoAuthorsPlus
 * @since 3.6.0
 */

namespace CoAuthors\Blocks;

use WP_Block;
use CoAuthors\Blocks;

/**
 * Block CoAuthors
 * 
 * @package CoAuthors
 */
class Block_CoAuthors {
	/**
	 * Register Block
	 *
	 * @since 3.6.0
	 */
	public static function register_block(): void {

		add_filter( 'block_type_metadata_settings', array( __CLASS__, 'separator_internationalization' ), 10, 2 );

		register_block_type(
			dirname( COAUTHORS_PLUS_FILE ) . '/build/blocks/block-coauthors',
			array(
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
	}

	/**
	 * Separator Internationalization
	 * Provide i18n for the default prefix, separators and suffix attributes during block registration.
	 *
	 * @param array $settings Array of determined settings for registering a block type.
	 * @param array $metadata Metadata provided for registering a block type.
	 * @return array Updated settings that include internationalized attributes.
	 */
	public static function separator_internationalization( array $settings, array $metadata ): array {

		if ( ! array_key_exists( 'name', $metadata ) ) {
			return $settings;
		}

		if ( 'co-authors-plus/coauthors' !== $metadata['name'] ) {
			return $settings;
		}

		return array_merge(
			$settings,
			array(
				'attributes' => array_merge(
					$settings['attributes'],
					array(
						'prefix'        => array(
							'type'    => 'string',
							'default' => apply_filters( 'coauthors_default_before', __( 'By ', 'co-authors-plus' ) ),
						),
						'separator'     => array(
							'type'    => 'string',
							'default' => apply_filters( 'coauthors_default_between', ', ' ),
						),
						'lastSeparator' => array(
							'type'    => 'string',
							'default' => apply_filters( 'coauthors_default_between_last', __( ' and ', 'co-authors-plus' ) ),
						),
						'suffix'        => array(
							'type'    => 'string',
							'default' => apply_filters( 'coauthors_default_after', '' ),
						),
					)
				),
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

		$post_id = array_key_exists( 'postId', $block->context ) ? absint( $block->context['postId'] ) : 0;
	
		if ( 0 === $post_id ) {
			return '';
		}

		$authors = Blocks::get_authors_with_api_schema( $post_id );

		if ( empty( $authors ) ) {
			return '';
		}

		$blocks = self::render_coauthors_blocks_with_template(
			self::get_block_as_template( $block ),
			$authors
		);

		$separators = self::get_separators(
			count( $blocks ),
			$attributes
		);

		$blocks_with_separators = self::merge_blocks_with_separators(
			$blocks,
			$separators
		);

		if ( 'default' === $attributes['layout']['type'] ) {
			array_unshift(
				$blocks_with_separators,
				self::render_prefix( $attributes['prefix'] ?? '' )
			);
			array_push(
				$blocks_with_separators,
				self::render_suffix( $attributes['suffix'] ?? '' )
			);
		}

		return current( $block->parsed_block['innerContent'] ) . implode( $blocks_with_separators ) . end( $block->parsed_block['innerContent'] );
	}

	/**
	 * Render Prefix
	 *
	 * @since 3.6.0
	 * @param string $prefix
	 * @return string
	 */
	public static function render_prefix( string $prefix ): string {
		if ( empty( $prefix ) ) {
			return $prefix;
		}
		return Templating::render_element( 'span', 'class="wp-block-co-authors-plus-coauthors__prefix"', $prefix );
	}

	/**
	 * Render Suffix
	 *
	 * @since 3.6.0
	 * @param string $suffix
	 * @return string
	 */
	public static function render_suffix( string $suffix ): string {
		if ( empty( $suffix ) ) {
			return $suffix;
		}
		return Templating::render_element( 'span', 'class="wp-block-co-authors-plus-coauthors__suffix"', $suffix );
	}

	/**
	 * Render Co-Authors Blocks with Template
	 *
	 * @since 3.6.0
	 * @param array $template
	 * @param array $authors
	 * @return array
	 */
	public static function render_coauthors_blocks_with_template( array $template, array $authors ): array {
		$render_template = self::get_template_render_function( $template );

		return array_map(
			function( array $author ) use ( $render_template ): string {
				// To match JSX from the editor, remove line-breaks between
				// blocks and trim the whitespace around each one.
				$content = trim( str_replace( "\n", '', $render_template( $author ) ) );

				return Templating::render_element(
					'div',
					'class="wp-block-co-authors-plus-coauthor"',
					$content
				);
			},
			$authors
		);
	}

	/**
	 * Merge Blocks with Separators
	 *
	 * @since 3.6.0
	 * @param array $blocks
	 * @param array $separators
	 * @return array
	 */
	private static function merge_blocks_with_separators( array $blocks, array $separators ): array {
		return array_map(
			function( ...$args ) : string {
				return implode( $args );
			},
			$blocks,
			$separators
		);
	}

	/**
	 * Get Separators
	 *
	 * @since 3.6.0
	 * @param int   $count
	 * @param array $attributes
	 */
	private static function get_separators( int $count, array $attributes ): array {
		if ( 1 === $count ) {
			return array();
		}

		if ( 'default' !== $attributes['layout']['type'] ) {
			return array();
		}

		$separator      = self::get_separator( $attributes );
		$last_separator = self::get_last_separator( $attributes, $separator );
		$separators     = array_fill( 0, $count - 1, $separator );

		if ( ! empty( $separators ) ) {
			array_splice( $separators, -1, 1, $last_separator );
		}

		return $separators;
	}

	/**
	 * Get Separator
	 *
	 * @since 3.6.0
	 * @param array $attributes
	 * @return string $separator
	 */
	private static function get_separator( array $attributes ): string {
		$separator = esc_html(
			$attributes['separator'] ?? ''
		);

		if ( '' === $separator ) {
			return $separator;
		}

		return Templating::render_element(
			'span',
			'class="wp-block-co-authors-plus-coauthors__separator"',
			$separator
		);
	}

	/**
	 * Get Last Separator
	 *
	 * @since 3.6.0
	 * @param array  $attributes
	 * @param string $default
	 */
	private static function get_last_separator( array $attributes, string $default ): string {
		$last_separator = esc_html(
			$attributes['lastSeparator'] ?? ''
		);

		if ( '' === $last_separator ) {
			return $default;
		}

		return Templating::render_element(
			'span',
			'class="wp-block-co-authors-plus-coauthors__separator"',
			$last_separator
		);
	}

	/**
	 * Get Template Render Function
	 *
	 * @since 3.6.0
	 * @param array $block_template
	 * @return callable
	 */
	private static function get_template_render_function( array $block_template ): callable {
		return function( array $author ) use ( $block_template ): string {
			return (
				new WP_Block(
					$block_template,
					array(
						'co-authors-plus/author' => $author,
						'co-authors-plus/layout' => $block_template['attrs']['layout']['type'] ?? 'default'
					)
				)
			)->render(
				array(
					'dynamic' => false,
				)
			);
		};
	}

	/**
	 * Get Block as Template
	 *
	 * @since 3.6.0
	 * @param WP_Block $block
	 * @return array
	 */
	private static function get_block_as_template( WP_Block $block ): array {
		return array_merge(
			$block->parsed_block,
			array(
				'innerContent' => array_slice( $block->parsed_block['innerContent'], 1, -1 ),
				'blockName'    => 'core/null',
			)
		);
	}
}
