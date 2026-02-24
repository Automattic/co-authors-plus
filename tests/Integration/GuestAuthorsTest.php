<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

class GuestAuthorsTest extends TestCase {

	use \Yoast\PHPUnitPolyfills\Polyfills\AssertStringContains;

	private $admin1;
	private $author1;
	private $editor1;
	private $post;

	public function set_up() {

		parent::set_up();

		$this->admin1  = $this->factory()->user->create_and_get(
			array(
				'role'       => 'administrator',
				'user_login' => 'admin1',
			)
		);
		$this->author1 = $this->factory()->user->create_and_get(
			array(
				'role'       => 'author',
				'user_login' => 'author1',
			)
		);
		$this->editor1 = $this->factory()->user->create_and_get(
			array(
				'role'       => 'editor',
				'user_login' => 'editor1',
			)
		);

		$this->post = $this->factory()->post->create_and_get(
			array(
				'post_author'  => $this->author1->ID,
				'post_status'  => 'publish',
				'post_content' => rand_str(),
				'post_title'   => rand_str(),
				'post_type'    => 'post',
			)
		);
	}

	/**
	 * Checks a simulated WP_User object based on the post ID when key or value is empty.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_by()
	 */
	public function test_get_guest_author_by_with_empty_key_or_value(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Fetch guest author without forcefully.
		$this->assertFalse( $guest_author_obj->get_guest_author_by( '', '' ) );
		$this->assertFalse( $guest_author_obj->get_guest_author_by( 'ID', '' ) );
		$this->assertFalse( $guest_author_obj->get_guest_author_by( '', $this->author1->ID ) );

		// Fetch guest author forcefully.
		$this->assertFalse( $guest_author_obj->get_guest_author_by( '', '', true ) );
		$this->assertFalse( $guest_author_obj->get_guest_author_by( 'ID', '', true ) );
		$this->assertFalse( $guest_author_obj->get_guest_author_by( '', $this->author1->ID, true ) );
	}

	/**
	 * Checks a simulated WP_User object based on the post ID using cache.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_by()
	 */
	public function test_get_guest_author_by_using_cache(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$guest_author_id = $guest_author_obj->create_guest_author_from_user_id( $this->editor1->ID );

		$cache_key = $guest_author_obj->get_cache_key( 'ID', $guest_author_id );

		// Checks when guest author does not exist in cache.
		$this->assertFalse( wp_cache_get( $cache_key, $guest_author_obj::$cache_group ) );

		// Checks when guest author exists in cache.
		$guest_author        = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );
		$guest_author_cached = wp_cache_get( $cache_key, $guest_author_obj::$cache_group );

