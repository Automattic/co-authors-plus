<?php
/**
 * Tests for changing a guest author slug from the edit screen.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * A slug change from the edit screen renames the author term in place, so
 * the posts of the guest author follow the new slug.
 *
 * @see https://github.com/Automattic/co-authors-plus/issues/29
 *
 * @covers \CoAuthors_Guest_Authors::manage_guest_author_filter_post_data
 * @covers \CoAuthors_Guest_Authors::manage_guest_author_save_meta_fields
 * @covers \CoAuthors_Guest_Authors::is_slug_locked
 */
class GuestAuthorSlugRenameTest extends TestCase {

	/** @var \WP_User */
	private $admin;

	public function set_up() {
		parent::set_up();

		$this->admin = $this->factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin->ID );
	}

	public function tear_down() {
		unset(
			$_POST['guest-author-nonce'],
			$_POST['cap-display_name'],
			$_POST['cap-user_login'],
			$_POST['cap-linked_account']
		);
		parent::tear_down();
	}

	/**
	 * Save the guest author like the edit screen does.
	 *
	 * @param int    $guest_author_id Guest author post ID.
	 * @param string $slug            Value of the Slug field.
	 */
	private function save_from_edit_screen( int $guest_author_id, string $slug ): void {
		$_POST['guest-author-nonce'] = wp_create_nonce( 'guest-author-nonce' );
		$_POST['cap-display_name']   = get_the_title( $guest_author_id );
		$_POST['cap-user_login']     = $slug;

		wp_update_post( array( 'ID' => $guest_author_id ) );
	}

	public function test_renaming_slug_moves_posts_to_the_new_slug(): void {
		$guest_author_id = $this->create_guest_author( 'old-slug' );
		$guest_author    = $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_author_id );
		$post            = $this->create_post( $this->admin );
		$this->_cap->add_coauthors( $post->ID, array( $guest_author->user_login ) );
		$term_before = $this->_cap->get_author_term( $guest_author );

		// Prime the byline cache, it has to be cleared by the rename.
		get_coauthors( $post->ID );

		$this->save_from_edit_screen( $guest_author_id, 'New Slug' );

		$this->assertSame( 'cap-new-slug', get_post( $guest_author_id )->post_name );
		$this->assertSame( 'new-slug', get_post_meta( $guest_author_id, 'cap-user_login', true ) );

		$renamed = $this->_cap->get_coauthor_by( 'user_login', 'new-slug' );
		$this->assertIsObject( $renamed );
		$this->assertSame( $guest_author_id, (int) $renamed->ID );
		$this->assertFalse( $this->_cap->get_coauthor_by( 'user_login', 'old-slug' ) );

		$term_after = $this->_cap->get_author_term( $renamed );
		$this->assertSame( $term_before->term_id, $term_after->term_id, 'The term must be renamed, not replaced.' );
		$this->assertSame( 'cap-new-slug', $term_after->slug );

		$coauthors = get_coauthors( $post->ID );
		$this->assertSame( array( 'new-slug' ), wp_list_pluck( $coauthors, 'user_login' ) );
	}

	public function test_untouched_slug_field_keeps_legacy_slug(): void {
		$guest_author_id = $this->create_guest_author( 'Old.Slug' );
		$term_before     = $this->_cap->get_author_term( $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_author_id ) );

		$this->save_from_edit_screen( $guest_author_id, 'Old.Slug' );

		$this->assertSame( 'Old.Slug', get_post_meta( $guest_author_id, 'cap-user_login', true ) );
		$term_after = $this->_cap->get_author_term( $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_author_id, true ) );
		$this->assertSame( $term_before->term_id, $term_after->term_id );
		$this->assertSame( $term_before->slug, $term_after->slug );
	}

	public function test_slug_used_by_another_guest_author_is_rejected(): void {
		$this->create_guest_author( 'taken' );
		$guest_author_id = $this->create_guest_author( 'renamer' );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'already used' );

		$this->save_from_edit_screen( $guest_author_id, 'taken' );
	}

	public function test_slug_of_a_leftover_author_term_is_rejected(): void {
		wp_insert_term( 'leftover', $this->_cap->coauthor_taxonomy, array( 'slug' => 'cap-leftover' ) );
		$guest_author_id = $this->create_guest_author( 'renamer' );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'already used by another author' );

		$this->save_from_edit_screen( $guest_author_id, 'leftover' );
	}

	public function test_new_guest_author_cannot_take_a_leftover_author_term(): void {
		wp_insert_term( 'leftover', $this->_cap->coauthor_taxonomy, array( 'slug' => 'cap-leftover' ) );
		$draft_id = $this->factory()->post->create(
			array(
				'post_type'   => 'guest-author',
				'post_status' => 'auto-draft',
				'post_title'  => 'New Person',
			)
		);

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'already used by another author' );

		$this->save_from_edit_screen( $draft_id, 'leftover' );
	}

	public function test_new_guest_author_uses_typed_slug(): void {
		$draft_id = $this->factory()->post->create(
			array(
				'post_type'   => 'guest-author',
				'post_status' => 'auto-draft',
				'post_title'  => 'New Person',
			)
		);

		$this->save_from_edit_screen( $draft_id, 'custom-slug' );

		$this->assertSame( 'custom-slug', get_post_meta( $draft_id, 'cap-user_login', true ) );
		$this->assertSame( 'cap-custom-slug', get_post( $draft_id )->post_name );
	}

	public function test_failed_term_rename_keeps_the_old_slug(): void {
		$guest_author_id = $this->create_guest_author( 'stays' );

		// Another request takes the term slug after the conflict check ran.
		$taxonomy = $this->_cap->coauthor_taxonomy;
		$race     = static function () use ( $taxonomy ) {
			wp_insert_term( 'raced', $taxonomy, array( 'slug' => 'cap-raced' ) );
		};
		add_action( 'save_post', $race, 5 );
		$this->save_from_edit_screen( $guest_author_id, 'raced' );
		remove_action( 'save_post', $race, 5 );

		$this->assertSame( 'stays', get_post_meta( $guest_author_id, 'cap-user_login', true ) );
		$this->assertSame( 'cap-stays', get_post( $guest_author_id )->post_name );
		$guest_author = $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_author_id, true );
		$this->assertSame( 'cap-stays', $this->_cap->get_author_term( $guest_author )->slug );
	}

	public function test_slug_matching_linked_account_is_locked(): void {
		$user            = $this->create_author( 'linked-user' );
		$guest_author_id = $this->_cap->guest_authors->create_guest_author_from_user_id( $user->ID );
		$_POST['cap-linked_account'] = (string) $user->ID;

		$this->assertTrue( $this->_cap->guest_authors->is_slug_locked( $guest_author_id ) );

		$this->save_from_edit_screen( $guest_author_id, 'something-else' );

		$this->assertSame( 'linked-user', get_post_meta( $guest_author_id, 'cap-user_login', true ) );
		$this->assertSame( 'cap-linked-user', get_post( $guest_author_id )->post_name );
	}

	public function test_unlinked_guest_author_slug_is_not_locked(): void {
		$guest_author_id = $this->create_guest_author( 'free' );

		$this->assertFalse( $this->_cap->guest_authors->is_slug_locked( $guest_author_id ) );
	}
}
