<?php
/**
 * Guards that the commands use the configured co-author taxonomy.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Cli;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;

/**
 * The taxonomy holding co-author terms is a public property, not a constant.
 *
 * The remove-terms-from-revisions command used to read its terms through the configured
 * taxonomy and then clear the hardcoded 'author' one. On a site that had
 * changed the property the command logged a removal for every revision it
 * found and reported how many it had cleared, having cleared none.
 *
 * A live test would have to register a taxonomy under another name before
 * init, which is more machinery than the risk deserves. Reading the source is
 * enough to catch the name being written in again, and follows the same
 * approach as the cache-flush guard alongside it.
 *
 * @coversNothing
 */
final class CoauthorTaxonomyUsageTest extends TestCase {

	/**
	 * Command classes must not name the taxonomy directly.
	 */
	public function test_no_command_hardcodes_the_taxonomy_name(): void {
		$sources = glob( dirname( __DIR__, 3 ) . '/php/cli/class-*.php' );

		$this->assertNotEmpty( $sources, 'The command classes must be readable for this check to mean anything.' );

		foreach ( $sources as $source ) {
			$code = (string) file_get_contents( $source );

			// Strip comments and docblocks, which discuss the taxonomy by name.
			$code = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $code );

			$this->assertStringNotContainsString(
				"'author'",
				$code,
				sprintf(
					'%s names the co-author taxonomy directly. Use $coauthors_plus->coauthor_taxonomy, '
					. 'which is a public property a site can change.',
					basename( $source )
				)
			);
		}
	}
}
