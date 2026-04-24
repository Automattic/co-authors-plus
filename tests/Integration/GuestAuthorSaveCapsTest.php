<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

/**
 * Regression tests for the capability checks added to
 * CoAuthors_Guest_Authors::manage_guest_author_filter_post_data() and
 * CoAuthors_Guest_Authors::manage_guest_author_save_meta_fields().
 *
 * The two save hooks used to rely solely on the guest-author-nonce.
 * A current_user_can( 'edit_post', ... ) check now guards both hooks
 * as defence in depth, in case the nonce is ever exposed to a user
 * who lacks rights to edit the guest author.
 *
 * @covers \CoAuthors_Guest_Authors::manage_guest_author_filter_post_data
 * @covers \CoAuthors_Guest_Authors::manage_guest_author_save_meta_fields
 */
class GuestAuthorSaveCapsTest extends TestCase {

	/** @var \WP_User */
	private $admin;

	/** @var \WP_User */
	private $subscriber;

	/** @var int */
	private $guest_author_post_id;

	public function set_up() {
		parent::set_up();

		$this->admin      = $this->factory()->user->create_and_get(
			array(
				'role'       => 'administrator',
				'user_login' => 'ga_caps_admin',
			)
		);
		$this->subscriber = $this->factory()->user->create_and_get(
			array(
				'role'       => 'subscriber',
				'user_login' => 'ga_caps_subscriber',
			)
		);

		// Create the guest author as admin so the fixture is valid.
		wp_set_current_user( $this->admin->ID );
		$this->guest_author_post_id = $this->_cap->guest_authors->create(
			array(
				'display_name' => 'Caps Test Guest',
				'user_login'   => 'caps_test_guest',
			)
		);
	}

	public function tear_down() {
		unset(
			$_POST['guest-author-nonce'],
			$_POST['cap-display_name'],
			$_POST['cap-first_name'],
			$_POST['cap-last_name']
		);
		parent::tear_down();
	}

	/**
	 * @return array $post_data shaped like what wp_insert_post_data receives
	 *               for a guest-author save.
	 */
	private function make_post_data(): array {
		return array(
			'post_title' => 'Caps Test Guest (renamed)',
			'post_name'  => 'cap-caps_test_guest',
			'post_type'  => 'guest-author',
		);
	}

	/**
	 * A subscriber without edit_post rights on the guest-author post should
	 * not be able to persist changes through the filter, even with a valid
	 * guest-author-nonce.
	 */
	public function test_filter_returns_unchanged_data_when_user_lacks_edit_post_cap(): void {
		wp_set_current_user( $this->subscriber->ID );

		$_POST['guest-author-nonce'] = wp_create_nonce( 'guest-author-nonce' );
		$_POST['cap-display_name']   = 'Should Not Stick';

		$post_data = $this->make_post_data();

		$filtered = $this->_cap->guest_authors->manage_guest_author_filter_post_data(
			$post_data,
			array( 'ID' => $this->guest_author_post_id )
		);

		$this->assertSame(
			$post_data,
			$filtered,
			'Filter must return unchanged post data for users without edit_post capability.'
		);
	}

	/**
	 * The filter should bail when no post ID is available, since a
	 * capability check without a post context cannot be meaningful.
	 */
	public function test_filter_returns_unchanged_data_when_post_id_missing(): void {
		wp_set_current_user( $this->admin->ID );

		$_POST['guest-author-nonce'] = wp_create_nonce( 'guest-author-nonce' );
		$_POST['cap-display_name']   = 'Does Not Matter';

		$post_data = $this->make_post_data();

		$filtered = $this->_cap->guest_authors->manage_guest_author_filter_post_data(
			$post_data,
			array( 'ID' => 0 )
		);

		$this->assertSame(
			$post_data,
			$filtered,
			'Filter must bail when no post ID is present, regardless of caps.'
		);
	}

	/**
	 * With a valid nonce and the right cap, the filter applies its
	 * normal rewriting behaviour (e.g. setting post_title from
	 * cap-display_name). This pins the positive path.
	 */
	public function test_filter_applies_changes_when_user_has_edit_post_cap(): void {
		wp_set_current_user( $this->admin->ID );

		$new_display_name            = 'Caps Test Guest Renamed';
		$_POST['guest-author-nonce'] = wp_create_nonce( 'guest-author-nonce' );
		$_POST['cap-display_name']   = $new_display_name;

		$post_data = $this->make_post_data();

		$filtered = $this->_cap->guest_authors->manage_guest_author_filter_post_data(
			$post_data,
			array( 'ID' => $this->guest_author_post_id )
		);

		$this->assertSame(
			$new_display_name,
			$filtered['post_title'],
			'Filter should rewrite post_title from cap-display_name for users with edit_post capability.'
		);
	}

	/**
	 * A subscriber cannot persist meta fields through the save_post action.
	 */
	public function test_save_meta_fields_skips_writes_when_user_lacks_edit_post_cap(): void {
		$existing_first_name = get_post_meta( $this->guest_author_post_id, 'cap-first_name', true );

		wp_set_current_user( $this->subscriber->ID );

		$_POST['guest-author-nonce'] = wp_create_nonce( 'guest-author-nonce' );
		$_POST['cap-first_name']     = 'Attacker';

		$post = get_post( $this->guest_author_post_id );
		$this->_cap->guest_authors->manage_guest_author_save_meta_fields( $this->guest_author_post_id, $post );

		$this->assertSame(
			$existing_first_name,
			get_post_meta( $this->guest_author_post_id, 'cap-first_name', true ),
			'Subscriber must not be able to persist guest-author meta.'
		);
	}

	/**
	 * An admin with valid nonce and cap persists the meta fields.
	 */
	public function test_save_meta_fields_persists_when_user_has_edit_post_cap(): void {
		wp_set_current_user( $this->admin->ID );

		$_POST['guest-author-nonce'] = wp_create_nonce( 'guest-author-nonce' );
		$_POST['cap-first_name']     = 'Legit';

		$post = get_post( $this->guest_author_post_id );
		$this->_cap->guest_authors->manage_guest_author_save_meta_fields( $this->guest_author_post_id, $post );

		$this->assertSame(
			'Legit',
			get_post_meta( $this->guest_author_post_id, 'cap-first_name', true ),
			'Admin with valid nonce should be able to persist guest-author meta.'
		);
	}

	/**
	 * Missing nonce is still a no-op for authorised users (guards the
	 * pre-existing nonce check from silently regressing).
	 */
	public function test_save_meta_fields_requires_nonce_even_for_admin(): void {
		$existing_first_name = get_post_meta( $this->guest_author_post_id, 'cap-first_name', true );

		wp_set_current_user( $this->admin->ID );

		unset( $_POST['guest-author-nonce'] );
		$_POST['cap-first_name'] = 'Should Not Stick';

		$post = get_post( $this->guest_author_post_id );
		$this->_cap->guest_authors->manage_guest_author_save_meta_fields( $this->guest_author_post_id, $post );

		$this->assertSame(
			$existing_first_name,
			get_post_meta( $this->guest_author_post_id, 'cap-first_name', true ),
			'Missing nonce must prevent writes even for authorised users.'
		);
	}
}
