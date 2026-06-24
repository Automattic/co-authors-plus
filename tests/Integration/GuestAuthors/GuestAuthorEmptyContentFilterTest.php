<?php
/**
 * Integration tests for the guest-author empty-content filter and the
 * wp_insert_post() flow that depends on it.
 *
 * The unit-level filter test lives in
 * tests/Unit/GuestAuthors/EmptyContentFilterTest.php; this class has a distinct
 * name to avoid a class collision and exercises the live guest authors object.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Guest_Authors
 */
class GuestAuthorEmptyContentFilterTest extends TestCase {

	/**
	 * Checks that the empty content filter does not interfere with non-guest-author post types.
	 *
	 * @covers CoAuthors_Guest_Authors::filter_wp_insert_post_empty_content()
	 */
	public function test_filter_wp_insert_post_empty_content_passes_through_for_other_post_types(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$result = $guest_author_obj->filter_wp_insert_post_empty_content(
			true,
			array( 'post_type' => 'post' )
		);

		$this->assertTrue( $result, 'Filter should pass through $maybe_empty for non-guest-author post types.' );

		$result = $guest_author_obj->filter_wp_insert_post_empty_content(
			false,
			array( 'post_type' => 'post' )
		);

		$this->assertFalse( $result, 'Filter should pass through $maybe_empty for non-guest-author post types.' );
	}

	/**
	 * Checks that the empty content filter allows guest author posts with empty content.
	 *
	 * Guest author posts store data in post meta rather than post_content, so
	 * the filter must not block insertion even when title/content are empty.
	 *
	 * @covers CoAuthors_Guest_Authors::filter_wp_insert_post_empty_content()
	 */
	public function test_filter_wp_insert_post_empty_content_allows_guest_author_posts(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Even when core considers the post "empty", the filter should allow it.
		$result = $guest_author_obj->filter_wp_insert_post_empty_content(
			true,
			array(
				'post_type'  => $guest_author_obj->post_type,
				'post_title' => '',
			)
		);

		$this->assertFalse( $result, 'Filter should return false for guest author posts to allow empty content.' );
	}

	/**
	 * Checks that guest author posts with a display name also pass the empty content filter.
	 *
	 * @covers CoAuthors_Guest_Authors::filter_wp_insert_post_empty_content()
	 */
	public function test_filter_wp_insert_post_empty_content_allows_guest_author_posts_with_title(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$result = $guest_author_obj->filter_wp_insert_post_empty_content(
			true,
			array(
				'post_type'  => $guest_author_obj->post_type,
				'post_title' => 'Test Author',
			)
		);

		$this->assertFalse( $result, 'Filter should return false for guest author posts regardless of title.' );
	}

	/**
	 * Checks that wp_insert_post() succeeds for the guest author post type
	 * when title and content are empty, simulating an auto-draft scenario.
	 *
	 * This is the scenario that caused failures: the block editor creates an
	 * auto-draft with empty title/content, and the empty content filter must
	 * not block it.
	 *
	 * @covers CoAuthors_Guest_Authors::filter_wp_insert_post_empty_content()
	 */
	public function test_wp_insert_post_succeeds_for_guest_author_auto_draft(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$post_id = wp_insert_post(
			array(
				'post_type'    => $guest_author_obj->post_type,
				'post_title'   => '',
				'post_content' => '',
				'post_status'  => 'auto-draft',
			),
			true
		);

		$this->assertNotWPError( $post_id, 'wp_insert_post should succeed for guest author auto-drafts with empty content.' );
		$this->assertGreaterThan( 0, $post_id );

		wp_delete_post( $post_id, true );
	}
}
