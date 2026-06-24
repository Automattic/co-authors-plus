<?php
/**
 * Tests for the coauthors_get_avatar() template tag.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers ::coauthors_get_avatar()
 */
class CoauthorsGetAvatarTest extends TestCase {

	/**
	 * Checks co-author's avatar.
	 */
	public function test_with_author(): void {
		$author = $this->create_author();

		$this->assertEmpty( coauthors_get_avatar( $author->ID ) );
		$this->assertEquals( preg_match( "|^<img alt='[^']*' src='[^']*' srcset='[^']*' class='[^']*' height='[^']*' width='[^']*'( loading='[^']*')?( decoding='[^']*')?/>$|", coauthors_get_avatar( $author ) ), 1 );
	}

	/**
	 * Checks co-author's avatar when author is a guest author.
	 */
	public function test_with_guest_author(): void {
		global $coauthors_plus;

		$guest_author_id = $this->create_guest_author();
		$guest_author  = $coauthors_plus->guest_authors->get_guest_author_by( 'id', $guest_author_id );
		$attachment_id = $this->factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/dummy-attachment.png', $guest_author_id );

		$this->assertEquals( preg_match( "|^<img alt='[^']*' src='[^']*' srcset='[^']*' class='[^']*' height='[^']*' width='[^']*'( loading='[^']*')?( decoding='[^']*')?/>$|", coauthors_get_avatar( $guest_author ) ), 1 );

		set_post_thumbnail( $guest_author->ID, $attachment_id );

		$avatar         = coauthors_get_avatar( $guest_author );
		$attachment_url = wp_get_attachment_url( $attachment_id );

		// Checking for dummy-attachment instead of dummy-attachment.png, as filename might change to
		// dummy-attachment-1.png, dummy-attachment-2.png, etc. when running multiple tests.
		$this->assertStringContainsString( 'dummy-attachment', $avatar );
		$this->assertStringContainsString( $attachment_url, $avatar );
	}

	/**
	 * Checks co-author's avatar when user's email is not set somehow.
	 */
	public function test_with_guest_author_when_user_email_not_set(): void {
		global $coauthors_plus;

		$guest_author_id = $this->create_guest_author();
		$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'id', $guest_author_id );
		unset( $guest_author->user_email );

		$this->assertEmpty( coauthors_get_avatar( $guest_author ) );
	}

	/**
	 * Checks co-author's avatar with size.
	 */
	public function test_with_author_and_size_arg(): void {
		$author = $this->create_author();
		$size = '100';

		$this->assertEquals( preg_match( "|^<img .*height='$size'.*width='$size'|", coauthors_get_avatar( $author, $size ) ), 1 );
	}

	/**
	 * Checks co-author's avatar with alt.
	 */
	public function test_with_author_and_alt_arg(): void {
		$author = $this->create_author();
		$alt = 'Test';

		$this->assertEquals( preg_match( "|^<img alt='$alt'|", coauthors_get_avatar( $author, 96, '', $alt ) ), 1 );
	}
}
