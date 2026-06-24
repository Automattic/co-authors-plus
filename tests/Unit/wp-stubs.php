<?php
/**
 * Minimal stubs for WordPress classes referenced by the unit suite.
 *
 * The unit suite runs without WordPress. A few plugin classes extend or
 * reference WordPress core classes at declaration time (for example, the REST
 * controller extends WP_REST_Controller). Defining empty stubs lets those
 * classes load so their WordPress-independent logic can be unit tested.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Deliberate stubs for WordPress core classes.
if ( ! class_exists( 'WP_REST_Controller' ) ) {
	class WP_REST_Controller {}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {}
}
// phpcs:enable
