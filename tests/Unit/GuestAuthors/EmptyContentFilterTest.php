<?php
/**
 * Unit tests for CoAuthors_Guest_Authors::filter_wp_insert_post_empty_content().
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use CoAuthors_Guest_Authors;
use ReflectionClass;

/**
 * @covers \CoAuthors_Guest_Authors::filter_wp_insert_post_empty_content
 */
final class EmptyContentFilterTest extends TestCase {

	/**
	 * Guest authors instance under test, built without its hook-registering constructor.
	 *
	 * @var CoAuthors_Guest_Authors
	 */
	private CoAuthors_Guest_Authors $guest_authors;

	protected function set_up(): void {
		parent::set_up();

		$this->guest_authors = ( new ReflectionClass( CoAuthors_Guest_Authors::class ) )->newInstanceWithoutConstructor();
	}

	public function test_allows_empty_content_for_guest_author_posts(): void {
		// Guest authors store their data in post meta, so an "empty" post is valid.
		$this->assertFalse(
			$this->guest_authors->filter_wp_insert_post_empty_content( true, array( 'post_type' => 'guest-author' ) )
		);
	}

	public function test_leaves_other_post_types_unchanged(): void {
		$this->assertTrue(
			$this->guest_authors->filter_wp_insert_post_empty_content( true, array( 'post_type' => 'post' ) )
		);
		$this->assertFalse(
			$this->guest_authors->filter_wp_insert_post_empty_content( false, array( 'post_type' => 'post' ) )
		);
	}
}
