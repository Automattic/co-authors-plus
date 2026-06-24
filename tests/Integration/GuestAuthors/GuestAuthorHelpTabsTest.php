<?php
/**
 * Tests for the guest-author admin help tabs.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers \CoAuthors_Guest_Authors::add_help_tabs
 */
class GuestAuthorHelpTabsTest extends TestCase {

	private $previous_screen;

	public function set_up() {
		parent::set_up();
		$this->previous_screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	}

	public function tear_down() {
		if ( $this->previous_screen instanceof \WP_Screen ) {
			set_current_screen( $this->previous_screen );
		} else {
			set_current_screen( 'front' );
		}
		parent::tear_down();
	}

	public function test_help_tabs_registered_on_list_screen(): void {
		global $coauthors_plus;

		$screen = \WP_Screen::get( 'users_page_view-guest-authors' );
		set_current_screen( $screen );

		$coauthors_plus->guest_authors->add_help_tabs( $screen );

		$ids = wp_list_pluck( $screen->get_help_tabs(), 'id' );

		$this->assertContains( 'co-authors-plus-overview', $ids );
		$this->assertContains( 'co-authors-plus-linking', $ids );
		$this->assertContains( 'co-authors-plus-bylines', $ids );
	}

	public function test_help_tabs_registered_on_edit_screen(): void {
		global $coauthors_plus;

		$screen = \WP_Screen::get( 'guest-author' );
		set_current_screen( $screen );

		$coauthors_plus->guest_authors->add_help_tabs( $screen );

		$ids = wp_list_pluck( $screen->get_help_tabs(), 'id' );

		$this->assertContains( 'co-authors-plus-overview', $ids );
		$this->assertContains( 'co-authors-plus-linked-account', $ids );
		$this->assertContains( 'co-authors-plus-deleting', $ids );
	}

	public function test_no_help_tabs_on_unrelated_screen(): void {
		global $coauthors_plus;

		$screen = \WP_Screen::get( 'edit-post' );
		set_current_screen( $screen );

		$existing_ids = wp_list_pluck( $screen->get_help_tabs(), 'id' );

		$coauthors_plus->guest_authors->add_help_tabs( $screen );

		$ids = wp_list_pluck( $screen->get_help_tabs(), 'id' );

		$this->assertSame( $existing_ids, $ids );
	}
}
