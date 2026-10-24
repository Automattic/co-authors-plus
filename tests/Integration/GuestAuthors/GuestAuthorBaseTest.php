<?php
/**
 * Tests for the custom guest author archive base.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use CoAuthors_Guest_Authors;

/**
 * Covers the coauthors_guest_author_base filter.
 *
 * @see https://github.com/Automattic/co-authors-plus/issues/171
 *
 * @covers \CoAuthors_Guest_Authors::register_guest_author_permastruct
 * @covers \CoAuthors_Guest_Authors::filter_author_link
 */
class GuestAuthorBaseTest extends TestCase {

	/**
	 * Guest author used by the tests.
	 *
	 * @var object
	 */
	private $guest_author;

	public function set_up() {
		parent::set_up();

		$this->set_permalink_structure( '/%postname%/' );

		$guest_author_id    = $this->create_guest_author( 'guest-writer' );
		$this->guest_author = $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_author_id );

		// An author archive with no posts is a 404, so give the guest author one.
		$post_id = $this->factory()->post->create();
		$this->_cap->add_coauthors( $post_id, array( $this->guest_author->user_login ) );
	}

	public function tear_down() {
		remove_all_filters( 'coauthors_guest_author_base' );
		remove_permastruct( CoAuthors_Guest_Authors::PERMASTRUCT );

		// Rebuilds the rules so later tests don't see the custom base.
		$this->set_permalink_structure( '' );

		parent::tear_down();
	}

	/**
	 * Register the permastruct with the given base and rebuild the rules.
	 *
	 * @param string $base Base returned by the filter.
	 */
	private function use_guest_author_base( string $base ): void {
		add_filter(
			'coauthors_guest_author_base',
			static function () use ( $base ) {
				return $base;
			}
		);
		$this->_cap->guest_authors->register_guest_author_permastruct();

		// Rebuilds the rules with the new permastruct.
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function test_guest_author_link_uses_custom_base(): void {
		$this->use_guest_author_base( 'contributor' );

		$this->assertSame(
			home_url( '/contributor/guest-writer/' ),
			get_author_posts_url( $this->guest_author->ID, $this->guest_author->user_nicename )
		);
	}

	public function test_slashes_around_custom_base_are_trimmed(): void {
		$this->use_guest_author_base( '/contributor/' );

		$this->assertSame(
			home_url( '/contributor/guest-writer/' ),
			get_author_posts_url( $this->guest_author->ID, $this->guest_author->user_nicename )
		);
	}

	public function test_wp_user_link_keeps_author_base(): void {
		$this->use_guest_author_base( 'contributor' );

		$user = $this->create_author( 'wp-writer' );

		$this->assertSame(
			home_url( '/author/wp-writer/' ),
			get_author_posts_url( $user->ID, $user->user_nicename )
		);
	}

	public function test_guest_author_link_keeps_author_base_without_filter(): void {
		$this->_cap->guest_authors->register_guest_author_permastruct();

		$this->assertSame(
			home_url( '/author/guest-writer/' ),
			get_author_posts_url( $this->guest_author->ID, $this->guest_author->user_nicename )
		);
	}

	public function test_base_same_as_author_base_registers_nothing(): void {
		global $wp_rewrite;

		$this->use_guest_author_base( $wp_rewrite->author_base );

		$this->assertFalse( $wp_rewrite->get_extra_permastruct( CoAuthors_Guest_Authors::PERMASTRUCT ) );
	}

	public function test_custom_base_archive_resolves_guest_author(): void {
		$this->use_guest_author_base( 'contributor' );

		$this->go_to( home_url( '/contributor/guest-writer/' ) );

		$this->assertTrue( is_author() );
		$this->assertSame( (int) $this->guest_author->ID, get_queried_object_id() );
	}

	public function test_custom_base_feed_resolves(): void {
		$this->use_guest_author_base( 'contributor' );

		$this->go_to( home_url( '/contributor/guest-writer/feed/' ) );

		$this->assertTrue( is_feed() );
		$this->assertSame( 'guest-writer', get_query_var( 'author_name' ) );
	}

	public function test_author_base_archive_still_resolves_guest_author(): void {
		$this->use_guest_author_base( 'contributor' );

		$this->go_to( home_url( '/author/guest-writer/' ) );

		$this->assertTrue( is_author() );
		$this->assertSame( (int) $this->guest_author->ID, get_queried_object_id() );
	}
}
