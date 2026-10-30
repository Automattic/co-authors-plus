<?php
/**
 * Tests for how CoAuthors_WP_List_Table::prepare_items() reads its request parameters.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_WP_List_Table
 */
class ListTablePrepareItemsTest extends TestCase {

	/**
	 * Backup of $_REQUEST, restored in tear_down().
	 *
	 * @var array
	 */
	private $request_backup;

	public function set_up() {
		parent::set_up();

		$this->request_backup = $_REQUEST;

		// WP_List_Table resolves its screen from the admin page hook.
		$GLOBALS['hook_suffix'] = 'users_page_view-guest-authors';

		global $coauthors_plus;
		foreach ( array( 'Ada Byron', 'Grace Hopper', 'Alan Turing' ) as $name ) {
			list( $first_name, $last_name ) = explode( ' ', $name );
			$coauthors_plus->guest_authors->create(
				array(
					'display_name' => $name,
					'user_login'   => strtolower( $last_name ),
					'first_name'   => $first_name,
					'last_name'    => $last_name,
				)
			);
		}
	}

	public function tear_down() {
		$_REQUEST = $this->request_backup;
		unset( $GLOBALS['hook_suffix'] );

		parent::tear_down();
	}

	/**
	 * Runs prepare_items() for the given request and returns the listed display names.
	 *
	 * @param array $request Request parameters.
	 * @return string[]
	 */
	private function list_display_names( array $request ): array {
		$_REQUEST = $request;

		$table = new \CoAuthors_WP_List_Table();
		$table->prepare_items();

		return wp_list_pluck( $table->items, 'display_name' );
	}

	/**
	 * @covers ::prepare_items
	 */
	public function test_sorts_by_a_name_field_in_the_requested_direction(): void {
		$this->assertSame(
			array( 'Alan Turing', 'Grace Hopper', 'Ada Byron' ),
			$this->list_display_names(
				array(
					'orderby' => 'last_name',
					'order'   => 'desc',
				)
			)
		);
	}

	/**
	 * @covers ::prepare_items
	 */
	public function test_applies_a_known_filter(): void {
		global $coauthors_plus;

		$user = $this->create_author( 'linked_author' );
		$coauthors_plus->guest_authors->create_guest_author_from_user_id( $user->ID );

		$table    = new \CoAuthors_WP_List_Table();
		$_REQUEST = array( 'filter' => 'with-linked-account' );
		$table->prepare_items();

		$this->assertSame( 'with-linked-account', $table->active_filter );
		$this->assertSame( array( $user->display_name ), wp_list_pluck( $table->items, 'display_name' ) );
	}

	/**
	 * Array values used to reach strtoupper() and array_key_exists() and fatal.
	 *
	 * @covers ::prepare_items
	 */
	public function test_array_parameters_fall_back_to_the_defaults(): void {
		$_REQUEST = array(
			'orderby' => array( 'last_name' ),
			'order'   => array( 'DESC' ),
			'filter'  => array( 'with-linked-account' ),
		);

		$table = new \CoAuthors_WP_List_Table();
		$table->prepare_items();

		$this->assertSame( 'show-all', $table->active_filter );
		$this->assertSame(
			array( 'Ada Byron', 'Alan Turing', 'Grace Hopper' ),
			wp_list_pluck( $table->items, 'display_name' )
		);
	}
}
