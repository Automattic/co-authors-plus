<?php
/**
 * Tests for the WordPress Importer integration.
 *
 * @package CoAuthors
 */

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use Automattic\CoAuthorsPlus\Integrations\WordPress_Importer;

/**
 * Tests for the WordPress Importer integration class.
 *
 * @covers \CoAuthors\Integrations\WordPress_Importer
 */
class WordPressImporterTest extends TestCase {

	/**
	 * The WordPress Importer instance.
	 *
	 * @var WordPress_Importer
	 */
	private WordPress_Importer $importer;

	/**
	 * Set up the test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->importer = new WordPress_Importer();
	}

	/**
	 * Test that filter does not modify behavior for non-guest-author posts.
	 *
	 * @covers \CoAuthors\Integrations\WordPress_Importer::check_existing_guest_author
	 */
	public function test_filter_ignores_non_guest_author_posts(): void {
		// Create a regular post.
		$post = array(
			'post_type'  => 'post',
			'post_title' => 'Test Regular Post',
		);

		// Filter should return the original value unchanged.
		$result = $this->importer->check_existing_guest_author( 0, $post );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test that filter respects existing match from WordPress.
	 *
	 * @covers \CoAuthors\Integrations\WordPress_Importer::check_existing_guest_author
	 */
	public function test_filter_respects_existing_match(): void {
		$existing_id = 123;

		$post = array(
			'post_type'  => 'guest-author',
			'post_title' => 'Test Guest Author',
		);

		// If WordPress already found a match, it should be returned.
		$result = $this->importer->check_existing_guest_author( $existing_id, $post );

		$this->assertSame( $existing_id, $result );
	}

	/**
	 * Test that filter finds existing guest-author by title.
	 *
	 * @covers \CoAuthors\Integrations\WordPress_Importer::check_existing_guest_author
	 */
	public function test_filter_finds_existing_guest_author_by_title(): void {
		global $coauthors_plus;

		// Create a guest author.
		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Existing Guest Author',
				'user_login'   => 'existing-guest-author',
			)
		);

		$this->assertIsInt( $guest_author_id );

		// Get the guest author post to find its title.
		$guest_author_post = get_post( $guest_author_id );
		$this->assertInstanceOf( \WP_Post::class, $guest_author_post );

		// Simulate importing the same guest author with a different date.
		$post = array(
			'post_type'  => 'guest-author',
			'post_title' => $guest_author_post->post_title,
			'post_date'  => '2020-01-01 00:00:00', // Different date.
		);

		// Filter should find the existing guest author.
		$result = $this->importer->check_existing_guest_author( 0, $post );

		$this->assertSame( $guest_author_id, $result );
	}

	/**
	 * Test that filter returns 0 when guest-author does not exist.
	 *
	 * @covers \CoAuthors\Integrations\WordPress_Importer::check_existing_guest_author
	 */
	public function test_filter_returns_zero_for_new_guest_author(): void {
		$post = array(
			'post_type'  => 'guest-author',
			'post_title' => 'Brand New Guest Author That Does Not Exist ' . wp_generate_uuid4(),
		);

		$result = $this->importer->check_existing_guest_author( 0, $post );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test that filter handles missing post_type gracefully.
	 *
	 * @covers \CoAuthors\Integrations\WordPress_Importer::check_existing_guest_author
	 */
	public function test_filter_handles_missing_post_type(): void {
		$post = array(
			'post_title' => 'Test Post Without Type',
		);

		$result = $this->importer->check_existing_guest_author( 0, $post );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test that filter handles missing post_title gracefully.
	 *
	 * @covers \CoAuthors\Integrations\WordPress_Importer::check_existing_guest_author
	 */
	public function test_filter_handles_missing_post_title(): void {
		$post = array(
			'post_type' => 'guest-author',
		);

		$result = $this->importer->check_existing_guest_author( 0, $post );

		$this->assertSame( 0, $result );
	}
}
