<?php
/**
 * Tests for the author_link filter.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Tests for CoAuthors_Guest_Authors::filter_author_link() when core has no nicename to pass it.
 *
 * @covers \CoAuthors_Guest_Authors::filter_author_link
 */
class FilterAuthorLinkTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '' );

		// Keep guest author post IDs clear of user IDs.
		self::factory()->post->create(
			array(
				'import_id'   => 1000000,
				'post_status' => 'draft',
			)
		);
	}

	public function test_plain_permalinks_keep_the_core_link_for_a_user(): void {
		$user = $this->create_author( 'link_user' );

		$this->assertSame( home_url( '/?author=' . $user->ID ), get_author_posts_url( $user->ID ) );
	}

	public function test_plain_permalinks_use_the_nicename_when_it_is_passed(): void {
		$user = $this->create_author( 'link_user' );

		$this->assertSame(
			add_query_arg( 'author_name', 'link_user', home_url() ),
			get_author_posts_url( $user->ID, 'link_user' )
		);
	}

	public function test_plain_permalinks_on_an_archive_keep_another_users_own_link(): void {
		$guest_id = $this->create_guest_author( 'link_guest' );
		$other    = $this->create_author( 'link_other' );

		$this->go_to( '/?author_name=link_guest' );
		$this->assertTrue( is_author() );
		$this->assertSame( (int) $guest_id, get_queried_object_id() );
		$this->assertNotSame( (int) $guest_id, $other->ID );

		$this->assertSame( home_url( '/?author=' . $other->ID ), get_author_posts_url( $other->ID ) );
	}

	public function test_plain_permalinks_on_an_archive_still_find_the_archives_own_guest_author(): void {
		$guest_id = $this->create_guest_author( 'link_guest' );

		$this->go_to( '/?author_name=link_guest' );

		$this->assertSame(
			add_query_arg( 'author_name', 'link_guest', home_url() ),
			get_author_posts_url( (int) $guest_id )
		);
	}

	public function test_pretty_permalinks_still_use_the_users_nicename(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$user = $this->create_author( 'link_user' );

		$this->assertSame( home_url( '/author/link_user/' ), get_author_posts_url( $user->ID ) );
	}
}
