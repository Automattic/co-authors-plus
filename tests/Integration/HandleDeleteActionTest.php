<?php
/**
 * Tests for CoAuthors_Guest_Authors::handle_delete_guest_author_action().
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration;

/**
 * @coversDefaultClass \CoAuthors_Guest_Authors
 */
class HandleDeleteActionTest extends TestCase {

	use \Yoast\PHPUnitPolyfills\Polyfills\AssertStringContains;

	private $admin1;
	private $author1;
	private $editor1;

	/**
	 * Backup of $_POST taken in start_delete_request().
	 *
	 * @var array
	 */
	private $post_backup;

	/**
	 * Backup of the current user ID taken in start_delete_request().
	 *
	 * @var int
	 */
	private $current_user_backup;

	public function set_up() {
		parent::set_up();

		$this->admin1  = $this->factory()->user->create_and_get(
			array(
				'role'       => 'administrator',
				'user_login' => 'admin1',
			)
		);
		$this->author1 = $this->create_author( 'author1' );
		$this->editor1 = $this->create_editor( 'editor1' );

		// Authoring a post assigns author1 as a co-author, which creates their author
		// term. The reassign-another test reassigns to author1, which only works when
		// author1 is a registered co-author.
		$this->create_post( $this->author1 );
	}

	/**
	 * Backs up $_POST and the current user, sets admin1 as the acting user and
	 * populates the common delete-guest-author $_POST fields.
	 *
	 * @param string $reassign The reassign mode to set, or '' to leave it unset.
	 *
	 * @return int The created guest author ID (set on $_POST['id']).
	 */
	private function start_delete_request( string $reassign = '' ): int {

		global $coauthors_plus;

		$this->post_backup         = $_POST;
		$this->current_user_backup = get_current_user_id();

		wp_set_current_user( $this->admin1->ID );

		$_POST['action']   = 'delete-guest-author';
		$_POST['_wpnonce'] = wp_create_nonce( 'delete-guest-author' );
		$_POST['id']       = $coauthors_plus->guest_authors->create_guest_author_from_user_id( $this->admin1->ID );

		if ( '' !== $reassign ) {
			$_POST['reassign'] = $reassign;
		}

		return (int) $_POST['id'];
	}

	/**
	 * Restores $_POST and the current user from the backups taken in start_delete_request().
	 *
	 * @return void
	 */
	private function restore_delete_request(): void {

		wp_set_current_user( $this->current_user_backup );

		$_POST = $this->post_backup;
	}

	/**
	 * To catch any redirection and throw location and status in Exception.
	 *
	 * Note : Destination location can be got from Exception Message and
	 * status can be got from Exception code.
	 *
	 * @param string $location Redirected location.
	 * @param int    $status   Status.
	 *
	 * @throws \Exception Redirection data.
	 *
	 * @return void
	 **/
	public function catch_redirect_destination( $location, $status ): void {

		throw new \Exception( $location, $status );
	}

	/**
	 * Checks delete guest author action when $_POST args are not set.
	 *
	 * @covers CoAuthors_Guest_Authors::handle_delete_guest_author_action()
	 */
	public function test_handle_delete_guest_author_action_when_post_args_not_as_expected(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Checks when nothing is set.
		$this->assertNull( $guest_author_obj->handle_delete_guest_author_action() );

		// Back up $_POST.
		$_post_backup = $_POST;

		// Checks when action is set but not expected.
		$_POST['action'] = 'test';
		$_POST['id']     = $guest_author_obj->create_guest_author_from_user_id( $this->editor1->ID );

		$this->assertNull( $guest_author_obj->handle_delete_guest_author_action() );

		// Get guest author and check that it should not be removed.
		$guest_author = $guest_author_obj->get_guest_author_by( 'ID', $_POST['id'] );

		$this->assertNotEmpty( $guest_author );

		// Checks when _wpnonce and id not set.
		$_POST['action']   = 'delete-guest-author';
		$_POST['reassign'] = 'test';

		$this->assertNull( $guest_author_obj->handle_delete_guest_author_action() );

		// Get guest author and check that it should not be removed.
		$guest_author = $guest_author_obj->get_guest_author_by( 'ID', $_POST['id'] );

		$this->assertNotEmpty( $guest_author );

		// Checks when all args set for $_POST but action is not as expected.
		$_POST['action']   = 'test';
		$_POST['reassign'] = 'test';
		$_POST['_wpnonce'] = wp_create_nonce( 'delete-guest-author-1' );

		$this->assertNull( $guest_author_obj->handle_delete_guest_author_action() );

		// Get guest author and check that it should not be removed.
		$guest_author = $guest_author_obj->get_guest_author_by( 'ID', $_POST['id'] );

		$this->assertNotEmpty( $guest_author );

		// Restore $_POST from back up.
		$_POST = $_post_backup;
	}

