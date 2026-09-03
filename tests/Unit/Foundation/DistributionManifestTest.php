<?php
/**
 * Guards for what ships to WordPress.org.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Foundation;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;

/**
 * Verifies that every tracked file at the repository root is deliberately
 * either shipped or excluded from the distribution.
 *
 * The deploy workflow hands `.distignore` to
 * `10up/action-wordpress-plugin-deploy`, which rsyncs everything else to the
 * WordPress.org SVN trunk and into the release ZIP. Nothing warns you when a
 * new root-level dev config lands without a matching exclusion, so
 * `playwright.config.js` and `eslint.config.js` both silently shipped for
 * several releases.
 *
 * `.gitattributes` mirrors the same list so that GitHub's release archives and
 * `composer install --prefer-dist` match the WordPress.org build.
 *
 * @coversNothing
 */
final class DistributionManifestTest extends TestCase {

	/**
	 * Root-level entries that are part of the distributed plugin.
	 */
	private const SHIPPED = array(
		'LICENSE',
		'README.md',
		'co-authors-plus.php',
		'css',
		'js',
		'languages',
		'php',
		'template-tags.php',
		'upgrade.php',
	);

	/**
	 * Entries `.distignore` carries that have no `.gitattributes` counterpart.
	 *
	 * `.git/` can never appear in a git archive, and `*.log` is a glob rather
	 * than a path, so neither belongs in the export-ignore list.
	 */
	private const SYNC_EXCEPTIONS = array( '/.git/', '*.log' );

	/**
	 * Every tracked root entry is either shipped or excluded.
	 */
	public function test_every_tracked_root_entry_is_shipped_or_excluded(): void {
		$excluded = array_map(
			static fn( string $rule ): string => trim( $rule, '/' ),
			$this->read_rules( '.distignore' )
		);

		$shipped = array_values(
			array_diff( $this->tracked_root_entries(), $excluded )
		);

		sort( $shipped );
		$expected = self::SHIPPED;
		sort( $expected );

		$this->assertSame(
			$expected,
			$shipped,
			'A root-level entry is neither listed in .distignore nor in this test\'s '
			. 'SHIPPED list. Add it to .distignore (and .gitattributes) if it is dev '
			. 'tooling, or to SHIPPED if it belongs in the plugin.'
		);
	}

	/**
	 * `.gitattributes` export-ignores everything `.distignore` excludes.
	 */
	public function test_gitattributes_mirrors_distignore(): void {
		$expected = array_values(
			array_diff( $this->read_rules( '.distignore' ), self::SYNC_EXCEPTIONS )
		);

		$exported = array();
		foreach ( $this->read_rules( '.gitattributes' ) as $line ) {
			if ( 'export-ignore' !== substr( $line, -13 ) ) {
				continue;
			}

			$exported[] = strtok( $line, " \t" );
		}

		sort( $expected );
		sort( $exported );

		$this->assertSame(
			$expected,
			$exported,
			'.gitattributes and .distignore have drifted apart. GitHub release '
			. 'archives would then differ from the WordPress.org build.'
		);
	}

	/**
	 * Read the significant (non-blank, non-comment) lines of a root file.
	 *
	 * @param string $filename File to read, relative to the repository root.
	 * @return string[]
	 */
	private function read_rules( string $filename ): array {
		$path = dirname( __DIR__, 3 ) . '/' . $filename;

		$this->assertFileExists( $path );

		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		return array_values(
			array_filter(
				array_map( 'trim', $lines ),
				static fn( string $line ): bool => '' !== $line && '#' !== $line[0]
			)
		);
	}

	/**
	 * The distinct top-level entries git tracks.
	 *
	 * @return string[]
	 */
	private function tracked_root_entries(): array {
		$root = dirname( __DIR__, 3 );

		$output    = array();
		$exit_code = 1;
		// Test-only: asking git for the tracked file list is the whole point, and
		// CI always checks the repository out with git available.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( 'git -C ' . escapeshellarg( $root ) . ' ls-files 2>/dev/null', $output, $exit_code );

		if ( 0 !== $exit_code || array() === $output ) {
			$this->markTestSkipped( 'git is unavailable or this is not a checkout.' );
		}

		return array_values(
			array_unique(
				array_map(
					static fn( string $path ): string => strtok( $path, '/' ),
					$output
				)
			)
		);
	}
}
