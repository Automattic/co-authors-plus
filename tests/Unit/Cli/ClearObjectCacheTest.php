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
	 * Commands and services that process large result sets in batches and must
	 * flush the object cache between batches.
	 *
	 * When adding code that loops over a large result set, add its file here so
	 * the flush cannot be dropped unnoticed.
	 */
	private const BATCHING_FILES = array(
		'php/cli/class-assign-coauthors-command.php',
		'php/cli/class-create-terms-for-posts-command.php',
		'php/cli/class-list-posts-without-terms-command.php',
		'php/cli/class-swap-coauthors-command.php',
		'php/cli/class-update-author-terms-command.php',
		'php/services/class-coauthor-checks-service.php',
	);

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

		foreach ( $sources as $source ) {
			$contents = (string) file_get_contents( $source );

			$this->assertStringNotContainsString( 'stop_the_insanity', $contents, basename( $source ) );
			$this->assertStringNotContainsString( 'memcache_debug', $contents, basename( $source ) );
		}
	}

	/**
	 * Every batching command and service still flushes the object cache between
	 * batches.
	 *
	 * Checked per file, not across the directory as a whole, so one file
	 * dropping its flush cannot hide behind another that kept it.
	 */
	public function test_each_batching_file_flushes_the_object_cache(): void {
		foreach ( self::BATCHING_FILES as $file ) {
			$source = $this->path( $file );

			$this->assertFileExists( $source, "$file is listed as a batching file but does not exist. Update BATCHING_FILES." );

			$this->assertStringContainsString(
				'\WP_CLI\Utils\wp_clear_object_cache();',
				(string) file_get_contents( $source ),
				"$file no longer flushes the object cache between batches. A long run would exhaust memory on a real site."
			);
		}
	}
}
