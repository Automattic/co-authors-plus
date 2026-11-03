<?php
/**
 * Minimal stubs for Yoast SEO classes used by the integration unit tests.
 *
 * The unit suite runs without Yoast SEO installed. filter_graph()
 * instantiates Yoast's Schema_Types service and the plugin's CoAuthor generator,
 * which extends Yoast's Author generator, so plain guarded stubs let the method
 * under test construct them. The class_exists() guards mean a real Yoast
 * autoloader always wins over these stubs, and unlike a Mockery "overload:"
 * mock the definitions are not restricted to a single test per process.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Yoast\WP\SEO\Config;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- Deliberate stubs for Yoast SEO classes.
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

if ( ! class_exists( Schema_IDs::class ) ) {
	/**
	 * Stand-in for Yoast's Schema_IDs constants.
	 */
	class Schema_IDs {

		/**
		 * Hash the guest author Person @id is built from.
		 *
		 * @var string
		 */
		const PERSON_LOGO_HASH = '#/schema/person/image/';
	}
}

namespace Yoast\WP\SEO\Generators\Schema;

if ( ! class_exists( Author::class ) ) {
	/**
	 * Stand-in for Yoast's Author schema generator, the CoAuthor parent class.
	 *
	 * Provides the properties filter_graph() sets and a minimal Person node.
	 */
	class Author {

		/**
		 * The meta tags context.
		 *
		 * @var object
		 */
		public $context;

		/**
		 * The Yoast helpers surface.
		 *
		 * @var object
		 */
		public $helpers;

		/**
		 * The Schema type, as set by Yoast's Person generator.
		 *
		 * @var string[]
		 */
		protected $type = array( 'Person', 'Organization' );

		/**
		 * Build a minimal Person node for a user.
		 *
		 * @param int  $user_id  The user ID.
		 * @param bool $add_hash Whether the person's image url hash should be added to the image id.
		 * @return array
		 */
		protected function build_person_data( $user_id, $add_hash = false ) {
			return array(
				'@type' => 'Person',
				'@id'   => 'https://example.com/#/schema/person/' . $user_id,
			);
		}

		/**
		 * Leave the Person node without an image.
		 *
		 * @param array  $data      The Person schema data.
		 * @param object $user_data The user data object.
		 * @param string $schema_id The schema ID base.
		 * @param bool   $add_hash  Whether the image url hash should be added to the image id.
		 * @return array
		 */
		protected function set_image_from_avatar( $data, $user_data, $schema_id, $add_hash = false ) {
			return $data;
		}
	}
}
// phpcs:enable
