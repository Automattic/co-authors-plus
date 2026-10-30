<?php
/**
 * Unit tests for CoAuthorsIterator.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \CoAuthorsIterator
 */
final class CoAuthorsIteratorTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();

		global $post, $authordata;
		$post       = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$authordata = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	protected function tear_down(): void {
		global $post, $authordata;
		$post       = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$authordata = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		parent::tear_down();
	}

	public function test_construct_with_explicit_post_id(): void {
		$author1 = (object) array(
			'ID'           => 1,
			'display_name' => 'Alice Author',
		);
		$author2 = (object) array(
			'ID'           => 2,
			'display_name' => 'Bob Writer',
		);

		Functions\expect( 'get_coauthors' )
			->once()
			->with( 42 )
			->andReturn( array( $author1, $author2 ) );

		$iterator = new \CoAuthorsIterator( 42 );

		$this->assertSame( 2, $iterator->count );
		$this->assertSame( 2, $iterator->count() );
		$this->assertSame( -1, $iterator->position );
		$this->assertFalse( $iterator->get_position() );
		$this->assertSame( array( $author1, $author2 ), $iterator->authordata_array );
		$this->assertSame( array( $author1, $author2 ), $iterator->get_all() );
		$this->assertNull( $iterator->original_authordata );
		$this->assertNull( $iterator->current_author );
	}

	public function test_construct_falls_back_to_global_post_id(): void {
		global $post;
		$post = (object) array( 'ID' => 99 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$author = (object) array(
			'ID'           => 10,
			'display_name' => 'Global Author',
		);

		Functions\expect( 'get_coauthors' )
			->once()
			->with( 99 )
			->andReturn( array( $author ) );

		$iterator = new \CoAuthorsIterator();

		$this->assertSame( 1, $iterator->count() );
		$this->assertSame( array( $author ), $iterator->get_all() );
	}

	public function test_construct_triggers_error_when_no_post_id_and_no_global_post(): void {
		Functions\expect( 'wp_trigger_error' )
			->once()
			->with(
				'CoAuthorsIterator::__construct',
				'No post ID provided for CoAuthorsIterator constructor. Are you not in a loop or is $post not set?'
			);

		Functions\expect( 'get_coauthors' )
			->once()
			->with( 0 )
			->andReturn( array() );

		$iterator = new \CoAuthorsIterator( 0 );

		$this->assertSame( 0, $iterator->count() );
		$this->assertSame( array(), $iterator->get_all() );
	}

	public function test_iterate_with_empty_authors_array_immediately_returns_false(): void {
		Functions\expect( 'get_coauthors' )
			->once()
			->with( 404 )
			->andReturn( array() );

		$iterator = new \CoAuthorsIterator( 404 );

		$this->assertFalse( $iterator->iterate() );
		$this->assertSame( -1, $iterator->position );
		$this->assertFalse( $iterator->get_position() );
	}

	public function test_iteration_lifecycle_single_author(): void {
		global $authordata;

		$author = (object) array(
			'ID'           => 10,
			'display_name' => 'Solo Writer',
		);

		Functions\expect( 'get_coauthors' )
			->once()
			->with( 505 )
			->andReturn( array( $author ) );

		$iterator = new \CoAuthorsIterator( 505 );

		$this->assertFalse( $iterator->is_first() );
		$this->assertFalse( $iterator->is_last() );

		// Step 1: iterate over the only author.
		$this->assertTrue( $iterator->iterate() );
		$this->assertSame( 0, $iterator->get_position() );
		$this->assertTrue( $iterator->is_first() );
		$this->assertTrue( $iterator->is_last() );
		$this->assertSame( $author, $iterator->current_author );
		$this->assertSame( $author, $authordata );

		// Step 2: attempt to iterate past the end.
		$this->assertFalse( $iterator->iterate() );
		$this->assertSame( -1, $iterator->position );
		$this->assertFalse( $iterator->get_position() );
		$this->assertNull( $iterator->current_author );
		$this->assertNull( $authordata );
	}

	public function test_iteration_lifecycle_multiple_authors(): void {
		global $authordata;

		$author1 = (object) array(
			'ID'           => 1,
			'display_name' => 'First Author',
		);
		$author2 = (object) array(
			'ID'           => 2,
			'display_name' => 'Middle Author',
		);
		$author3 = (object) array(
			'ID'           => 3,
			'display_name' => 'Last Author',
		);

		Functions\expect( 'get_coauthors' )
			->once()
			->with( 606 )
			->andReturn( array( $author1, $author2, $author3 ) );

		$iterator = new \CoAuthorsIterator( 606 );

		// Iteration 1.
		$this->assertTrue( $iterator->iterate() );
		$this->assertSame( 0, $iterator->get_position() );
		$this->assertTrue( $iterator->is_first() );
		$this->assertFalse( $iterator->is_last() );
		$this->assertSame( $author1, $iterator->current_author );
		$this->assertSame( $author1, $authordata );

		// Iteration 2.
		$this->assertTrue( $iterator->iterate() );
		$this->assertSame( 1, $iterator->get_position() );
		$this->assertFalse( $iterator->is_first() );
		$this->assertFalse( $iterator->is_last() );
		$this->assertSame( $author2, $iterator->current_author );
		$this->assertSame( $author2, $authordata );

		// Iteration 3.
		$this->assertTrue( $iterator->iterate() );
		$this->assertSame( 2, $iterator->get_position() );
		$this->assertFalse( $iterator->is_first() );
		$this->assertTrue( $iterator->is_last() );
		$this->assertSame( $author3, $iterator->current_author );
		$this->assertSame( $author3, $authordata );

		// Iteration 4: loop terminates and resets.
		$this->assertFalse( $iterator->iterate() );
		$this->assertSame( -1, $iterator->position );
		$this->assertFalse( $iterator->get_position() );
		$this->assertNull( $iterator->current_author );
		$this->assertNull( $authordata );
	}

	public function test_iteration_restores_preexisting_global_authordata(): void {
		global $authordata;

		$original_author = (object) array(
			'ID'           => 999,
			'display_name' => 'Original Surrounding Author',
		);
		$authordata      = $original_author; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$post_author = (object) array(
			'ID'           => 1,
			'display_name' => 'Post Coauthor',
		);

		Functions\expect( 'get_coauthors' )
			->once()
			->with( 707 )
			->andReturn( array( $post_author ) );

		$iterator = new \CoAuthorsIterator( 707 );

		// Before loop: original authordata is remembered from constructor.
		$this->assertSame( $original_author, $iterator->original_authordata );
		$this->assertSame( $original_author, $iterator->current_author );

		// Inside loop: global authordata is replaced with the co-author.
		$this->assertTrue( $iterator->iterate() );
		$this->assertSame( $post_author, $authordata );
		$this->assertSame( $post_author, $iterator->current_author );

		// After loop: global authordata is restored to the original.
		$this->assertFalse( $iterator->iterate() );
		$this->assertSame( $original_author, $authordata );
		$this->assertSame( $original_author, $iterator->current_author );
	}

	public function test_iteration_captures_authordata_set_before_first_iterate(): void {
		global $authordata;

		$original_author = (object) array(
			'ID'           => 999,
			'display_name' => 'Original Surrounding Author',
		);

		$post_author = (object) array(
			'ID'           => 1,
			'display_name' => 'Post Coauthor',
		);

		Functions\expect( 'get_coauthors' )
			->once()
			->with( 708 )
			->andReturn( array( $post_author ) );

		// Construct while $authordata is null so the constructor does not capture it.
		$iterator = new \CoAuthorsIterator( 708 );
		$this->assertNull( $iterator->original_authordata );

		// Set $authordata after construction but before the first iterate() call.
		$authordata = $original_author; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// First iterate() at position 0 re-captures original_authordata.
		$this->assertTrue( $iterator->iterate() );
		$this->assertSame( $post_author, $authordata );
		$this->assertSame( $original_author, $iterator->original_authordata );

		// After loop: global authordata is restored to the author captured at position 0.
		$this->assertFalse( $iterator->iterate() );
		$this->assertSame( $original_author, $authordata );
		$this->assertSame( $original_author, $iterator->current_author );
	}

	public function test_iterator_can_be_looped_multiple_times(): void {
		$author1 = (object) array(
			'ID'           => 1,
			'display_name' => 'First Author',
		);
		$author2 = (object) array(
			'ID'           => 2,
			'display_name' => 'Second Author',
		);

		Functions\expect( 'get_coauthors' )
			->once()
			->with( 808 )
			->andReturn( array( $author1, $author2 ) );

		$iterator = new \CoAuthorsIterator( 808 );

		// First pass through the loop.
		$this->assertTrue( $iterator->iterate() );
		$this->assertSame( 0, $iterator->get_position() );
		$this->assertSame( $author1, $iterator->current_author );

		$this->assertTrue( $iterator->iterate() );
		$this->assertSame( 1, $iterator->get_position() );
		$this->assertSame( $author2, $iterator->current_author );

		$this->assertFalse( $iterator->iterate() );
		$this->assertSame( -1, $iterator->position );
		$this->assertFalse( $iterator->get_position() );

		// Second pass through the loop on the same instance.
		$this->assertTrue( $iterator->iterate() );
		$this->assertSame( 0, $iterator->get_position() );
		$this->assertSame( $author1, $iterator->current_author );

		$this->assertTrue( $iterator->iterate() );
		$this->assertSame( 1, $iterator->get_position() );
		$this->assertSame( $author2, $iterator->current_author );

		$this->assertFalse( $iterator->iterate() );
		$this->assertSame( -1, $iterator->position );
		$this->assertFalse( $iterator->get_position() );
	}
}
