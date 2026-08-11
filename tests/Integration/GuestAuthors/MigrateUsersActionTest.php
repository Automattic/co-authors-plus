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
		$total          = count_users()['total_users'];
		$user_ids = array_map(
			'intval',
			get_users(
				array(
					'fields'  => 'ID',
					'orderby' => 'user_login',
					'order'   => 'ASC',
				)
			)
		);
		$offset   = array_search( (int) $user->ID, $user_ids, true );

		$this->assertNotFalse( $offset );
		$result = $guest_authors->migrate_guest_authors_batch( (int) $offset, 1 );

		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 1, $result['offset'] - (int) $offset );
		$this->assertSame( $total, $result['total'] );
		$this->assertNotInstanceOf( 'WP_Error', $guest_authors->get_guest_author_by( 'linked_account', $user->user_login ) );
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
}
