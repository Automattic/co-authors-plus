<?php
/**
 * Minimal stubs for Yoast SEO classes used by the integration unit tests.
 *
 * The unit suite runs without Yoast SEO installed. filter_graph()
 * instantiates Yoast's Schema_Types service, so a plain guarded stub lets the
 * method under test construct it. The class_exists() guard means a real Yoast
 * autoloader always wins over this stub, and unlike a Mockery "overload:"
 * mock the definition is not restricted to a single test per process.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Yoast\WP\SEO\Config;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Deliberate stub for a Yoast SEO class.
if ( ! class_exists( Schema_Types::class ) ) {
	/**
	 * Stand-in for Yoast's Schema_Types service.
	 *
	 * Provides the single article type option the tests assert against.
	 */
	class Schema_Types {

		/**
		 * List the article type options that filter_graph() matches against.
		 *
		 * The stub returns the single article type the tests assert against.
		 *
		 * @return array
		 */
		public function get_article_type_options(): array {
			return array(
				array(
					'value' => 'Article',
				),
			);
		}
	}
}
// phpcs:enable
