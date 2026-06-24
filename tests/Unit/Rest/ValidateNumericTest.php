<?php
/**
 * Unit tests for the REST search endpoint's numeric argument validator.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Rest;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use CoAuthors\API\Endpoints;
use ReflectionClass;

/**
 * @covers \CoAuthors\API\Endpoints::validate_numeric
 */
final class ValidateNumericTest extends TestCase {

	/**
	 * @dataProvider provide_values
	 *
	 * @param mixed $value    Value passed to the validator.
	 * @param bool  $expected Expected result.
	 */
	public function test_validate_numeric( $value, bool $expected ): void {
		// validate_numeric() does not use instance state, and the constructor
		// registers REST routes against WordPress, so build the object without it.
		$endpoint = ( new ReflectionClass( Endpoints::class ) )->newInstanceWithoutConstructor();

		$this->assertSame( $expected, $endpoint->validate_numeric( $value ) );
	}

	public static function provide_values(): iterable {
		yield 'integer'        => array( 123, true );
		yield 'numeric string' => array( '123', true );
		yield 'float string'   => array( '12.5', true );
		yield 'zero integer'   => array( 0, true );
		yield 'zero string'    => array( '0', true );
		yield 'alpha string'   => array( 'abc', false );
		yield 'empty string'   => array( '', false );
		yield 'null'           => array( null, false );
		yield 'mixed string'   => array( 'a1', false );
	}
}