	/**
	 * Checks delete guest author action with nonce.
	 *
	 * @covers CoAuthors_Guest_Authors::handle_delete_guest_author_action()
	 */
	public function test_handle_delete_guest_author_action_with_nonce(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Back up $_POST.
		$_post_backup = $_POST;

		$expected = __( "Doin' something fishy, huh?", 'co-authors-plus' );

		$_POST['action']   = 'delete-guest-author';
		$_POST['reassign'] = 'test';
		$_POST['id']       = '0';

		// Checks when nonce is not as expected.
		$_POST['_wpnonce'] = wp_create_nonce( 'delete-guest-author-1' );

		try {
			$guest_author_obj->handle_delete_guest_author_action();
		} catch ( \Exception $e ) {
			$exception = $e;
		}

		$this->assertInstanceOf( \WPDieException::class, $exception );
		$this->assertStringContainsString( esc_html( $expected ), $exception->getMessage() );

		// Checks when nonce is as expected.
		$_POST['_wpnonce'] = wp_create_nonce( 'delete-guest-author' );

		try {
			$guest_author_obj->handle_delete_guest_author_action();
		} catch ( \Exception $e ) {
			$exception = $e;
		}

		$this->assertStringNotContainsString( esc_html( $expected ), $exception->getMessage() );

		// Restore $_POST from back up.
		$_POST = $_post_backup;
	}

	/**
	 * Checks delete guest author action with list_author capability.
	 *
	 * @covers CoAuthors_Guest_Authors::handle_delete_guest_author_action()
	 */
	public function test_handle_delete_guest_author_action_with_list_users_capability(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Back up $_POST.
		$_post_backup = $_POST;

		$expected = __( "You don't have permission to perform this action.", 'co-authors-plus' );

		// Back up current user.
		$current_user = get_current_user_id();

		wp_set_current_user( $this->editor1->ID );

		$_POST['action']   = 'delete-guest-author';
		$_POST['reassign'] = 'test';

		// Checks when current user can not have list_users capability.
		$_POST['_wpnonce'] = wp_create_nonce( 'delete-guest-author' );
		$_POST['id']       = $guest_author_obj->create_guest_author_from_user_id( $this->editor1->ID );

		try {
			$guest_author_obj->handle_delete_guest_author_action();
		} catch ( \Exception $e ) {
			$exception = $e;
		}

		$this->assertInstanceOf( \WPDieException::class, $exception );
		$this->assertStringContainsString( esc_html( $expected ), $exception->getMessage() );

		// Checks when current user has list_users capability.
		wp_set_current_user( $this->admin1->ID );

		$_POST['_wpnonce'] = wp_create_nonce( 'delete-guest-author' );
		$_POST['id']       = $guest_author_obj->create_guest_author_from_user_id( $this->admin1->ID );

		try {
			$guest_author_obj->handle_delete_guest_author_action();
		} catch ( \Exception $e ) {
			$exception = $e;
		}

		$this->assertStringNotContainsString( esc_html( $expected ), $exception->getMessage() );

		// Restore current user from backup.
		wp_set_current_user( $current_user );

		// Restore $_POST from back up.
		$_POST = $_post_backup;
	}

	/**
	 * Checks delete guest author action with guest author.
	 *
	 * @covers CoAuthors_Guest_Authors::handle_delete_guest_author_action()
	 */
	public function test_handle_delete_guest_author_action_with_guest_author_existence(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Back up $_POST.
		$_post_backup = $_POST;

		$expected = __( "Guest author can't be deleted because it doesn't exist.", 'co-authors-plus' );

		// Back up current user.
		$current_user = get_current_user_id();

		wp_set_current_user( $this->admin1->ID );

		$_POST['action']   = 'delete-guest-author';
		$_POST['reassign'] = 'test';
		$_POST['_wpnonce'] = wp_create_nonce( 'delete-guest-author' );
		$_POST['id']       = $this->admin1->ID;

		// Checks when guest author does not exist.
		try {
			$guest_author_obj->handle_delete_guest_author_action();
		} catch ( \Exception $e ) {
			$exception = $e;
		}

		$this->assertInstanceOf( \WPDieException::class, $exception );
		$this->assertStringContainsString( esc_html( $expected ), $exception->getMessage() );

		// Checks when guest author exists.
		$_POST['id'] = $guest_author_obj->create_guest_author_from_user_id( $this->admin1->ID );

		try {
			$guest_author_obj->handle_delete_guest_author_action();
		} catch ( \Exception $e ) {
			$exception = $e;
		}

		$this->assertStringNotContainsString( esc_html( $expected ), $exception->getMessage() );

		// Restore current user from backup.
		wp_set_current_user( $current_user );

		// Restore $_POST from back up.
		$_POST = $_post_backup;
	}

