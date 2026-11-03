<?php
/**
 * Unit tests for CoAuthors_Plus::filter_oembed_response_data().
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Embeds;

require_once dirname( __DIR__ ) . '/wp-stubs.php';

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use CoAuthors_Plus;
use WP_Post;

/**
 * Unit coverage for oEmbed response data filtering.
 *
 * @covers \CoAuthors_Plus::filter_oembed_response_data
 */
final class OembedResponseDataTest extends TestCase {

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

		$this->coauthors_plus                       = new CoAuthors_Plus();
		$this->coauthors_plus->supported_post_types = array( 'post' );
	}

	/**
	 * Verify that non-array data is returned unchanged.
	 */
	public function test_filter_returns_data_unchanged_when_data_is_not_an_array(): void {
		$post = new WP_Post(
			(object) array(
				'ID'        => 123,
				'post_type' => 'post',
			)
		);

		$this->assertFalse( $this->coauthors_plus->filter_oembed_response_data( false, $post ) );
	}

	/**
	 * Verify that data is returned unchanged when the post is not a WP_Post instance.
	 */
	public function test_filter_returns_data_unchanged_when_post_is_not_wp_post(): void {
		$data = array( 'author_name' => 'Original Author' );

		$this->assertSame( $data, $this->coauthors_plus->filter_oembed_response_data( $data, null ) );
	}

	/**
	 * Verify that data is returned unchanged when the post type is not supported.
	 */
	public function test_filter_returns_data_unchanged_when_post_type_is_not_supported(): void {
		$post = new WP_Post(
			(object) array(
				'ID'        => 123,
				'post_type' => 'page',
			)
		);
		$data = array( 'author_name' => 'Original Author' );

		$this->assertSame( $data, $this->coauthors_plus->filter_oembed_response_data( $data, $post ) );
	}

	/**
	 * Verify that data is returned unchanged when no co-authors are assigned.
	 */
	public function test_filter_returns_data_unchanged_when_no_coauthors_assigned(): void {
		$post = new WP_Post(
			(object) array(
				'ID'        => 123,
				'post_type' => 'post',
			)
		);
		$data = array( 'author_name' => 'Original Author' );

		Functions\when( 'get_coauthors' )->justReturn( array() );

		$this->assertSame( $data, $this->coauthors_plus->filter_oembed_response_data( $data, $post ) );
	}

	/**
	 * Verify that data is returned unchanged when the first co-author has an empty display name.
	 */
	public function test_filter_returns_data_unchanged_when_first_coauthor_has_empty_display_name(): void {
		$post = new WP_Post(
			(object) array(
				'ID'        => 123,
				'post_type' => 'post',
			)
		);
		$data = array( 'author_name' => 'Original Author' );

		$author = (object) array(
			'ID'           => 456,
			'display_name' => '',
		);

		Functions\when( 'get_coauthors' )->justReturn( array( $author ) );

		$this->assertSame( $data, $this->coauthors_plus->filter_oembed_response_data( $data, $post ) );
	}

	/**
	 * Verify that the first co-author's display name and URL are applied to the oEmbed response data.
	 */
	public function test_filter_sets_author_name_and_author_url_from_first_coauthor(): void {
		$post = new WP_Post(
			(object) array(
				'ID'        => 123,
				'post_type' => 'post',
			)
		);
		$data = array(
			'version'     => '1.0',
			'author_name' => 'Core Publishing Author',
			'author_url'  => 'https://example.com/author/publishing-author',
		);

		$coauthor1 = (object) array(
			'ID'            => 456,
			'user_nicename' => 'first-coauthor',
			'display_name'  => 'First Coauthor',
		);
		$coauthor2 = (object) array(
			'ID'            => 789,
			'user_nicename' => 'second-coauthor',
			'display_name'  => 'Second Coauthor',
		);

		Functions\when( 'get_coauthors' )->justReturn( array( $coauthor1, $coauthor2 ) );
		Functions\when( 'get_author_posts_url' )->justReturn( 'https://example.com/author/first-coauthor' );

		$result = $this->coauthors_plus->filter_oembed_response_data( $data, $post );

		$this->assertSame( 'First Coauthor', $result['author_name'] );
		$this->assertSame( 'https://example.com/author/first-coauthor', $result['author_url'] );
	}
}
