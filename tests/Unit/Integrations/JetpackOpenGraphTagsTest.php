<?php
/**
 * Unit tests for CoAuthors_Plus::filter_jetpack_open_graph_tags().
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Integrations;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use CoAuthors_Plus;

/**
 * Unit coverage for Jetpack Open Graph Tags integration.
 *
 * Regression coverage for issue #1023:
 * Jetpack Social and companion integrations invoke `apply_filters( 'jetpack_open_graph_tags', $tags )`
 * with only 1 argument. The callback must define the second parameter ($image_dimensions)
 * as optional with a default array value to prevent a PHP fatal ArgumentCountError.
 *
 * @covers \CoAuthors_Plus::filter_jetpack_open_graph_tags
 */
final class JetpackOpenGraphTagsTest extends TestCase {

	/**
	 * An instance of CoAuthors_Plus under test.
	 *
	 * @var CoAuthors_Plus
	 */
	private $coauthors_plus;

	/**
	 * Set up test environment before each test.
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->coauthors_plus = new CoAuthors_Plus();
	}

	/**
	 * Verify that filter_jetpack_open_graph_tags accepts a single argument without throwing ArgumentCountError.
	 *
	 * Regression test for issue #1023.
	 */
	public function test_filter_jetpack_open_graph_tags_accepts_single_argument_without_error(): void {
		Functions\when( 'is_author' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );

		$tags = array(
			'og:type'  => 'website',
			'og:title' => 'Home Page',
		);

		$result = $this->coauthors_plus->filter_jetpack_open_graph_tags( $tags );

		$this->assertSame( $tags, $result );
	}

	/**
	 * Verify that filter_jetpack_open_graph_tags accepts two arguments as passed by core Jetpack.
	 */
	public function test_filter_jetpack_open_graph_tags_accepts_two_arguments(): void {
		Functions\when( 'is_author' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );

		$tags = array(
			'og:type'  => 'website',
			'og:title' => 'Home Page',
		);

		$dimensions = array(
			'width'  => 1200,
			'height' => 630,
		);

		$result = $this->coauthors_plus->filter_jetpack_open_graph_tags( $tags, $dimensions );

		$this->assertSame( $tags, $result );
	}
}
