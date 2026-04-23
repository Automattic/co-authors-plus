<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

/**
 * @covers \CoAuthors_Plus::filter_pre_get_avatar_data_url()
 */
class FilterPreGetAvatarDataTest extends TestCase {

	private function attach_image( int $post_id ): string {
		$attachment_id = $this->factory()->attachment->create_upload_object(
			__DIR__ . '/fixtures/dummy-attachment.png',
			$post_id
		);
		set_post_thumbnail( $post_id, $attachment_id );
		return wp_get_attachment_url( $attachment_id );
	}

	/**
	 * Insert a WP user at a specific ID so it collides with a guest-author post ID.
	 *
	 * The factory's `ID` argument is ignored by `wp_insert_user()` on insert, so
	 * we go directly through `$wpdb` to reserve the id we need.
	 */
	private function create_user_at_id( int $id, string $login, string $email ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->users,
			array(
				'ID'            => $id,
				'user_login'    => $login,
				'user_pass'     => 'password',
				'user_nicename' => $login,
				'user_email'    => $email,
				'user_registered' => current_time( 'mysql' ),
				'display_name'  => $login,
			)
		);
		clean_user_cache( $id );
	}

	public function tear_down(): void {
		$GLOBALS['current_screen'] = null;
		parent::tear_down();
	}

	/**
	 * The featured image should be returned for a guest author across every
	 * admin screen when the caller flags the lookup as a guest user.
	 *
	 * @dataProvider data_admin_screens
	 */
	public function test_guest_author_featured_image_returned_on_admin_screens( ?string $screen ): void {
		$guest_author_id  = $this->create_guest_author( 'featured_guest' );
		$expected_fragment = $this->attach_image( $guest_author_id );

		if ( null !== $screen ) {
			set_current_screen( $screen );
		}

		$url = get_avatar_url(
			$guest_author_id,
			array( 'user_type' => 'guest-user' )
		);

		$this->assertStringContainsString(
			pathinfo( $expected_fragment, PATHINFO_FILENAME ),
			$url,
			"Expected featured image for guest author on screen '$screen'."
		);
	}

	public function data_admin_screens(): array {
		return array(
			'no screen (frontend)'  => array( null ),
			'post edit (post.php)'  => array( 'post' ),
			'post list (edit.php)'  => array( 'edit-post' ),
			'profile (profile.php)' => array( 'profile' ),
		);
	}

	/**
	 * When a WP user ID collides with a guest-author post ID and the caller
	 * did not flag the lookup as a guest author, core's Gravatar URL must win
	 * (the original bug fixed by PR #996 — a user's avatar should not be
	 * replaced with a colliding guest author's featured image).
	 */
	public function test_colliding_wp_user_wins_when_user_type_not_specified(): void {
		$guest_author_id = $this->create_guest_author( 'collision_ga' );
		$this->attach_image( $guest_author_id );

		$this->create_user_at_id( $guest_author_id, 'collision_user', 'collision_user@example.com' );

		$url = get_avatar_url( $guest_author_id );

		$this->assertStringContainsString(
			'gravatar.com',
			$url,
			'Expected WP user Gravatar to win when caller did not flag as guest-user.'
		);
	}

	/**
	 * Guest-user flag overrides a collision — callers that know they are
	 * rendering a guest author (CAP meta box, post list column, REST endpoint)
	 * get the featured image even if a WP user has the same ID.
	 */
	public function test_guest_user_flag_wins_over_colliding_wp_user(): void {
		$guest_author_id   = $this->create_guest_author( 'flag_ga' );
		$expected_fragment = $this->attach_image( $guest_author_id );

		$this->create_user_at_id( $guest_author_id, 'flag_collision', 'flag_collision@example.com' );

		$url = get_avatar_url(
			$guest_author_id,
			array( 'user_type' => 'guest-user' )
		);

		$this->assertStringContainsString(
			pathinfo( $expected_fragment, PATHINFO_FILENAME ),
			$url
		);
	}

	/**
	 * The wp-user flag always bypasses the filter.
	 */
	public function test_wp_user_flag_bypasses_filter(): void {
		$guest_author_id = $this->create_guest_author( 'bypass_ga' );
		$this->attach_image( $guest_author_id );

		$url = get_avatar_url(
			$guest_author_id,
			array( 'user_type' => 'wp-user' )
		);

		$this->assertStringContainsString( 'gravatar.com', $url );
	}
}
