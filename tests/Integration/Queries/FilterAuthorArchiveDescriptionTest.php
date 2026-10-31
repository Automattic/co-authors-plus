<?php
/**
 * Tests for the author archive description filter.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Queries;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers \CoAuthors_Plus::filter_author_archive_description()
 */
class FilterAuthorArchiveDescriptionTest extends TestCase {

	/**
	 * Default behaviour: regular user author archive renders the user's biographical description.
	 */
	public function test_user_author_archive_renders_description(): void {
		$author = $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => 'desc-user-author',
				'display_name' => 'Jane Description',
				'description'  => 'Jane is a staff investigative reporter.',
			)
		);
		$this->create_post( $author );

		$this->go_to( home_url( '/?author_name=' . $author->user_nicename ) );

		$this->assertSame( 'Jane is a staff investigative reporter.', get_the_archive_description() );
	}

	/**
	 * Guest authors (no underlying WP_User) must render their biographical description.
	 */
	public function test_guest_author_archive_renders_description(): void {
		global $coauthors_plus;

		$coauthors_plus->guest_authors->create(
			array(
				'user_login'   => 'guest-desc-author',
				'display_name' => 'Guest Description',
				'description'  => 'Guest contributor specializing in open source.',
			)
		);

		$this->go_to( home_url( '/?author_name=guest-desc-author' ) );

		$this->assertSame( 'Guest contributor specializing in open source.', get_the_archive_description() );
	}

	/**
	 * When the author has no description, the default description is preserved.
	 */
	public function test_author_archive_with_empty_description_preserves_default(): void {
		global $coauthors_plus;

		$coauthors_plus->guest_authors->create(
			array(
				'user_login'   => 'guest-no-bio',
				'display_name' => 'Guest No Bio',
			)
		);

		$this->go_to( home_url( '/?author_name=guest-no-bio' ) );

		$this->assertSame( '', get_the_archive_description() );
	}

	/**
	 * Outside an author archive, the filter must be a no-op.
	 */
	public function test_non_author_archive_is_untouched(): void {
		global $coauthors_plus;

		$result = $coauthors_plus->filter_author_archive_description( 'Untouched description' );

		$this->assertSame( 'Untouched description', $result );
	}

	/**
	 * Core passes a null description in some contexts. The filter must still honour
	 * its string return type rather than fatal with a TypeError.
	 */
	public function test_null_description_returns_empty_string(): void {
		$this->assertSame( '', apply_filters( 'get_the_archive_description', null ) );
	}
}
