<?php
/**
 * Guards for the WP-CLI cache-clearing utility the commands rely on.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Cli;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;

/**
 * The long-running CLI commands flush caches between batches.
 *
 * They used to do it through a private stop_the_insanity() that reached into
 * $wp_object_cache's internals directly. They now call WP_CLI\Utils
 * \wp_clear_object_cache(), which prefers wp_cache_flush_runtime() when the
 * object cache supports it and only touches the Memcached-specific properties
 * that are actually set.
 *
 * That utility ships with WP-CLI itself, and the call sites only run under
 * WP-CLI over large batches, so nothing in the PHP suites would notice it
 * disappearing until a command fatalled on a real site. Checking the copy in
 * vendor/ is a proxy for the WP-CLI a user runs rather than proof about it,
 * but it is the cheapest standing signal that the function has not been
 * renamed or moved out from under us.
 *
 * @coversNothing
 */
final class ClearObjectCacheTest extends TestCase {

	/**
	 * Absolute path to a file in the plugin root.
	 *
	 * @param string $relative Path relative to the repository root.
	 * @return string
	 */
	private function path( string $relative ): string {
		return dirname( __DIR__, 3 ) . '/' . $relative;
	}

	/**
	 * WP-CLI still ships the utility the commands delegate to.
	 */
	public function test_wp_cli_provides_the_cache_clearing_utility(): void {
		$utils = $this->path( 'vendor/wp-cli/wp-cli/php/utils-wp.php' );

		$this->assertFileExists(
			$utils,
			'wp-cli/wp-cli must be installed so this check has something to read.'
		);

		$this->assertStringContainsString(
			'function wp_clear_object_cache()',
			(string) file_get_contents( $utils ),
			'WP_CLI\Utils\wp_clear_object_cache() has moved or been renamed. The CLI '
			. 'commands call it between batches and would fatal on a long run.'
		);
	}

	/**
	 * The plugin no longer carries its own copy of the cache flush.
	 *
	 * The hand-rolled version blanked $wp_object_cache->cache and friends
	 * unconditionally, which creates dynamic properties on any drop-in whose
	 * class does not allow them, and never reached wp_cache_flush_runtime().
	 *
	 * The commands used to share one class and now have one each, so this reads
	 * the whole command directory rather than a single file.
	 */
	public function test_the_plugin_does_not_reimplement_the_cache_flush(): void {
		$sources = glob( $this->path( 'php/cli' ) . '/class-*.php' );

		$this->assertNotEmpty( $sources, 'The command classes must be readable for this check to mean anything.' );

		$combined = '';

		foreach ( $sources as $source ) {
			$contents = (string) file_get_contents( $source );

			$this->assertStringNotContainsString( 'stop_the_insanity', $contents, basename( $source ) );
			$this->assertStringNotContainsString( 'memcache_debug', $contents, basename( $source ) );

			$combined .= $contents;
		}

		$this->assertStringContainsString(
			'\WP_CLI\Utils\wp_clear_object_cache();',
			$combined,
			'The long-running commands should still flush the object cache between batches.'
		);
	}
}
