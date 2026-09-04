<?php
/**
 * Tests for the two prefix bugs fixed alongside the Prefix helper.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Two places treated "contains cap-" as if it meant "starts with cap-".
 *
 * Both are exercised here through the hooks that reach them, rather than by
 * calling the methods directly, so the tests describe what a site sees.
 *
 * @covers \CoAuthors_Guest_Authors::manage_guest_author_filter_post_data
 * @covers \CoAuthors_Guest_Authors::filter_update_post_metadata
 */
class GuestAuthorPrefixHandlingTest extends TestCase {

	/**
	 * @var \CoAuthors_Guest_Authors
	 */
	private $guest_authors;

	public function set_up() {
		parent::set_up();

		$this->guest_authors = $this->_cap->guest_authors;

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		unset( $_POST['guest-author-nonce'], $_POST['cap-display_name'] );

		parent::tear_down();
	}

	/**
	 * Run the post-data filter for a guest author as the edit screen would.
	 *
	 * @param int    $guest_author_id Guest author post ID.
	 * @param string $display_name    Display name submitted with the form.
	 * @return array The filtered post data.
	 */
	private function filter_post_data( int $guest_author_id, string $display_name ): array {
		/*
		 * Populate $_POST only for the duration of this call. The filter is
		 * also reached through wp_insert_post() during create(), where a nonce
		 * left lying around would make it engage with no form data behind it.
		 */
		$_POST['guest-author-nonce'] = wp_create_nonce( 'guest-author-nonce' );
		$_POST['cap-display_name']   = $display_name;

		try {
			return $this->guest_authors->manage_guest_author_filter_post_data(
				array( 'post_type' => $this->guest_authors->post_type ),
				array(
					'ID'        => $guest_author_id,
					'post_type' => $this->guest_authors->post_type,
				)
			);
		} finally {
			unset( $_POST['guest-author-nonce'], $_POST['cap-display_name'] );
		}
	}

	/**
	 * A name containing "cap-" is not mistaken for a prefixed one.
	 *
	 * The filter strips the prefix from the slug to check whether a WordPress
	 * user already owns that nicename. It used
	 * str_replace(), which removes every occurrence, so "Recap Caption" became
	 * `recaption` and the guard compared against the wrong user entirely.
	 *
	 * Here a real user owns `recaption`. The guest author being saved is
	 * `recap-caption`, a different nicename, so the save must go through. The
	 * old code matched them and called wp_die().
	 */
	public function test_a_name_containing_the_prefix_is_not_treated_as_prefixed(): void {
		$this->factory()->user->create(
			array(
				'user_login'    => 'recaption',
				'user_nicename' => 'recaption',
				'role'          => 'author',
			)
		);

		$guest_author_id = $this->guest_authors->create(
			array(
				'display_name' => 'Recap Caption',
				'user_login'   => 'recap-caption',
			)
		);

		$this->assertIsInt( $guest_author_id );

		$post_data = $this->filter_post_data( $guest_author_id, 'Recap Caption' );

		$this->assertSame(
			'cap-recap-caption',
			$post_data['post_name'],
			'The slug should keep its internal "cap-" and gain a single prefix.'
		);
	}

	/**
	 * A guest author whose nicename really is taken still cannot be saved.
	 *
	 * The control for the test above: the guard must still fire when the
	 * stripped nicename genuinely belongs to a WordPress user.
	 */
	public function test_a_genuinely_colliding_nicename_is_still_refused(): void {
		// The guest author first: create() refuses a user_login that already
		// belongs to a WordPress user, so the collision has to arrive after.
		$guest_author_id = $this->guest_authors->create(
			array(
				'display_name' => 'Ada Lovelace',
				'user_login'   => 'ada-lovelace',
			)
		);

		$this->assertIsInt( $guest_author_id );

		$this->factory()->user->create(
			array(
				'user_login'    => 'ada-lovelace',
				'user_nicename' => 'ada-lovelace',
				'role'          => 'author',
			)
		);

		$this->expectException( \WPDieException::class );

		$this->filter_post_data( $guest_author_id, 'Ada Lovelace' );
	}

	/**
	 * Only keys that start with the prefix invalidate the guest author cache.
	 *
	 * The metadata filter used strpos() !== false, so a third-party key merely
	 * containing "cap-" anywhere busted the cache on every save.
	 *
	 * @dataProvider data_unrelated_meta_keys
	 *
	 * @param string $meta_key Key that should not invalidate the cache.
	 */
	public function test_an_unrelated_meta_key_leaves_the_cache_alone( string $meta_key ): void {
		$guest_author_id = $this->guest_authors->create(
			array(
				'display_name' => 'Grace Hopper',
				'user_login'   => 'grace-hopper',
			)
		);

		$cache_key = $this->guest_authors->get_cache_key( 'ID', $guest_author_id );
		$this->guest_authors->get_guest_author_by( 'ID', $guest_author_id );
		$this->assertNotFalse(
			wp_cache_get( $cache_key, \CoAuthors_Guest_Authors::$cache_group ),
			'Precondition: the guest author should be cached.'
		);

		update_post_meta( $guest_author_id, $meta_key, 'some value' );

		$this->assertNotFalse(
			wp_cache_get( $cache_key, \CoAuthors_Guest_Authors::$cache_group ),
			'An unrelated meta key should not invalidate the guest author cache.'
		);
	}

	/**
	 * Meta keys that contain "cap-" without starting with it.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_unrelated_meta_keys(): array {
		return array(
			'third-party key containing the prefix' => array( 'my-cap-setting' ),
			'prefix at the end'                     => array( 'setting-cap-' ),
			'unrelated key entirely'                => array( 'some_plugin_flag' ),
		);
	}

	/**
	 * A genuine guest author field still invalidates the cache.
	 *
	 * The control: without this, the assertions above would pass just as
	 * happily against a check that never invalidated anything.
	 */
	public function test_a_guest_author_field_still_invalidates_the_cache(): void {
		$guest_author_id = $this->guest_authors->create(
			array(
				'display_name' => 'Grace Hopper',
				'user_login'   => 'grace-hopper',
			)
		);

		$cache_key = $this->guest_authors->get_cache_key( 'ID', $guest_author_id );
		$this->guest_authors->get_guest_author_by( 'ID', $guest_author_id );
		$this->assertNotFalse(
			wp_cache_get( $cache_key, \CoAuthors_Guest_Authors::$cache_group )
		);

		update_post_meta( $guest_author_id, $this->guest_authors->get_post_meta_key( 'first_name' ), 'Grace' );

		$this->assertFalse(
			wp_cache_get( $cache_key, \CoAuthors_Guest_Authors::$cache_group ),
			'Changing a guest author field must invalidate its cache.'
		);
	}
}
