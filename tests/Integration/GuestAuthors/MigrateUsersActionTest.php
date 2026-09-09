<?php
/**
 * Tests for migrating WordPress users to guest authors.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Guest_Authors
 */
class MigrateUsersActionTest extends TestCase {

	/**
	 * Checks that the missing-user count excludes users with guest authors.
	 *
	 * @covers \CoAuthors_Guest_Authors::get_users_missing_guest_author_count()
	 */
	public function test_get_users_missing_guest_author_count(): void {
		$guest_authors = $this->_cap->guest_authors;
		$user          = $this->create_author( 'migration-count-user' );
		$initial_count = $guest_authors->get_users_missing_guest_author_count();

		$guest_authors->create_guest_author_from_user_id( $user->ID );

		$this->assertSame( $initial_count, $guest_authors->get_users_missing_guest_author_count() + 1 );
	}

	/**
	 * Checks that a migration batch creates guest authors and reports progress.
	 *
	 * @covers \CoAuthors_Guest_Authors::migrate_guest_authors_batch()
	 */
	public function test_migrate_guest_authors_batch(): void {
		$guest_authors = $this->_cap->guest_authors;
		$user          = $this->create_author( 'migration-batch-user' );
		$user_ids      = array_map(
			'intval',
			get_users(
				array(
					'fields'  => 'ID',
					'orderby' => 'user_login',
					'order'   => 'ASC',
				)
			)
		);
		$offset = array_search( (int) $user->ID, $user_ids, true );

		$this->assertNotFalse( $offset );
		$result = $guest_authors->migrate_guest_authors_batch( (int) $offset, 1 );

		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 1, $result['offset'] - (int) $offset );
		$guest_author = $guest_authors->get_guest_author_by( 'linked_account', $user->user_login );
		$this->assertIsObject( $guest_author );
		$this->assertSame( $user->user_login, $guest_author->linked_account );
	}

	/**
	 * Checks that a short batch reports a terminable state.
	 *
	 * @covers \CoAuthors_Guest_Authors::migrate_guest_authors_batch()
	 */
	public function test_migrate_guest_authors_batch_terminates_on_short_batch(): void {
		$guest_authors = $this->_cap->guest_authors;
		$total         = $guest_authors->count_users_via_wpdb();
		$offset        = max( 0, $total - 1 );

		$result = $guest_authors->migrate_guest_authors_batch( $offset, 1000 );

		$this->assertSame( true, $result['done'] );
		$this->assertSame( $total, $result['offset'] );
	}

	/**
	 * Checks that a full batch does not report completion.
	 *
	 * @covers \CoAuthors_Guest_Authors::migrate_guest_authors_batch()
	 */
	public function test_migrate_guest_authors_batch_does_not_terminate_on_full_batch(): void {
		$this->create_author( 'migration-full-batch-user' );

		$result = $this->_cap->guest_authors->migrate_guest_authors_batch( 0, 1 );

		$this->assertSame( false, $result['done'] );
		$this->assertSame( 1, $result['offset'] );
	}

	/**
	 * Checks that migration batch size and offset are constrained safely.
	 *
	 * @covers \CoAuthors_Guest_Authors::migrate_guest_authors_batch()
	 */
	public function test_migrate_guest_authors_batch_normalizes_arguments(): void {
		$result = $this->_cap->guest_authors->migrate_guest_authors_batch( -10, 1000 );

		$this->assertSame( 100, $result['batch_size'] );
		$this->assertSame(
			0,
			$result['offset'] - count(
				get_users(
					array(
						'fields'  => 'ID',
						'number'  => 100,
						'orderby' => 'user_login',
						'order'   => 'ASC',
					)
				)
			)
		);
	}

	/**
	 * Checks that the action rejects users without the required capability.
	 *
	 * @covers \CoAuthors_Guest_Authors::handle_migrate_guest_authors_action()
	 */
	public function test_handle_migrate_guest_authors_action_rejects_unauthorized_user(): void {
		$user         = $this->create_subscriber( 'migration-action-subscriber' );
		$current_user = get_current_user_id();
		$post_backup  = $_POST;
		wp_set_current_user( $user->ID );
		$_POST['_wpnonce'] = wp_create_nonce( 'cap_migrate_guest_authors' );

		try {
			$this->_cap->guest_authors->handle_migrate_guest_authors_action();
			$this->fail( 'Unauthorized migration did not stop execution.' );
		} catch ( \WPDieException $exception ) {
			$expected = esc_html__( "You don't have permission to perform this action.", 'co-authors-plus' );
			$this->assertStringContainsString( $expected, $exception->getMessage() );
		} finally {
			wp_set_current_user( $current_user );
			$_POST = $post_backup;
		}
	}

	/**
	 * Checks that the action rejects an invalid nonce.
	 *
	 * @covers \CoAuthors_Guest_Authors::handle_migrate_guest_authors_action()
	 */
	public function test_handle_migrate_guest_authors_action_rejects_invalid_nonce(): void {
		$user         = $this->factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$current_user = get_current_user_id();
		$post_backup  = $_POST;
		wp_set_current_user( $user->ID );
		$_POST['_wpnonce'] = wp_create_nonce( 'invalid-migration-nonce' );

		try {
			$this->_cap->guest_authors->handle_migrate_guest_authors_action();
			$this->fail( 'Invalid migration nonce did not stop execution.' );
		} catch ( \WPDieException $exception ) {
			$this->assertNotEmpty( $exception->getMessage() );
		} finally {
			wp_set_current_user( $current_user );
			$_POST = $post_backup;
		}
	}
}
