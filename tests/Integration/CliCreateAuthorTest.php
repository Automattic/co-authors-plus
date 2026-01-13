<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

/**
 * Tests for the CLI create-author command behavior.
 *
 * These tests verify the duplicate detection logic in create_guest_author(),
 * specifically that empty field values don't cause false positive matches.
 *
 * @link https://github.com/Automattic/Co-Authors-Plus/issues/1163
 */
class CliCreateAuthorTest extends TestCase {

	/**
	 * Verifies that two guest authors with different user_logins but both with
	 * empty emails are not considered duplicates.
	 *
	 * This tests the fix for issue #1163 where empty email values caused
	 * false "already exists" matches.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_by()
	 */
	public function test_empty_email_does_not_match_existing_author_with_empty_email(): void {
		global $coauthors_plus;

		// Create first guest author with no email.
		$author1_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Author One',
				'user_login'   => 'author-one',
				'user_email'   => '',
			)
		);

		$this->assertIsInt( $author1_id, 'First guest author should be created successfully.' );

		// Create second guest author with different login but also no email.
		$author2_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Author Two',
				'user_login'   => 'author-two',
				'user_email'   => '',
			)
		);

		$this->assertIsInt( $author2_id, 'Second guest author should be created successfully despite both having empty emails.' );
		$this->assertNotEquals( $author1_id, $author2_id, 'Authors should have different IDs.' );
	}

	/**
	 * Verifies that looking up a guest author by empty email returns false.
	 *
	 * Empty values should not match against other empty values in the database.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_by()
	 */
	public function test_get_guest_author_by_empty_email_returns_false(): void {
		global $coauthors_plus;

		// Create a guest author with no email.
		$coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'No Email Author',
				'user_login'   => 'no-email-author',
				'user_email'   => '',
			)
		);

		// Looking up by empty email should return false, not match the author above.
		$result = $coauthors_plus->guest_authors->get_guest_author_by( 'user_email', '', true );

		$this->assertFalse( $result, 'Empty email lookup should return false, not match existing authors with empty email.' );
	}

	/**
	 * Verifies that looking up a guest author by empty user_login returns false.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_by()
	 */
	public function test_get_guest_author_by_empty_user_login_returns_false(): void {
		global $coauthors_plus;

		// Looking up by empty user_login should return false.
		$result = $coauthors_plus->guest_authors->get_guest_author_by( 'user_login', '', true );

		$this->assertFalse( $result, 'Empty user_login lookup should return false.' );
	}

	/**
	 * Verifies that matching by email still works when email is provided.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_by()
	 */
	public function test_get_guest_author_by_email_matches_when_provided(): void {
		global $coauthors_plus;

		$email = 'test-author@example.com';

		// Create a guest author with an email.
		$author_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Test Author',
				'user_login'   => 'test-author',
				'user_email'   => $email,
			)
		);

		// Looking up by that email should find the author.
		$result = $coauthors_plus->guest_authors->get_guest_author_by( 'user_email', $email, true );

		$this->assertIsObject( $result, 'Should find guest author by email.' );
		$this->assertEquals( $author_id, $result->ID, 'Should return the correct author.' );
	}

	/**
	 * Verifies that matching by user_login still works when provided.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_by()
	 */
	public function test_get_guest_author_by_user_login_matches_when_provided(): void {
		global $coauthors_plus;

		$user_login = 'findable-author';

		// Create a guest author.
		$author_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Findable Author',
				'user_login'   => $user_login,
			)
		);

		// Looking up by user_login should find the author.
		$result = $coauthors_plus->guest_authors->get_guest_author_by( 'user_login', $user_login, true );

		$this->assertIsObject( $result, 'Should find guest author by user_login.' );
		$this->assertEquals( $author_id, $result->ID, 'Should return the correct author.' );
	}
}
