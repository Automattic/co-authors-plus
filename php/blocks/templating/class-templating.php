<?php
/**
 * Templating
 * 
 * @package Automattic\CoAuthorsPlus
 * @since 3.6.0
 */

namespace CoAuthors\Blocks;

/**
 * Templating
 * 
 * @package CoAuthors
 */
class Templating {

	/**
	 * Render Element
	 *
	 * @param string      $name HTML element tag name.
	 * @param string|null $attributes HTML attributes.
	 * @param string|null $content Inner HTML content.
	 * @since 3.6.0
	 */
	public static function render_element( string $name, ?string $attributes = '', ?string $content = '' ): string {
		return "<{$name} $attributes>{$content}</{$name}>";
	}

	/**
	 * Render Self Closing Element
	 *
	 * @since 3.6.0
	 * @param string      $name
	 * @param null|string $attributes
	 * @return string
	 */
	public static function render_self_closing_element( string $name, ?string $attributes = '' ): string {
		return "<{$name} $attributes/>";
	}

	/**
	 * Render Attribute String
	 *
	 * @since 3.6.0
	 * @param string|int $key Attribute key.
	 * @param mixed      $value Attribute value. For boolean attributes, set value the same as the key.
	 * @return string
	 */
	public static function render_attribute_string( $key, $value ): string {
		if ( empty( $value ) ) {
			return '';
		}
		if ( $key === $value ) {
			return $key;
		}
		return sprintf( '%s="%s"', $key, esc_attr( $value ) );
	}

	/**
	 * Render Attributes
	 *
	 * @since 3.6.0
	 * @param array $attributes An associative array of attributes and their values.
	 */
	public static function render_attributes( array $attributes ): string {
		
		$attribute_strings = array_map(
			array( __CLASS__, 'render_attribute_string' ),
			array_keys( $attributes ),
			array_values( $attributes )
		);

		$validated_attribute_strings = array_values(
			array_filter(
				$attribute_strings,
				function( $value ) {
					return is_string( $value ) && '' !== $value;
				}
			)
		);

		return implode( ' ', $validated_attribute_strings );
	}
}
