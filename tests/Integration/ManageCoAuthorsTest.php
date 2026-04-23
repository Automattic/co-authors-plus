<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

class ManageCoAuthorsTest extends TestCase {

	private $admin1;
	private $author1;
	private $editor1;
	/**
	 * @var int|WP_Error
	 */
	private $author1_post1;
	/**
	 * @var int|WP_Error
	 */
	private $author1_post2;
	/**
	 * @var int|WP_Error
	 */
	private $author1_page1;
	/**
	 * @var int|WP_Error
	 */
	private $author1_page2;

	public function set_up() {
		parent::set_up();

		$this->admin1  = $this->factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'admin1',
			)
		);
		$this->author1 = $this->factory()->user->create(
			array(
				'role'       => 'author',
				'user_login' => 'author1',
			)
		);
		$this->editor1 = $this->factory()->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'editor2',
			)
		);

		$post = array(
			'post_author'  => $this->author1,
			'post_status'  => 'publish',
			'post_content' => rand_str(),
			'post_title'   => rand_str(),
			'post_type'    => 'post',
		);

		$this->author1_post1 = wp_insert_post( $post );

		$post = array(
			'post_author'  => $this->author1,
			'post_status'  => 'publish',
			'post_content' => rand_str(),
			'post_title'   => rand_str(),
			'post_type'    => 'post',
		);

		$this->author1_post2 = wp_insert_post( $post );

		$page = array(
			'post_author'  => $this->author1,
			'post_status'  => 'publish',
			'post_content' => rand_str(),
			'post_title'   => rand_str(),
			'post_type'    => 'page',
		);

		$this->author1_page1 = wp_insert_post( $page );

		$page = array(
			'post_author'  => $this->author1,
			'post_status'  => 'publish',
			'post_content' => rand_str(),
			'post_title'   => rand_str(),
			'post_type'    => 'page',
		);

		$this->author1_page2 = wp_insert_post( $page );
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	/**
	 * Test assigning a Co-Author to a post
	 */
	public function test_add_coauthor_to_post(): void {
		global $coauthors_plus;

		$coauthors = get_coauthors( $this->author1_post1 );
		$this->assertCount( 1, $coauthors );

		// append = true, should preserve order
		$editor1 = get_user_by( 'id', $this->editor1 );
		$coauthors_plus->add_coauthors( $this->author1_post1, array( $editor1->user_login ), true );
		$coauthors = get_coauthors( $this->author1_post1 );
		$this->assertEquals( array( $this->author1, $this->editor1 ), wp_list_pluck( $coauthors, 'ID' ) );

		// append = false, overrides existing authors
		$coauthors_plus->add_coauthors( $this->author1_post1, array( $editor1->user_login ) );
		$coauthors = get_coauthors( $this->author1_post1 );
		$this->assertEquals( array( $this->editor1 ), wp_list_pluck( $coauthors, 'ID' ) );
	}

	/**
	 * When a co-author is assigned to a post, the post author value
	 * should be set appropriately
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/140
	 */
	public function test_add_coauthor_updates_post_author(): void {
		global $coauthors_plus;

		// append = true, preserves existing post_author
		$editor1 = get_user_by( 'id', $this->editor1 );
		$coauthors_plus->add_coauthors( $this->author1_post1, array( $editor1->user_login ), true );
		$this->assertEquals( $this->author1, get_post( $this->author1_post1 )->post_author );

		// append = false, overrides existing post_author
		$coauthors_plus->add_coauthors( $this->author1_post1, array( $editor1->user_login ) );
		$this->assertEquals( $this->editor1, get_post( $this->author1_post1 )->post_author );
	}

	/**
	 * Post published count should default to 'post', but be filterable
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/170
	 */
	public function test_post_publish_count_for_coauthor(): void {
		global $coauthors_plus;

		$editor1 = get_user_by( 'id', $this->editor1 );

		/**
		 * Two published posts
		 */
		$coauthors_plus->add_coauthors( $this->author1_post1, array( $editor1->user_login ) );
		$coauthors_plus->add_coauthors( $this->author1_post2, array( $editor1->user_login ) );
		$this->assertEquals( 2, count_user_posts( $editor1->ID ) );

		/**
		 * One published page too, but no filter
		 */
		$coauthors_plus->add_coauthors( $this->author1_page1, array( $editor1->user_login ) );
		$this->assertEquals( 2, count_user_posts( $editor1->ID ) );

		// Publish count to include posts and pages
		$filter = function () {
			return array( 'post', 'page' );
		};
		add_filter( 'coauthors_count_published_post_types', $filter );

		/**
		 * Two published posts and pages
		 */
		$coauthors_plus->add_coauthors( $this->author1_page2, array( $editor1->user_login ) );
		$this->assertEquals( 4, count_user_posts( $editor1->ID ) );

		// Publish count is just pages
		remove_filter( 'coauthors_count_published_post_types', $filter );
		$filter = function () {
			return array( 'page' );
		};
		add_filter( 'coauthors_count_published_post_types', $filter );

		/**
		 * Just one published page now for the editor
		 */
		$author1 = get_user_by( 'id', $this->author1 );
		$coauthors_plus->add_coauthors( $this->author1_page2, array( $author1->user_login ) );
		$this->assertEquals( 1, count_user_posts( $editor1->ID ) );
	}

	/**
	 * Returns data as it is when post type is not allowed.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_set_post_author_field
	 */
	public function test_coauthors_set_post_author_field_when_post_type_is_attachment(): void {

		global $coauthors_plus;

		$this->assertEquals(
			10,
			has_filter(
				'wp_insert_post_data',
				array(
					$coauthors_plus,
					'coauthors_set_post_author_field',
				)
			)
		);

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->author1,
				'post_type'   => 'attachment',
			)
		);

		$post = get_post( $post_id );

		$post_array = array(
			'ID'          => $post->ID,
			'post_type'   => $post->post_type,
			'post_author' => $post->post_author,
		);
		$data       = $post_array;

		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $post_array );

		$this->assertEquals( $data, $new_data );
	}

	/**
	 * Compares data when coauthor is not set in the post array.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_set_post_author_field
	 */
	public function test_coauthors_set_post_author_field_when_coauthor_is_not_set(): void {

		global $coauthors_plus;

		$author1_post1 = get_post( $this->author1_post1 );

		$post_array = array(
			'ID'          => $author1_post1->ID,
			'post_type'   => $author1_post1->post_type,
			'post_author' => $author1_post1->post_author,
		);
		$data       = $post_array;

		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $post_array );

		$this->assertEquals( $data, $new_data );
	}

	/**
	 * Compares data when coauthor is set in the post array.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_set_post_author_field
	 */
	public function test_coauthors_set_post_author_field_when_coauthor_is_set(): void {

		global $coauthors_plus;

		$user_id = $this->factory()->user->create(
			array(
				'user_login'    => 'test_admin',
				'user_nicename' => 'test_admiи',
			)
		);

		$user = get_user_by( 'id', $user_id );

		// Backing up global variables.
		$post_backup    = $_POST;
		$request_backup = $_REQUEST;

		$_REQUEST['coauthors-nonce'] = wp_create_nonce( 'coauthors-edit' );

		$_POST['coauthors'] = array(
			$user->user_nicename,
		);

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $user_id,
			)
		);

		$post = get_post( $post_id );

		$post_array = array(
			'ID'          => $post->ID,
			'post_type'   => $post->post_type,
			'post_author' => $post->post_author,
		);
		$data       = $post_array;

		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $post_array );

		$this->assertEquals( $data, $new_data );

		// Store global variables from backup.
		$_POST    = $post_backup;
		$_REQUEST = $request_backup;
	}

	/**
	 * Compares data when coauthor is set, and it is linked with main wp user.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_set_post_author_field
	 */
	public function test_coauthors_set_post_author_field_when_guest_author_is_linked_with_wp_user(): void {

		global $coauthors_plus;

		$author1 = get_user_by( 'id', $this->author1 );

		$author1_post1 = get_post( $this->author1_post1 );

		$post_array = array(
			'ID'          => $author1_post1->ID,
			'post_type'   => $author1_post1->post_type,
			'post_author' => $author1_post1->post_author,
		);
		$data       = $post_array;

		// Backing up global variables.
		$post_backup    = $_POST;
		$request_backup = $_REQUEST;

		$_REQUEST['coauthors-nonce'] = wp_create_nonce( 'coauthors-edit' );

		$_POST['coauthors'] = array(
			$author1->user_nicename,
		);

		// Create guest author with linked account with user.
		$coauthors_plus->guest_authors = new \CoAuthors_Guest_Authors();
		$coauthors_plus->guest_authors->create_guest_author_from_user_id( $this->author1 );

		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $post_array );

		$this->assertEquals( $data, $new_data );

		// Store global variables from backup.
		$_POST    = $post_backup;
		$_REQUEST = $request_backup;
	}

	/**
	 * Compares post author when it is not set in the main data array somehow.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_set_post_author_field
	 */
	public function test_coauthors_set_post_author_field_when_post_author_is_not_set(): void {

		global $coauthors_plus;

		wp_set_current_user( $this->author1 );

		// Backing up global variables.
		$post_backup    = $_POST;
		$request_backup = $_REQUEST;

		$_POST    = array();
		$_REQUEST = array();

		$author1_post1 = get_post( $this->author1_post1 );

		$post_array = array(
			'ID'          => $author1_post1->ID,
			'post_type'   => $author1_post1->post_type,
			'post_author' => $author1_post1->post_author,
		);
		$data       = $post_array;

		unset( $data['post_author'] );

		$new_data = $coauthors_plus->coauthors_set_post_author_field( $data, $post_array );

		$this->assertEquals( $this->author1, $new_data['post_author'] );

		// Store global variables from backup.
		$_POST    = $post_backup;
		$_REQUEST = $request_backup;
	}

	/**
	 * Bypass coauthors_update_post() when post type is not allowed.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_update_post
	 */
	public function test_coauthors_update_post_when_post_type_is_attachment(): void {

		global $coauthors_plus;

		$this->assertEquals(
			10,
			has_action(
				'save_post',
				array(
					$coauthors_plus,
					'coauthors_update_post',
				)
			)
		);

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->author1,
				'post_type'   => 'attachment',
			)
		);

		$post   = get_post( $post_id );
		$return = $coauthors_plus->coauthors_update_post( $post_id, $post );

		$this->assertNull( $return );
	}

	/**
	 * Checks coauthors when current user can set authors.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_update_post
	 */
	public function test_coauthors_update_post_when_current_user_can_set_authors(): void {

		global $coauthors_plus;

		wp_set_current_user( $this->admin1 );

		$admin1  = get_user_by( 'id', $this->admin1 );
		$author1 = get_user_by( 'id', $this->author1 );

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->admin1,
			)
		);

		$post = get_post( $post_id );

		// Backing up global variables.
		$post_backup    = $_POST;
		$request_backup = $_REQUEST;

		$nonce                       = wp_create_nonce( 'coauthors-edit' );
		$_POST['coauthors-nonce']    = $nonce;
		$_REQUEST['coauthors-nonce'] = $nonce;
		$_POST['coauthors']       = array(
			$admin1->user_nicename,
			$author1->user_nicename,
		);

		$coauthors_plus->coauthors_update_post( $post_id, $post );

		$coauthors = get_coauthors( $post_id );

		$this->assertEquals( array( $this->admin1, $this->author1 ), wp_list_pluck( $coauthors, 'ID' ) );

		// Store global variables from backup.
		$_POST    = $post_backup;
		$_REQUEST = $request_backup;
	}

	/**
	 * Coauthors should be empty if post does not have any author terms
	 * and current user can not set authors for the post.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_update_post
	 */
	public function test_coauthors_update_post_when_post_has_not_author_terms(): void {

		global $coauthors_plus;

		$post_id = $this->factory()->post->create();
		$post    = get_post( $post_id );

		$coauthors_plus->coauthors_update_post( $post_id, $post );

		$coauthors = get_coauthors( $post_id );

		$this->assertEmpty( $coauthors );
	}
}
