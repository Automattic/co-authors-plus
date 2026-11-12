<?php
/**
 * Tests for what Co-Authors Plus adds to the Delete Users screen.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Admin;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Plus
 */
class DeleteUsersScreenTest extends TestCase {

	/**
	 * Both hooks are added when the Users screen loads.
	 *
	 * @covers ::load_users_screen
	 */
	public function test_load_users_screen_adds_the_delete_hooks(): void {
		$this->_cap->load_users_screen();

		$this->assertSame( 10, has_filter( 'users_have_additional_content', array( $this->_cap, 'filter_users_have_additional_content' ) ) );
		$this->assertSame( 10, has_action( 'delete_user_form', array( $this->_cap, 'delete_user_form_note' ) ) );
	}

	/**
	 * A user who is only a later co-author has content to reassign.
	 *
	 * @covers ::filter_users_have_additional_content
	 */
	public function test_later_coauthor_has_additional_content(): void {
		$boss    = $this->create_author( 'bossauthor' );
		$later   = $this->create_author( 'laterauthor' );
		$post_id = $this->create_post( $boss )->ID;
		$this->_cap->add_coauthors( $post_id, array( $boss->user_nicename, $later->user_nicename ) );

		$this->assertTrue( $this->_cap->filter_users_have_additional_content( false, array( $later->ID ) ) );
	}

	/**
	 * A byline on a trashed post counts, since reassigning reaches it.
	 *
	 * @covers ::filter_users_have_additional_content
	 */
	public function test_byline_on_trashed_post_counts_as_content(): void {
		$boss    = $this->create_author( 'bossauthor' );
		$later   = $this->create_author( 'laterauthor' );
		$post_id = $this->create_post( $boss )->ID;
		$this->_cap->add_coauthors( $post_id, array( $boss->user_nicename, $later->user_nicename ) );
		wp_trash_post( $post_id );

		$this->assertTrue( $this->_cap->filter_users_have_additional_content( false, array( $later->ID ) ) );
	}

	/**
	 * A user on no byline has no extra content.
	 *
	 * @covers ::filter_users_have_additional_content
	 */
	public function test_user_without_bylines_has_no_additional_content(): void {
		$user = $this->create_author( 'nobylines' );
		$this->_cap->update_author_term( $user );

		$this->assertFalse( $this->_cap->filter_users_have_additional_content( false, array( $user->ID ) ) );
	}

	/**
	 * A value already set by someone else is kept.
	 *
	 * @covers ::filter_users_have_additional_content
	 */
	public function test_existing_true_value_is_kept(): void {
		$user = $this->create_author( 'nobylines' );

		$this->assertTrue( $this->_cap->filter_users_have_additional_content( true, array( $user->ID ) ) );
	}

	/**
	 * The byline of a linked guest author counts, since it's deleted with the user.
	 *
	 * @covers ::filter_users_have_additional_content
	 */
	public function test_linked_guest_author_byline_counts_as_content(): void {
		$user     = $this->create_author( 'linkeduser' );
		$guest_id = $this->_cap->guest_authors->create(
			array(
				'display_name'   => 'Pen Name',
				'user_login'     => 'penname',
				'linked_account' => $user->user_login,
			)
		);
		$guest    = $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_id );
		$post_id  = $this->create_post( $this->create_author( 'bossauthor' ) )->ID;
		$this->_cap->add_coauthors( $post_id, array( 'bossauthor', $guest->user_nicename ) );

		$this->assertTrue( $this->_cap->filter_users_have_additional_content( false, array( $user->ID ) ) );
	}

	/**
	 * A term shared with an unlinked guest author belongs to that guest author, so
	 * its posts are not the user's content.
	 *
	 * @covers ::filter_users_have_additional_content
	 */
	public function test_term_shared_with_unlinked_guest_author_is_not_content(): void {
		$guest_id = $this->create_guest_author( 'penname' );
		$guest    = $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_id );
		$post_id  = $this->create_post( $this->create_author( 'bossauthor' ) )->ID;
		$this->_cap->add_coauthors( $post_id, array( 'bossauthor', $guest->user_nicename ) );
		$user = $this->create_author( 'penname' );

		$this->assertFalse( $this->_cap->filter_users_have_additional_content( false, array( $user->ID ) ) );
	}

	/**
	 * A revision alone doesn't count as content.
	 *
	 * @covers ::filter_users_have_additional_content
	 */
	public function test_revision_with_author_term_is_not_content(): void {
		$user        = $this->create_author( 'revisionauthor' );
		$term        = $this->_cap->update_author_term( $user );
		$revision_id = $this->factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
			)
		);
		wp_set_object_terms( $revision_id, array( (int) $term->term_id ), $this->_cap->coauthor_taxonomy );

		$this->assertFalse( $this->_cap->filter_users_have_additional_content( false, array( $user->ID ) ) );
	}

	/**
	 * The note explains the rules and names the linked guest author, escaped.
	 *
	 * @covers ::delete_user_form_note
	 */
	public function test_note_names_the_linked_guest_author(): void {
		$user = $this->create_author( 'linkeduser' );
		$this->_cap->guest_authors->create(
			array(
				'display_name'   => 'Pen <em>Name</em>',
				'user_login'     => 'penname',
				'linked_account' => $user->user_login,
			)
		);

		ob_start();
		$this->_cap->delete_user_form_note( wp_get_current_user(), array( $user->ID ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'removed from every byline they are on', $output );
		$this->assertStringContainsString( '<strong>linkeduser</strong> is linked to the guest author', $output );
		$this->assertStringNotContainsString( '<em>Name</em>', $output );
	}

	/**
	 * Without a linked guest author, the note has no guest author line.
	 *
	 * @covers ::delete_user_form_note
	 */
	public function test_note_without_linked_guest_author(): void {
		$user = $this->create_author( 'plainuser' );

		ob_start();
		$this->_cap->delete_user_form_note( wp_get_current_user(), array( $user->ID ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'removed from every byline they are on', $output );
		$this->assertStringNotContainsString( 'is linked to the guest author', $output );
	}
}
