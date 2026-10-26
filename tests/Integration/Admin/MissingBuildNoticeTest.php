<?php
/**
 * Tests for the admin notice shown when the build directory is missing.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Admin;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Plus
 */
class MissingBuildNoticeTest extends TestCase {

	/**
	 * The notice is only hooked when build/ is missing, so it holds in a
	 * checkout either way.
	 *
	 * @covers ::register_hooks
	 */
	public function test_notice_is_hooked_only_when_build_is_missing(): void {
		global $coauthors_plus;

		$build_missing = ! file_exists( dirname( COAUTHORS_PLUS_FILE ) . '/build/index.asset.php' );

		$this->assertSame( $build_missing, is_int( has_action( 'admin_notices', array( $coauthors_plus, 'missing_build_notice' ) ) ) );
	}

	/**
	 * @covers ::missing_build_notice
	 */
	public function test_notice_tells_administrators_how_to_fix_it(): void {
		global $coauthors_plus;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		$this->expectOutputRegex( '#^<div class="notice notice-error"><p>Co-Authors Plus is missing its build directory.*<a href="https://github\.com/Automattic/co-authors-plus/blob/main/docs/upgrading-to-4\.0\.md\#installing-with-composer">#' );

		$coauthors_plus->missing_build_notice();
	}

	/**
	 * @covers ::missing_build_notice
	 */
	public function test_notice_is_not_shown_to_editors(): void {
		global $coauthors_plus;

		wp_set_current_user( $this->create_editor()->ID );

		$this->expectOutputString( '' );

		$coauthors_plus->missing_build_notice();
	}
}
