<?php
/**
 * Tests for the AJAX search behind the "Reassign to another co-author" field
 * on the delete guest author screen.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use WPAjaxDieContinueException;

/**
 * @covers \CoAuthors_Guest_Authors::handle_ajax_search_coauthors_to_assign
 */
class SearchCoauthorsToAssignTest extends \WP_Ajax_UnitTestCase {

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		unset( $_GET['term'], $_GET['guest_author'] );

		parent::tear_down();
	}

	/**
	 * The guest author being deleted is left out of the results even when its
	 * login differs from its nicename, because search_authors() ignores by
	 * nicename.
	 */
	public function test_excludes_guest_author_whose_login_differs_from_nicename(): void {
		global $coauthors_plus;

		$deleting = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Reassign Deleting',
				'user_login'   => 'Reassign Deleting',
			)
		);
		$coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Reassign Target',
				'user_login'   => 'reassign-target',
			)
		);

		$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'ID', $deleting );
		$this->assertNotSame( $guest_author->user_login, $guest_author->user_nicename );

		$_GET['term']         = 'Reassign';
		$_GET['guest_author'] = (string) $deleting;

		try {
			$this->_handleAjax( 'search_coauthors_to_assign' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$values = wp_list_pluck( json_decode( $this->_last_response, true ), 'value' );

		$this->assertSame( array( 'reassign-target' ), $values );
	}
}
