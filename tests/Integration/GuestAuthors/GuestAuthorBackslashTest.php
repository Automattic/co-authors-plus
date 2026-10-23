<?php
/**
 * Tests that guest author profile fields keep their backslashes.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Services\Guest_Author_Service;
use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Both wp_insert_post() and update_post_meta() unslash what they are given.
 * Every write path used to hand them values that were already unslashed, so
 * each backslash was stripped on save. The apostrophes guard the other side:
 * a value must not come back with a stray slash in front of them either.
 *
 * @link https://github.com/Automattic/co-authors-plus/issues/1495
 */
class GuestAuthorBackslashTest extends TestCase {

	private const DISPLAY_NAME = "Pat O'Brien \\ Co";

	private const DESCRIPTION = "It's kept in C:\\Users\\pat";

	public function tear_down() {
		unset(
			$_POST['guest-author-nonce'],
			$_POST['cap-display_name'],
			$_POST['cap-description']
		);
		parent::tear_down();
	}

	/**
	 * @covers \CoAuthors_Guest_Authors::create
	 */
	public function test_create_keeps_backslashes(): void {
		$id = $this->_cap->guest_authors->create(
			array(
				'display_name' => self::DISPLAY_NAME,
				'user_login'   => 'pat-obrien',
				'description'  => self::DESCRIPTION,
			)
		);

		$this->assertIsInt( $id );
		$this->assert_profile_kept( $id );
	}

	/**
	 * The service sanitises before create() slashes, so the bio must come
	 * back neither stripped nor double slashed.
	 *
	 * @covers \Automattic\CoAuthorsPlus\Services\Guest_Author_Service::create
	 */
	public function test_service_create_keeps_backslashes(): void {
		$service = new Guest_Author_Service( $this->_cap->guest_authors );

		$id = $service->create(
			array(
				'display_name' => self::DISPLAY_NAME,
				'user_login'   => 'pat-obrien',
				'description'  => self::DESCRIPTION,
			)
		);

		$this->assertIsInt( $id );
		$this->assert_profile_kept( $id );
	}

	/**
	 * A save from the edit screen, with $_POST slashed as WordPress delivers it.
	 *
	 * @covers \CoAuthors_Guest_Authors::manage_guest_author_filter_post_data
	 * @covers \CoAuthors_Guest_Authors::manage_guest_author_save_meta_fields
	 */
	public function test_admin_save_keeps_backslashes(): void {
		$id = $this->_cap->guest_authors->create(
			array(
				'display_name' => 'Pat',
				'user_login'   => 'pat-obrien',
			)
		);

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST['guest-author-nonce'] = wp_create_nonce( 'guest-author-nonce' );
		$_POST['cap-display_name']   = wp_slash( self::DISPLAY_NAME );
		$_POST['cap-description']    = wp_slash( self::DESCRIPTION );

		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Replaced from cap-display_name by the filter',
			)
		);

		$this->assert_profile_kept( $id );
	}

	/**
	 * Assert the profile's name and bio were stored exactly as written.
	 *
	 * @param int $id Guest author ID.
	 */
	private function assert_profile_kept( int $id ): void {
		$this->assertSame( self::DISPLAY_NAME, get_post_meta( $id, 'cap-display_name', true ) );
		$this->assertSame( self::DISPLAY_NAME, get_post( $id )->post_title );
		$this->assertSame( self::DESCRIPTION, get_post_meta( $id, 'cap-description', true ) );
	}
}
