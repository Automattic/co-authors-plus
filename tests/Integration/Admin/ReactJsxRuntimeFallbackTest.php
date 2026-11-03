<?php
/**
 * Tests for the react-jsx-runtime fallback on WordPress versions without it.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Admin;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_Scripts;

/**
 * @covers \CoAuthors_Plus::register_react_jsx_runtime_fallback
 */
class ReactJsxRuntimeFallbackTest extends TestCase {

	/**
	 * @var WP_Scripts|null
	 */
	private $original_scripts;

	public function set_up() {
		parent::set_up();

		$this->original_scripts = $GLOBALS['wp_scripts'] ?? null;
		$GLOBALS['wp_scripts']  = new WP_Scripts();
	}

	public function tear_down() {
		$GLOBALS['wp_scripts'] = $this->original_scripts;

		parent::tear_down();
	}

	public function test_registers_the_runtime_when_core_does_not(): void {
		global $coauthors_plus;

		wp_deregister_script( 'react-jsx-runtime' );

		$coauthors_plus->register_react_jsx_runtime_fallback();

		$script = wp_scripts()->query( 'react-jsx-runtime' );

		$this->assertNotFalse( $script );
		$this->assertSame( array( 'react' ), $script->deps );
		$this->assertStringContainsString( 'window.ReactJSXRuntime', implode( '', $script->extra['after'] ) );
	}

	public function test_keeps_a_runtime_that_is_already_registered(): void {
		global $coauthors_plus;

		wp_deregister_script( 'react-jsx-runtime' );
		wp_register_script( 'react-jsx-runtime', 'https://example.org/react-jsx-runtime.js', array(), '1.0.0', true );

		$coauthors_plus->register_react_jsx_runtime_fallback();

		$script = wp_scripts()->query( 'react-jsx-runtime' );

		$this->assertSame( 'https://example.org/react-jsx-runtime.js', $script->src );
		$this->assertArrayNotHasKey( 'after', $script->extra );
	}

	public function test_runs_before_blocks_and_scripts_are_registered(): void {
		global $coauthors_plus;

		$this->assertSame( 0, has_action( 'init', array( $coauthors_plus, 'register_react_jsx_runtime_fallback' ) ) );
	}
}
