<?php
/**
 * Tests for the guest author delete confirmation screen.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers \CoAuthors_Guest_Authors::view_guest_authors_list
 */
class DeleteConfirmationScreenTest extends TestCase {

	/**
	 * @var string
	 */
	private $manage_cap_backup;

	/**
	 * @var array
	 */
	private $get_backup;

	public function set_up() {
		parent::set_up();

		global $coauthors_plus;
		$this->manage_cap_backup = $coauthors_plus->guest_authors->list_guest_authors_cap;
		$this->get_backup        = $_GET;

		// For submit_button().
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}

	public function tear_down() {
		global $coauthors_plus;
		$coauthors_plus->guest_authors->list_guest_authors_cap = $this->manage_cap_backup;
		$_GET = $this->get_backup;

		parent::tear_down();
	}

	/**
	 * Renders the confirmation screen for a guest author created by an
	 * administrator, as the given user, under a manage cap lowered to edit_posts.
	 *
	 * @param \WP_User $user Acting user.
	 *
	 * @return string Screen output.
	 */
	private function render_confirmation_as( \WP_User $user ): string {
		global $coauthors_plus;

		$coauthors_plus->guest_authors->list_guest_authors_cap = 'edit_posts';

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Confirm Me',
				'user_login'   => 'confirm-me',
			)
		);

		wp_set_current_user( $user->ID );
		$_GET = array(
			'action'   => 'delete',
			'id'       => (string) $guest_author_id,
			'_wpnonce' => wp_create_nonce( 'guest-author-delete' ),
		);

		ob_start();
		try {
			$coauthors_plus->guest_authors->view_guest_authors_list();
		} finally {
			$output = ob_get_clean();
		}

		return $output;
	}

	public function test_confirmation_screen_dies_without_delete_post(): void {
		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( esc_html__( "You don't have permission to delete this guest author.", 'co-authors-plus' ) );

		$this->render_confirmation_as( $this->create_author( 'confirm-author' ) );
	}

	public function test_confirmation_screen_renders_with_delete_post(): void {
		$output = $this->render_confirmation_as( $this->create_editor( 'confirm-editor' ) );

		$this->assertStringContainsString( 'value="delete-guest-author"', $output );
	}
}
