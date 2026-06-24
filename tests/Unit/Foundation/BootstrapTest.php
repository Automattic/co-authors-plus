<?php
/**
 * Smoke tests for the unit-suite bootstrap.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Foundation;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Verifies the unit suite boots without WordPress and that Brain Monkey is wired up.
 *
 * Acts as the guard for the foundation: if WordPress ever leaks into the unit
 * suite, or Brain Monkey stops working, these fail.
 *
 * @coversNothing
 */
final class BootstrapTest extends TestCase {

	/**
	 * The unit suite must not boot WordPress.
	 */
	public function test_wordpress_is_not_loaded(): void {
		$this->assertFalse(
			function_exists( 'wp_insert_post' ),
			'The unit suite must run without WordPress loaded.'
		);
	}

	/**
	 * Brain Monkey can stub WordPress functions for the suite.
	 */
	public function test_brain_monkey_can_stub_wordpress_functions(): void {
		Functions\when( 'esc_html' )->returnArg();

		$this->assertSame( 'Byline & co', esc_html( 'Byline & co' ) );
	}
}
