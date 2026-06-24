<?php
/**
 * Unit tests for the REST controller's co-author type predicates.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Rest;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use CoAuthors\API\Endpoints\CoAuthors_Controller;

require_once dirname( __DIR__ ) . '/wp-stubs.php';

/**
 * @covers \CoAuthors\API\Endpoints\CoAuthors_Controller::is_coauthor
 * @covers \CoAuthors\API\Endpoints\CoAuthors_Controller::is_guest_author
 */
final class CoauthorPredicatesTest extends TestCase {

	/**
	 * @dataProvider provide_guest_author_cases
	 *
	 * @param object $coauthor Co-author object.
	 * @param bool   $expected Whether it should be treated as a guest author.
	 */
	public function test_is_guest_author( object $coauthor, bool $expected ): void {
		$this->assertSame( $expected, CoAuthors_Controller::is_guest_author( $coauthor ) );
	}

	public static function provide_guest_author_cases(): iterable {
		yield 'guest author'    => array( (object) array( 'type' => 'guest-author' ), true );
		yield 'wp user type'    => array( (object) array( 'type' => 'wp-user' ), false );
		yield 'no type property' => array( (object) array( 'ID' => 1 ), false );
	}

	public function test_is_coauthor_is_true_for_a_wp_user(): void {
		$this->assertTrue( CoAuthors_Controller::is_coauthor( new \WP_User() ) );
	}

	public function test_is_coauthor_is_true_for_a_guest_author(): void {
		$this->assertTrue( CoAuthors_Controller::is_coauthor( (object) array( 'type' => 'guest-author' ) ) );
	}

	public function test_is_coauthor_is_false_for_an_unrelated_object(): void {
		$this->assertFalse( CoAuthors_Controller::is_coauthor( (object) array( 'type' => 'wp-user' ) ) );
	}
}
