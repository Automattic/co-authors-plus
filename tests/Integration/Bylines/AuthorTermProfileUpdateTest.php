<?php
/**
 * Tests for refreshing an author term when a user's profile is updated.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Regression coverage for issue #849.
 *
 * A co-author's searchable author term (its description holds the display name,
 * email and login) was only rebuilt when CAP itself touched the author, never
 * when the underlying user's profile changed through normal wp-admin. On a
 * persistent object cache this left the author search matching stale details
 * until a manual flush. The profile_update hook now refreshes an existing
 * co-author term so the search stays in step.
 *
 * @covers \CoAuthors_Plus::update_author_term_on_profile_update
 */
class AuthorTermProfileUpdateTest extends TestCase {

	public function test_author_term_refreshes_when_user_profile_changes(): void {
		$user = $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => 'jane_refresh',
				'display_name' => 'Jane Smith',
			)
		);

		// Give the co-author a searchable term, and confirm the original name matches.
		$this->_cap->update_author_term( $user );
		$this->assertArrayHasKey(
			'jane_refresh',
			$this->_cap->search_authors( 'Smith' ),
			'Sanity check: the co-author should be searchable by their original name.'
		);

		// Rename the user through the normal flow, which fires profile_update.
		wp_update_user(
			array(
				'ID'           => $user->ID,
				'display_name' => 'Jane Jones',
			)
		);

		$this->assertArrayHasKey(
			'jane_refresh',
			$this->_cap->search_authors( 'Jones' ),
			'After a profile update the co-author must be searchable by their new name.'
		);
	}

	public function test_profile_update_does_not_create_a_term_for_a_non_coauthor(): void {
		$user = $this->factory()->user->create_and_get(
			array(
				'role'         => 'subscriber',
				'user_login'   => 'sub_refresh',
				'display_name' => 'Sub Scriber',
			)
		);

		// This user has never been a co-author and has no term.
		$this->assertEmpty( $this->_cap->get_author_term( $user ), 'Sanity check: the user has no author term to begin with.' );

		wp_update_user(
			array(
				'ID'           => $user->ID,
				'display_name' => 'Sub Renamed',
			)
		);

		$this->assertEmpty(
			$this->_cap->get_author_term( $user ),
			'A profile update must not create an author term for a user who is not a co-author.'
		);
	}
}
