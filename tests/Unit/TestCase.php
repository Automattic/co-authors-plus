<?php
/**
 * Base test case for the WordPress-free unit suite.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit;

use Yoast\WPTestUtils\BrainMonkey\TestCase as BrainMonkeyTestCase;

/**
 * Base test case for unit tests.
 *
 * Extends the Yoast Brain Monkey test case, which boots Brain Monkey in its
 * snake_case `set_up()` fixture. Subclasses that need their own fixtures MUST
 * override the snake_case `set_up()` / `tear_down()` methods (calling the
 * parent), never the camelCase `setUp()` / `tearDown()`, or Brain Monkey
 * initialisation will be bypassed.
 */
abstract class TestCase extends BrainMonkeyTestCase {
}