		$this->assertInstanceOf( \stdClass::class, $guest_author );
		$this->assertEquals( $guest_author, $guest_author_cached );
	}

	/**
	 * Checks a simulated WP_User object based on the post ID using different key/value.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_by()
	 */
	public function test_get_guest_author_by_with_different_keys(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Checks when user is not a guest author.
		$this->assertFalse( $guest_author_obj->get_guest_author_by( 'ID', $this->author1->ID ) );
		$this->assertFalse( $guest_author_obj->get_guest_author_by( 'ID', $this->author1->ID, true ) );

		$guest_author_id = $guest_author_obj->create_guest_author_from_user_id( $this->editor1->ID );

		// Checks guest author using ID.
		$guest_author = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );

		$this->assertInstanceOf( \stdClass::class, $guest_author );
		$this->assertEquals( $guest_author_id, $guest_author->ID );
		$this->assertEquals( $guest_author_obj->post_type, $guest_author->type );

		// Checks guest author using user_nicename.
		$guest_author = $guest_author_obj->get_guest_author_by( 'user_nicename', $this->editor1->user_nicename );

		$this->assertInstanceOf( \stdClass::class, $guest_author );
		$this->assertEquals( $guest_author_obj->post_type, $guest_author->type );

		// Checks guest author using linked_account.
		$guest_author = $guest_author_obj->get_guest_author_by( 'linked_account', $this->editor1->user_login );

		$this->assertInstanceOf( \stdClass::class, $guest_author );
		$this->assertEquals( $guest_author_obj->post_type, $guest_author->type );
	}

	/**
	 * Checks thumbnail for a guest author object.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_thumbnail()
	 */
	public function test_get_guest_author_thumbnail(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Checks when guest author does not have any thumbnail.
		$guest_author_id = $guest_author_obj->create(
			array(
				'user_login'   => 'author2',
				'display_name' => 'author2',
			)
		);
		$guest_author    = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );

		$this->assertNull( $guest_author_obj->get_guest_author_thumbnail( $guest_author, 0 ) );

		$attachment_id = $this->factory()->attachment->create_upload_object( __DIR__ . '/fixtures/dummy-attachment.png' );

		set_post_thumbnail( $guest_author->ID, $attachment_id );

		$thumbnail = $guest_author_obj->get_guest_author_thumbnail( $guest_author, 0 );

		$this->assertStringContainsString( 'avatar-0', $thumbnail );
		// Checking for dummy-attachment instead of dummy-attachment.png, as filename might change to
		// dummy-attachment-1.png, dummy-attachment-2.png, etc. when running multiple tests.
		$this->assertStringContainsString( 'dummy-attachment', $thumbnail );
		$this->assertStringContainsString( wp_get_attachment_url( $attachment_id ), $thumbnail );
	}

	/**
	 * Checks all the meta fields that can be associated with a guest author.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_fields()
	 */
	public function test_get_guest_author_fields(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Checks all the meta fields.
		$fields = $guest_author_obj->get_guest_author_fields();

		$this->assertNotEmpty( $fields );
		$this->assertIsArray( $fields );

		$keys = wp_list_pluck( $fields, 'key' );

		$global_fields = array(
			'display_name',
			'first_name',
			'last_name',
			'user_login',
			'user_email',
			'linked_account',
			'website',
			'description',
		);

		$this->assertEquals( $global_fields, $keys );

		// Checks all the meta fields with group that does not exist.
		$fields = $guest_author_obj->get_guest_author_fields( 'test' );

		$this->assertEmpty( $fields );

		// Checks all the meta fields with group "name".
		$fields = $guest_author_obj->get_guest_author_fields( 'name' );
		$keys   = wp_list_pluck( $fields, 'key' );

		$this->assertEquals( array( 'display_name', 'first_name', 'last_name' ), $keys );

		// Checks all the meta fields with group "slug".
		$fields = $guest_author_obj->get_guest_author_fields( 'slug' );
		$keys   = wp_list_pluck( $fields, 'key' );

		$this->assertEquals( array( 'user_login', 'linked_account' ), $keys );

		// Checks all the meta fields with group "contact-info".
		$fields = $guest_author_obj->get_guest_author_fields( 'contact-info' );
		$keys   = wp_list_pluck( $fields, 'key' );

		$this->assertEquals( array( 'user_email', 'website' ), $keys );

		// Checks all the meta fields with group "about".
		$fields = $guest_author_obj->get_guest_author_fields( 'about' );
		$keys   = wp_list_pluck( $fields, 'key' );

		$this->assertEquals( array( 'description' ), $keys );
	}

	/**
	 * Checks all the user accounts that have been linked.
	 *
	 * @covers CoAuthors_Guest_Authors::get_all_linked_accounts()
	 */
	public function test_get_all_linked_accounts(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$this->assertEmpty( $guest_author_obj->get_all_linked_accounts() );

		// Checks when guest author ( not linked account ) exists.
		$guest_author_obj->create(
			array(
				'user_login'   => 'author2',
				'display_name' => 'author2',
			)
		);

		$this->assertEmpty( $guest_author_obj->get_all_linked_accounts() );

		// Create guest author from existing user and check.
		$guest_author_obj->create_guest_author_from_user_id( $this->editor1->ID );

		$linked_accounts    = $guest_author_obj->get_all_linked_accounts();
		$linked_account_ids = wp_list_pluck( $linked_accounts, 'ID' );

		$this->assertNotEmpty( $linked_accounts );
		$this->assertIsArray( $linked_accounts );
		$this->assertContains( $this->editor1->ID, $linked_account_ids );
	}

	/**
	 * Checks all the user accounts that have been linked using cache.
	 *
	 * @covers CoAuthors_Guest_Authors::get_all_linked_accounts()
	 */
	public function test_get_all_linked_accounts_with_cache(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$cache_key = 'all-linked-accounts';

		// Checks when guest author does not exist in cache.
		$this->assertFalse( wp_cache_get( $cache_key, $guest_author_obj::$cache_group ) );

		// Checks when guest author exists in cache.
		$guest_author_obj->create_guest_author_from_user_id( $this->editor1->ID );

		$linked_accounts       = $guest_author_obj->get_all_linked_accounts();
		$linked_accounts_cache = wp_cache_get( $cache_key, $guest_author_obj::$cache_group );

		$this->assertEquals( $linked_accounts, $linked_accounts_cache );
	}

	/**
	 * Checks guest author from an existing WordPress user.
	 *
	 * @covers CoAuthors_Guest_Authors::create_guest_author_from_user_id()
	 */
	public function test_create_guest_author_from_user_id(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Checks create guest author when user don't exist.
		$response = $guest_author_obj->create_guest_author_from_user_id( 0 );

		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'invalid-user', $response->get_error_code() );

		// Checks create guest author when user exist.
		$guest_author_id = $guest_author_obj->create_guest_author_from_user_id( $this->editor1->ID );
		$guest_author    = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );

		$this->assertInstanceOf( \stdClass::class, $guest_author );
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

		// Back up $_POST.
		$_post_backup = $_POST;

		$expected = __( 'Please make sure to pick an option.', 'co-authors-plus' );

		// Back up current user.
		$current_user = get_current_user_id();

		wp_set_current_user( $this->admin1->ID );

		$_POST['action']   = 'delete-guest-author';
		$_POST['_wpnonce'] = wp_create_nonce( 'delete-guest-author' );
		$_POST['id']       = $guest_author_obj->create_guest_author_from_user_id( $this->admin1->ID );

		// Checks when reassign is not as expected.
		$_POST['reassign'] = 'test';

		try {
			$guest_author_obj->handle_delete_guest_author_action();
		} catch ( \Exception $e ) {
			$exception = $e;
		}

		$this->assertInstanceOf( \WPDieException::class, $exception );
		$this->assertStringContainsString( esc_html( $expected ), $exception->getMessage() );

		// Restore current user from backup.
		wp_set_current_user( $current_user );

		// Restore $_POST from back up.
		$_POST = $_post_backup;
	}

	/**
	 * Checks delete guest author action when reassign is leave-assigned.
	 *
	 * @covers CoAuthors_Guest_Authors::handle_delete_guest_author_action()
	 */
	public function test_handle_delete_guest_author_action_with_reassign_is_leave_assigned(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Back up $_POST.
		$_post_backup = $_POST;

		// Back up current user.
		$current_user = get_current_user_id();

		wp_set_current_user( $this->admin1->ID );

		$_POST['action']   = 'delete-guest-author';
		$_POST['_wpnonce'] = wp_create_nonce( 'delete-guest-author' );
		$_POST['id']       = $guest_author_obj->create_guest_author_from_user_id( $this->admin1->ID );
		$_POST['reassign'] = 'leave-assigned';

		add_filter( 'wp_redirect', array( $this, 'catch_redirect_destination' ), 99, 2 );

		try {

			$guest_author_obj->handle_delete_guest_author_action();

		} catch ( \Exception $e ) {

			$this->assertStringContainsString( $guest_author_obj->parent_page, $e->getMessage() );
			$this->assertStringContainsString( 'page=view-guest-authors', $e->getMessage() );
			$this->assertStringContainsString( 'message=guest-author-deleted', $e->getMessage() );
		}

		remove_filter( 'wp_redirect', array( $this, 'catch_redirect_destination' ), 99 );

		// Restore current user from backup.
		wp_set_current_user( $current_user );

		// Restore $_POST from back up.
		$_POST = $_post_backup;
	}

	/**
	 * Checks delete guest author action when reassign is reassign-another.
	 *
	 * @covers CoAuthors_Guest_Authors::handle_delete_guest_author_action()
	 */
	public function test_handle_delete_guest_author_action_with_reassign_is_reassign_another(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Back up $_POST.
		$_post_backup = $_POST;

		// Back up current user.
		$current_user = get_current_user_id();

		$expected = __( 'Co-author does not exists. Try again?', 'co-authors-plus' );

		wp_set_current_user( $this->admin1->ID );

		$_POST['action']   = 'delete-guest-author';
		$_POST['_wpnonce'] = wp_create_nonce( 'delete-guest-author' );
		$_POST['id']       = $guest_author_obj->create_guest_author_from_user_id( $this->admin1->ID );
		$_POST['reassign'] = 'reassign-another';

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

		try {

			$guest_author_obj->handle_delete_guest_author_action();

		} catch ( \Exception $e ) {

			// $this->assertStringContainsString( $guest_author_obj->parent_page, $e->getMessage() );
			$this->assertStringContainsString( 'page=view-guest-authors', $e->getMessage() );
			$this->assertStringContainsString( 'message=guest-author-deleted', $e->getMessage() );
		}

		remove_filter( 'wp_redirect', array( $this, 'catch_redirect_destination' ), 99 );

		// Restore current user from backup.
		wp_set_current_user( $current_user );

		// Restore $_POST from back up.
		$_POST = $_post_backup;
	}

	/**
	 * Checks delete guest author action when reassign is remove-byline.
	 *
	 * @covers CoAuthors_Guest_Authors::handle_delete_guest_author_action()
	 */
	public function test_handle_delete_guest_author_action_with_reassign_is_remove_byline(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Back up $_POST.
		$_post_backup = $_POST;

		// Back up current user.
		$current_user = get_current_user_id();

		wp_set_current_user( $this->admin1->ID );

		$_POST['action']   = 'delete-guest-author';
		$_POST['_wpnonce'] = wp_create_nonce( 'delete-guest-author' );
		$_POST['id']       = $guest_author_obj->create_guest_author_from_user_id( $this->admin1->ID );
		$_POST['reassign'] = 'remove-byline';

		add_filter( 'wp_redirect', array( $this, 'catch_redirect_destination' ), 99, 2 );

		try {

			$guest_author_obj->handle_delete_guest_author_action();

		} catch ( \Exception $e ) {

			$this->assertStringContainsString( $guest_author_obj->parent_page, $e->getMessage() );
			$this->assertStringContainsString( 'page=view-guest-authors', $e->getMessage() );
			$this->assertStringContainsString( 'message=guest-author-deleted', $e->getMessage() );
		}

		remove_filter( 'wp_redirect', array( $this, 'catch_redirect_destination' ), 99 );

		// Restore current user from backup.
		wp_set_current_user( $current_user );

		// Restore $_POST from back up.
		$_POST = $_post_backup;
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
	 * Checks delete guest author when he/she does not exist.
	 *
	 * @covers CoAuthors_Guest_Authors::delete()
	 */
	public function test_delete_when_guest_author_not_exist(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$response = $guest_author_obj->delete( $this->admin1->ID );

		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'guest-author-missing', $response->get_error_code() );
	}

	/**
	 * Checks delete guest author without reassign author.
	 *
	 * @covers CoAuthors_Guest_Authors::delete()
	 */
	public function test_delete_without_reassign(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$author2           = $this->factory()->user->create_and_get();
		$guest_author_id   = $guest_author_obj->create_guest_author_from_user_id( $author2->ID );
		$guest_author      = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );
		$guest_author_term = $coauthors_plus->get_author_term( $guest_author );

		$response = $guest_author_obj->delete( $guest_author_id );

		$this->assertTrue( $response );
		$this->assertFalse( get_term_by( 'id', $guest_author_term->term_id, $coauthors_plus->coauthor_taxonomy ) );
		$this->assertNull( get_post( $guest_author_id ) );
	}

	/**
	 * Checks delete guest author with reassign author but he/she does not exist.
	 *
	 * @covers CoAuthors_Guest_Authors::delete()
	 */
	public function test_delete_with_reassign_author_not_exist(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Checks when reassign author is not exist.
		$author2         = $this->factory()->user->create_and_get();
		$guest_author_id = $guest_author_obj->create_guest_author_from_user_id( $author2->ID );

		$response = $guest_author_obj->delete( $guest_author_id, 'test' );

		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'reassign-to-missing', $response->get_error_code() );
	}

	/**
	 * Checks delete guest author with reassign author when linked account and author are same user.
	 *
	 * @covers CoAuthors_Guest_Authors::delete()
	 */
	public function test_delete_with_reassign_when_linked_account_and_author_are_same_user(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$author2            = $this->factory()->user->create_and_get();
		$guest_author2_id   = $guest_author_obj->create_guest_author_from_user_id( $author2->ID );
		$guest_author2      = $guest_author_obj->get_guest_author_by( 'ID', $guest_author2_id );
		$guest_author2_term = $coauthors_plus->get_author_term( $guest_author2 );

		$response = $guest_author_obj->delete( $guest_author2_id, $guest_author2->linked_account );

		$this->assertTrue( $response );
		$this->assertNotEmpty( get_term_by( 'id', $guest_author2_term->term_id, $coauthors_plus->coauthor_taxonomy ) );
		$this->assertNull( get_post( $guest_author2_id ) );
	}

	/**
	 * Checks delete guest author with reassign author when linked account and author are different user.
	 *
	 * @covers CoAuthors_Guest_Authors::delete()
	 */
	public function test_delete_with_reassign_when_linked_account_and_author_are_different_user(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$guest_admin_id = $guest_author_obj->create_guest_author_from_user_id( $this->admin1->ID );
		$guest_admin    = $guest_author_obj->get_guest_author_by( 'ID', $guest_admin_id );

		$author2            = $this->factory()->user->create_and_get();
		$guest_author_id2   = $guest_author_obj->create_guest_author_from_user_id( $author2->ID );
		$guest_author2      = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id2 );
		$guest_author_term2 = $coauthors_plus->get_author_term( $guest_author2 );

		$post = $this->factory()->post->create_and_get(
			array(
				'post_author' => $author2->ID,
			)
		);

		$response = $guest_author_obj->delete( $guest_author_id2, $guest_admin->linked_account );

		// Checks post author, it should be reassigned to new author.
		$this->assertEquals( array( $guest_admin->linked_account ), wp_list_pluck( get_coauthors( $post->ID ), 'linked_account' ) );
		$this->assertTrue( $response );
		$this->assertFalse( get_term_by( 'id', $guest_author_term2->term_id, $coauthors_plus->coauthor_taxonomy ) );
		$this->assertNull( get_post( $guest_author_id2 ) );
	}

	/**
	 * Checks delete guest author with reassign author and without linked account and author is the same user.
	 *
	 * @covers CoAuthors_Guest_Authors::delete()
	 */
	public function test_delete_with_reassign_without_linked_account_and_author_is_same_user(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$guest_author_id   = $guest_author_obj->create(
			array(
				'user_login'   => 'guest_author',
				'display_name' => 'guest_author',
			)
		);
		$guest_author      = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );
		$guest_author_term = $coauthors_plus->get_author_term( $guest_author );

		$response = $guest_author_obj->delete( $guest_author_id, $guest_author->user_login );

		$this->assertTrue( $response );
		$this->assertNotEmpty( get_term_by( 'id', $guest_author_term->term_id, $coauthors_plus->coauthor_taxonomy ) );
		$this->assertNull( get_post( $guest_author_id ) );
	}

	/**
	 * Checks delete guest author with reassign author and without linked account and author is other user.
	 *
	 * @covers CoAuthors_Guest_Authors::delete()
	 */
	public function test_delete_with_reassign_without_linked_account_and_author_is_other_user(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$guest_admin_id = $guest_author_obj->create_guest_author_from_user_id( $this->admin1->ID );
		$guest_admin    = $guest_author_obj->get_guest_author_by( 'ID', $guest_admin_id );

		$guest_author_id   = $guest_author_obj->create(
			array(
				'user_login'   => 'guest_author',
				'display_name' => 'guest_author',
			)
		);
		$guest_author      = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );
		$guest_author_term = $coauthors_plus->get_author_term( $guest_author );

		$response = $guest_author_obj->delete( $guest_author_id, $guest_admin->user_login );

		$this->assertTrue( $response );
		$this->assertFalse( get_term_by( 'id', $guest_author_term->term_id, $coauthors_plus->coauthor_taxonomy ) );
		$this->assertNull( get_post( $guest_author_id ) );
	}

	/**
	 * Checks that update_author_term returns WP_Error when wp_insert_term fails.
	 *
	 * @covers CoAuthors_Plus::update_author_term()
	 *
	 * @link https://github.com/Automattic/Co-Authors-Plus/issues/1135
	 */
	public function test_update_author_term_returns_error_on_insert_failure(): void {

		global $coauthors_plus;

		// Force wp_insert_term to fail by using a filter.
		add_filter(
			'pre_insert_term',
			function () {
				return new \WP_Error( 'term_exists', 'A term with this slug already exists.' );
			}
		);

		// Create a mock coauthor object with all required properties.
		$coauthor                = new \stdClass();
		$coauthor->ID            = 0;
		$coauthor->user_nicename = 'test-author-' . wp_rand();
		$coauthor->user_login    = 'test-author-' . wp_rand();
		$coauthor->display_name  = 'Test Author';
		$coauthor->first_name    = 'Test';
		$coauthor->last_name     = 'Author';
		$coauthor->user_email    = 'test@example.com';

		$result = $coauthors_plus->update_author_term( $coauthor );

		$this->assertInstanceOf( 'WP_Error', $result );

		// Clean up filter.
		remove_all_filters( 'pre_insert_term' );
	}

	/**
	 * Checks that creating a guest author returns WP_Error when term creation fails
	 * and cleans up the orphaned post.
	 *
	 * @covers CoAuthors_Guest_Authors::create()
	 *
	 * @link https://github.com/Automattic/Co-Authors-Plus/issues/1135
	 */
	public function test_create_guest_author_returns_error_and_cleans_up_on_term_failure(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Count posts before attempting to create the guest author.
		$posts_before = get_posts(
			array(
				'post_type'   => $guest_author_obj->post_type,
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);
		$count_before = count( $posts_before );

		// Force wp_insert_term to fail by using a filter.
		add_filter(
			'pre_insert_term',
			function () {
				return new \WP_Error( 'term_exists', 'A term with this slug already exists.' );
			}
		);

		// Try to create a guest author - term creation will fail.
		$result = $guest_author_obj->create(
			array(
				'user_login'   => 'test-cleanup-author-' . wp_rand(),
				'display_name' => 'Test Cleanup Author',
			)
		);

		// Clean up filter.
		remove_all_filters( 'pre_insert_term' );

		// Should return a WP_Error.
		$this->assertInstanceOf( 'WP_Error', $result );

		// Verify no orphaned post was left behind.
		$posts_after = get_posts(
			array(
				'post_type'   => $guest_author_obj->post_type,
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);
		$count_after = count( $posts_after );

		$this->assertEquals( $count_before, $count_after, 'Orphaned guest author post should be cleaned up on term creation failure.' );
	}

	/**
	 * Checks that the empty content filter does not interfere with non-guest-author post types.
	 *
	 * @covers CoAuthors_Guest_Authors::filter_wp_insert_post_empty_content()
	 */
	public function test_filter_wp_insert_post_empty_content_passes_through_for_other_post_types(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$result = $guest_author_obj->filter_wp_insert_post_empty_content(
			true,
			array( 'post_type' => 'post' )
		);

		$this->assertTrue( $result, 'Filter should pass through $maybe_empty for non-guest-author post types.' );

		$result = $guest_author_obj->filter_wp_insert_post_empty_content(
			false,
			array( 'post_type' => 'post' )
		);

		$this->assertFalse( $result, 'Filter should pass through $maybe_empty for non-guest-author post types.' );
	}

	/**
	 * Checks that the empty content filter allows guest author posts with empty content.
	 *
	 * Guest author posts store data in post meta rather than post_content, so
	 * the filter must not block insertion even when title/content are empty.
	 *
	 * @covers CoAuthors_Guest_Authors::filter_wp_insert_post_empty_content()
	 */
	public function test_filter_wp_insert_post_empty_content_allows_guest_author_posts(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Even when core considers the post "empty", the filter should allow it.
		$result = $guest_author_obj->filter_wp_insert_post_empty_content(
			true,
			array(
				'post_type'  => $guest_author_obj->post_type,
				'post_title' => '',
			)
		);

		$this->assertFalse( $result, 'Filter should return false for guest author posts to allow empty content.' );
	}

	/**
	 * Checks that guest author posts with a display name also pass the empty content filter.
	 *
	 * @covers CoAuthors_Guest_Authors::filter_wp_insert_post_empty_content()
	 */
	public function test_filter_wp_insert_post_empty_content_allows_guest_author_posts_with_title(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$result = $guest_author_obj->filter_wp_insert_post_empty_content(
			true,
			array(
				'post_type'  => $guest_author_obj->post_type,
				'post_title' => 'Test Author',
			)
		);

		$this->assertFalse( $result, 'Filter should return false for guest author posts regardless of title.' );
	}

	/**
	 * Checks that wp_insert_post() succeeds for the guest author post type
	 * when title and content are empty, simulating an auto-draft scenario.
	 *
	 * This is the scenario that caused failures: the block editor creates an
	 * auto-draft with empty title/content, and the empty content filter must
	 * not block it.
	 *
	 * @covers CoAuthors_Guest_Authors::filter_wp_insert_post_empty_content()
	 */
	public function test_wp_insert_post_succeeds_for_guest_author_auto_draft(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$post_id = wp_insert_post(
			array(
				'post_type'    => $guest_author_obj->post_type,
				'post_title'   => '',
				'post_content' => '',
				'post_status'  => 'auto-draft',
			),
			true
		);

		$this->assertNotWPError( $post_id, 'wp_insert_post should succeed for guest author auto-drafts with empty content.' );
		$this->assertGreaterThan( 0, $post_id );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Checks that creating a guest author via the create() method works end-to-end.
	 *
	 * This exercises the full flow including the empty content filter.
	 *
	 * @covers CoAuthors_Guest_Authors::create()
	 * @covers CoAuthors_Guest_Authors::filter_wp_insert_post_empty_content()
	 */
	public function test_create_guest_author_succeeds_with_display_name(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$guest_author_id = $guest_author_obj->create(
			array(
				'user_login'   => 'test-empty-content-author',
				'display_name' => 'Test Empty Content Author',
			)
		);

		$this->assertIsInt( $guest_author_id, 'create() should return a post ID.' );
		$this->assertGreaterThan( 0, $guest_author_id );

		$guest_author = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );
		$this->assertInstanceOf( \stdClass::class, $guest_author );
		$this->assertEquals( 'Test Empty Content Author', $guest_author->display_name );
	}
}
