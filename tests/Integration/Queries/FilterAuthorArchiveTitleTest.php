<?php
/**
 * Tests for the author archive title filter.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Queries;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers \CoAuthors_Plus::filter_author_archive_title()
 */
class FilterAuthorArchiveTitleTest extends TestCase {

	public function tear_down(): void {
		remove_filter( 'get_the_archive_title_prefix', '__return_empty_string' );
		parent::tear_down();
	}

	/**
	 * Default behaviour: the prefix core resolved (e.g. "Author:") is preserved
	 * and the co-author's display name is rendered.
	 */
	public function test_user_author_archive_keeps_prefix_by_default(): void {
		$author = $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => 'jane-doe',
				'display_name' => 'Jane Doe',
			)
		);
		$this->create_post( $author );

		$this->go_to( home_url( '/?author_name=' . $author->user_nicename ) );

		$this->assertSame( 'Author: Jane Doe', get_the_archive_title() );
	}

	/**
	 * When the core/query-title block has `showPrefix` set to false it hooks
	 * `get_the_archive_title_prefix` to return an empty string. The author
	 * archive title must then render without the "Author:" prefix.
	 *
	 * @see https://wordpress.org/support/topic/author-being-added-on-single-author-template/
	 */
	public function test_user_author_archive_drops_prefix_when_show_prefix_is_disabled(): void {
		$author = $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => 'jane-doe',
				'display_name' => 'Jane Doe',
			)
		);
		$this->create_post( $author );

		$this->go_to( home_url( '/?author_name=' . $author->user_nicename ) );

		add_filter( 'get_the_archive_title_prefix', '__return_empty_string' );

		$this->assertSame( 'Jane Doe', get_the_archive_title() );
	}

	/**
	 * Guest authors (no underlying WP_User) must follow the same prefix rule
	 * as regular user archives — present by default, absent when the block
	 * disables it.
	 */
	public function test_guest_author_archive_respects_show_prefix_toggle(): void {
		global $coauthors_plus;

		$coauthors_plus->guest_authors->create(
			array(
				'user_login'   => 'guest-archive',
				'display_name' => 'Guest Archive',
			)
		);

		$this->go_to( home_url( '/?author_name=guest-archive' ) );

		$this->assertSame( 'Author: Guest Archive', get_the_archive_title() );

		add_filter( 'get_the_archive_title_prefix', '__return_empty_string' );

		$this->assertSame( 'Guest Archive', get_the_archive_title() );
	}

	/**
	 * Outside an author archive, the filter must be a no-op.
	 */
	public function test_non_author_archive_is_untouched(): void {
		global $coauthors_plus;

		$result = $coauthors_plus->filter_author_archive_title( 'Untouched', 'Untouched', '' );

		$this->assertSame( 'Untouched', $result );
	}
}