	/**
	 * Checks delete guest author action with reassign not as expected.
	 *
	 * @covers CoAuthors_Guest_Authors::handle_delete_guest_author_action()
	 */
	public function test_handle_delete_guest_author_action_with_reassign_not_as_expected(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$expected = __( 'Please make sure to pick an option.', 'co-authors-plus' );

		// Checks when reassign is not as expected.
		$this->start_delete_request( 'test' );

		try {
			$guest_author_obj->handle_delete_guest_author_action();
		} catch ( \Exception $e ) {
			$exception = $e;
		}

		$this->assertInstanceOf( \WPDieException::class, $exception );
		$this->assertStringContainsString( esc_html( $expected ), $exception->getMessage() );

		$this->restore_delete_request();
	}

	/**
	 * Checks delete guest author action when reassign is leave-assigned.
	 *
	 * @covers CoAuthors_Guest_Authors::handle_delete_guest_author_action()
	 */
	public function test_handle_delete_guest_author_action_with_reassign_is_leave_assigned(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$this->start_delete_request( 'leave-assigned' );

		add_filter( 'wp_redirect', array( $this, 'catch_redirect_destination' ), 99, 2 );

		$exception = null;
		try {

			$guest_author_obj->handle_delete_guest_author_action();

		} catch ( \Exception $e ) {

			$exception = $e;
		}

		$this->assertNotNull( $exception, 'Deleting a guest author must redirect via the wp_redirect filter.' );
		$this->assertStringContainsString( $guest_author_obj->parent_page, $exception->getMessage() );
		$this->assertStringContainsString( 'page=view-guest-authors', $exception->getMessage() );
		$this->assertStringContainsString( 'message=guest-author-deleted', $exception->getMessage() );

		remove_filter( 'wp_redirect', array( $this, 'catch_redirect_destination' ), 99 );

		$this->restore_delete_request();
	}

	/**
	 * Checks delete guest author action when reassign is reassign-another.
	 *
	 * @covers CoAuthors_Guest_Authors::handle_delete_guest_author_action()
	 */
	public function test_handle_delete_guest_author_action_with_reassign_is_reassign_another(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$expected = __( 'Co-author does not exists. Try again?', 'co-authors-plus' );

		$this->start_delete_request( 'reassign-another' );

		// When coauthor does not exist.
		$_POST['leave-assigned-to'] = 'test';

		try {
			$guest_author_obj->handle_delete_guest_author_action();
		} catch ( \Exception $e ) {
			$exception = $e;
		}

		$this->assertInstanceOf( \WPDieException::class, $exception );
		$this->assertStringContainsString( esc_html( $expected ), $exception->getMessage() );

		// When coauthor exists.
		$_POST['leave-assigned-to'] = $this->author1->user_nicename;

		add_filter( 'wp_redirect', array( $this, 'catch_redirect_destination' ), 99, 2 );

		$exception = null;
		try {

			$guest_author_obj->handle_delete_guest_author_action();

		} catch ( \Exception $e ) {

			$exception = $e;
		}

		$this->assertNotNull( $exception, 'Reassigning to an existing co-author must redirect via the wp_redirect filter.' );
		$this->assertStringContainsString( 'page=view-guest-authors', $exception->getMessage() );
		$this->assertStringContainsString( 'message=guest-author-deleted', $exception->getMessage() );

		remove_filter( 'wp_redirect', array( $this, 'catch_redirect_destination' ), 99 );

		$this->restore_delete_request();
	}

	/**
	 * Checks delete guest author action when reassign is remove-byline.
	 *
	 * @covers CoAuthors_Guest_Authors::handle_delete_guest_author_action()
	 */
	public function test_handle_delete_guest_author_action_with_reassign_is_remove_byline(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$this->start_delete_request( 'remove-byline' );

		add_filter( 'wp_redirect', array( $this, 'catch_redirect_destination' ), 99, 2 );

		$exception = null;
		try {

			$guest_author_obj->handle_delete_guest_author_action();

		} catch ( \Exception $e ) {

			$exception = $e;
		}

		$this->assertNotNull( $exception, 'Deleting a guest author must redirect via the wp_redirect filter.' );
		$this->assertStringContainsString( $guest_author_obj->parent_page, $exception->getMessage() );
		$this->assertStringContainsString( 'page=view-guest-authors', $exception->getMessage() );
		$this->assertStringContainsString( 'message=guest-author-deleted', $exception->getMessage() );

		remove_filter( 'wp_redirect', array( $this, 'catch_redirect_destination' ), 99 );

		$this->restore_delete_request();
	}
}
