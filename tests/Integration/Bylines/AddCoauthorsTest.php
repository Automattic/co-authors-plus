<?php
/**
 * Tests for assigning and appending co-authors to a post.
 *
 * Covers CoAuthors_Plus::add_coauthors() across the full matrix of WP_User,
 * guest author and linked-account inputs, in both assign and append modes,
 * verifying the resulting wp_posts.post_author column and the co-author terms.
 * Also covers delete_user_action() not warning when a co-author term is missing.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_Query;
use WP_Term;

/**
 * @coversDefaultClass \CoAuthors_Plus
 */
class AddCoauthorsTest extends TestCase {

	private $author1;

	private $author2;

	private $author3;

	private $editor1;

	public function set_up() {
		parent::set_up();

		$this->author1 = $this->create_author( 'author1' );
		$this->author2 = $this->create_author( 'author20' );
		$this->author3 = $this->create_author( 'author30' );
		$this->editor1 = $this->create_editor( 'editor1' );
	}

	/**
	 * This is a basic test to ensure that any authors being assigned to a post
	 * using the CoAuthors_Plus::add_coauthors() method are appropriately
	 * associated to the post. Some of the things the add_coauthors()
	 * method should do are:
	 *
	 * 1. Ensure that the post_author is set to the first author in the list
	 * 2. This is done internally by calling CoAuthors_Plus::get_coauthor_by(),
	 * which should return a WP_User in this instance (since the author is not linked to a coauthor account)
	 * 3. Since this coauthor is not linked, create the author's coauthor term, and associate it to the post.
	 *
	 * @covers ::add_coauthors
	 */
	public function test_assign_post_author_from_author_who_has_not_been_linked() {
		$post    = $this->factory()->post->create_and_get(
			array(
				'post_author' => $this->author2->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$post_id = $post->ID;

		$first_added_authors = $this->_cap->add_coauthors( $post_id, array( $this->author3->user_login ) );
		// add_coauthors should return true because CAP will treat this user as an Author with the
		// extra step of setting wp_post.post_author equal to this user's wp_user.ID.
		$this->assertTrue( $first_added_authors );

		$query1 = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		// Checking that the wp_post.post_author column has indeed been updated.
		$this->assertEquals( $this->author3->ID, $query1->posts[0]->post_author );

		$author3_term = $this->_cap->get_author_term( $this->author3 );

		$this->assertInstanceOf( WP_Term::class, $author3_term );

		$post_author_terms = wp_get_post_terms( $post_id, $this->_cap->coauthor_taxonomy );

		$this->assertIsArray( $post_author_terms );
		$this->assertCount( 1, $post_author_terms );
		$this->assertInstanceOf( WP_Term::class, $post_author_terms[0] );
		$this->assertEquals( 'cap-' . $this->author3->user_login, $post_author_terms[0]->slug );

		// Confirming that now $author2 does have an author term.
		$second_added_authors = $this->_cap->add_coauthors( $post_id, array( $this->author2->user_login ) );
		$this->assertTrue( $second_added_authors );
		$author2_term = $this->_cap->get_author_term( $this->author2 );
		$this->assertInstanceOf( WP_Term::class, $author2_term );

		$post_author_terms = wp_get_post_terms( $post_id, $this->_cap->coauthor_taxonomy );

		$this->assertIsArray( $post_author_terms );
		$this->assertCount( 1, $post_author_terms );
		$this->assertInstanceOf( WP_Term::class, $post_author_terms[0] );
		$this->assertEquals( 'cap-' . $this->author2->user_login, $post_author_terms[0]->slug );
	}

	/**
	 * This test should not affect the post_author field, since we
	 * are simply appending an author to a post.
	 *
	 * @covers ::add_coauthors
	 */
	public function test_append_post_author_who_has_not_been_linked() {
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->author2->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		// Immediately update the co-authors, adding $author3 to the existing $author2.
		$this->_cap->add_coauthors( $post_id, array( $this->author3->user_login ), true );

		$query = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		$this->assertEquals( 1, $query->found_posts );
		// Although we added a co-author, the wp_posts.post_author column should still be attributed to $author2.
		$this->assertEquals( $this->author2->ID, $query->posts[0]->post_author );

		$this->assertPostHasCoAuthors(
			$post_id,
			array(
				$this->author2,
				$this->author3,
			)
		);
	}

	/**
	 * Here we are assigning multiple authors who have not been
	 * linked to a coauthor to a post. Since we are not
	 * appending authors to the post, we should
	 * expect the post_author to change.
	 *
	 * @covers ::add_coauthors
	 */
	public function test_assign_post_authors_from_authors_who_have_not_been_linked() {
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->author1->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$this->_cap->add_coauthors(
			$post_id,
			array(
				$this->author3->user_login,
				$this->editor1->user_login,
				$this->author2->user_login,
			)
		);

		$query = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		$this->assertEquals( 1, $query->found_posts );
		$this->assertEquals( $this->author3->ID, $query->posts[0]->post_author );

		$this->assertPostHasCoAuthors(
			$post_id,
			array(
				$this->author3,
				$this->editor1,
				$this->author2,
			)
		);
	}

	/**
	 * Here we are creating guest authors (coauthors) and assigning them to a post,
	 * which was created by a WP_User. Since the guest authors have not been
	 * linked to a WP_User, the wp_post.post_author column should not
	 * change, and the response from CoAuthors_Plus::add_coauthors()
	 * should be false, since no WP_User could be found.
	 *
	 * @covers ::add_coauthors
	 */
	public function test_assign_post_authors_from_coauthors_who_have_not_been_linked() {
		$random_username = 'random_user_' . wp_rand( 1, 1000 );
		$display_name    = str_replace( '_', ' ', $random_username );

		$guest_author_1_id = $this->_cap->guest_authors->create(
			array(
				'user_login'   => $random_username,
				'display_name' => $display_name,
			)
		);
		/**
		 * By using CoAuthors_Plus::get_coauthor_by(), we are ensuring
		 * that the recent changes to the code will prioritize
		 * returning a Guest Author when one is found.
		 */
		$guest_author_1 = $this->_cap->get_coauthor_by( 'id', $guest_author_1_id );

		$this->assertIsGuestAuthorNotWpUser( $guest_author_1 );

		$random_username = 'random_user_' . wp_rand( 1001, 2000 );
		$display_name    = str_replace( '_', ' ', $random_username );

		$guest_author_2_id = $this->_cap->guest_authors->create(
			array(
				'user_login'   => $random_username,
				'display_name' => $display_name,
			)
		);
		$guest_author_2 = $this->_cap->get_coauthor_by( 'id', $guest_author_2_id );

		$this->assertIsGuestAuthorNotWpUser( $guest_author_2 );

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->author1->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$query = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		$this->assertEquals( 1, $query->found_posts );
		$this->assertEquals( $this->author1->ID, $query->posts[0]->post_author );

		$result = $this->_cap->add_coauthors(
			$post_id,
			array(
				$guest_author_1->user_login,
				$guest_author_2->user_login,
			)
		);

		/*
		 * This is false because we are NOT appending any coauthors who are linked to a WP_User to the post.
		 * */
		$this->assertFalse( $result );

		$second_query = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		$this->assertEquals( 1, $second_query->found_posts );
		$this->assertEquals( $this->author1->ID, $second_query->posts[0]->post_author );
	}

	/**
	 * This test is similar to above, but instead here, we are appending coauthors.
	 * This means that the wp_posts.post_author column is not expected to change,
	 * and so the response from CoAuthors_Plus::add_coauthors() should be true.
	 *
	 * @covers ::add_coauthors
	 */
	public function test_append_post_authors_from_coauthors_who_have_not_been_linked() {
		$random_username = 'random_user_' . wp_rand( 1, 1000 );
		$display_name    = str_replace( '_', ' ', $random_username );

		$guest_author_1_id = $this->_cap->guest_authors->create(
			array(
				'user_login'   => $random_username,
				'display_name' => $display_name,
			)
		);
		$guest_author_1 = $this->_cap->get_coauthor_by( 'id', $guest_author_1_id );

		$this->assertIsGuestAuthorNotWpUser( $guest_author_1 );

		$random_username = 'random_user_' . wp_rand( 1001, 2000 );
		$display_name    = str_replace( '_', ' ', $random_username );

		$guest_author_2_id = $this->_cap->guest_authors->create(
			array(
				'user_login'   => $random_username,
				'display_name' => $display_name,
			)
		);
		$guest_author_2 = $this->_cap->get_coauthor_by( 'id', $guest_author_2_id );

		$this->assertIsGuestAuthorNotWpUser( $guest_author_2 );

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->author1->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$query = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		$this->assertEquals( 1, $query->found_posts );
		$this->assertEquals( $this->author1->ID, $query->posts[0]->post_author );

		$result = $this->_cap->add_coauthors(
			$post_id,
			array(
				$guest_author_1->user_login,
				$guest_author_2->user_login,
			),
			true
		);

		$this->assertTrue( $result );

		$second_query = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		$this->assertEquals( 1, $second_query->found_posts );
		$this->assertEquals( $this->author1->ID, $second_query->posts[0]->post_author );

		$this->assertPostHasCoAuthors(
			$post_id,
			array(
				$this->author1,
				$guest_author_1,
				$guest_author_2,
			)
		);
	}

	/**
	 * Here we are assigning one coauthor and one WP_User who have not been linked.
	 * The result should be true, since the WP_User will be assigned as the
	 * post_author. There should only be 2 WP_Terms for the authors.
	 *
	 * @covers ::add_coauthors
	 */
	public function test_assign_coauthors_from_coauthors_and_user_who_have_not_been_linked() {
		$random_username = 'random_user_' . wp_rand( 1, 1000 );
		$display_name    = str_replace( '_', ' ', $random_username );

		$guest_author_1_id = $this->_cap->guest_authors->create(
			array(
				'user_login'   => $random_username,
				'display_name' => $display_name,
			)
		);
		$guest_author_1 = $this->_cap->get_coauthor_by( 'id', $guest_author_1_id );

		$this->assertIsGuestAuthorNotWpUser( $guest_author_1 );

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->author1->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$query = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		$this->assertEquals( 1, $query->found_posts );
		$this->assertEquals( $this->author1->ID, $query->posts[0]->post_author );

		$result = $this->_cap->add_coauthors(
			$post_id,
			array(
				$guest_author_1->user_login,
				$this->author3->user_login,
			)
		);

		$this->assertTrue( $result );

		$second_query = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		$this->assertEquals( 1, $second_query->found_posts );
		$this->assertEquals( $this->author3->ID, $second_query->posts[0]->post_author );

		$this->assertPostHasCoAuthors(
			$post_id,
			array(
				$this->author3,
				$guest_author_1,
			)
		);
	}

	/**
	 * Similar to above, but we are appending instead. The wp_posts.post_author should
	 * not be changed, but we should see 3 WP_Terms for the authors now.
	 *
	 * @covers ::add_coauthors
	 */
	public function test_append_coauthors_from_coauthors_and_user_who_have_not_been_linked() {
		$random_username = 'random_user_' . wp_rand( 1, 1000 );
		$display_name    = str_replace( '_', ' ', $random_username );

		$guest_author_1_id = $this->_cap->guest_authors->create(
			array(
				'user_login'   => $random_username,
				'display_name' => $display_name,
			)
		);
		$guest_author_1 = $this->_cap->get_coauthor_by( 'id', $guest_author_1_id );

		$this->assertIsGuestAuthorNotWpUser( $guest_author_1 );

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->author1->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$query = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		$this->assertEquals( 1, $query->found_posts );
		$this->assertEquals( $this->author1->ID, $query->posts[0]->post_author );

		$result = $this->_cap->add_coauthors(
			$post_id,
			array(
				$guest_author_1->user_login,
				$this->author3->user_login,
			),
			true
		);

		$this->assertTrue( $result );

		$second_query = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		$this->assertEquals( 1, $second_query->found_posts );
		$this->assertEquals( $this->author1->ID, $second_query->posts[0]->post_author );

		$this->assertPostHasCoAuthors(
			$post_id,
			array(
				$this->author1,
				$this->author3,
				$guest_author_1,
			)
		);
	}

	/**
	 * Provides the different permutations of assigning authors to a post.
	 *
	 * @return array[]
	 */
	public function provide_data_for_assign_post_authors_test() {
		return array(
			'setting_linked_coauthors'                => array(
				'author_set'         => array(
					'author_1' => 'linked',
					'author_2' => 'linked',
				),
				'all_authors_linked' => true,
				'append'             => false,
			),
			'appending_linked_coauthors'              => array(
				'author_set'         => array(
					'author_1' => 'linked',
					'author_2' => 'linked',
				),
				'all_authors_linked' => true,
				'append'             => true,
			),
			'setting_linked_and_unlinked_coauthors'   => array(
				'author_set'         => array(
					'author_1' => 'guest',
					'author_2' => 'linked',
				),
				'all_authors_linked' => false,
				'append'             => false,
			),
			'appending_linked_and_unlinked_coauthors' => array(
				'author_set'         => array(
					'author_1' => 'linked',
					'author_2' => 'guest',
				),
				'all_authors_linked' => false,
				'append'             => true,
			),
			'setting_unlinked_coauthors'              => array(
				'author_set'         => array(
					'author_1' => 'user',
					'author_2' => 'guest',
				),
				'all_authors_linked' => false,
				'append'             => false,
			),
			'appending_unlinked_coauthors'            => array(
				'author_set'         => array(
					'author_1' => 'guest',
					'author_2' => 'user',
				),
				'all_authors_linked' => false,
				'append'             => true,
			),
		);
	}

	/**
	 * This is where we test many moving parts of the CoAuthorsPlugin all at once. We are creating a guest author from a
	 * WP_User, and then assigning that guest author to a post. Since the guest author is linked to a WP_User, the
	 * function CoAuthors_Plus::get_coauthor_by() should return a guest author object along with meta data
	 * indicating that the object is linked to a WP_User. The wp_posts.post_author column should change,
	 * and the response from CoAuthors_Plus::add_coauthors() should be true.
	 *
	 * @dataProvider provide_data_for_assign_post_authors_test
	 * @covers ::add_coauthors
	 */
	public function test_assign_post_authors_from_coauthors( $author_set, $all_authors_linked, $append ) {
		$coauthors = array();

		foreach ( $author_set as $author_key => $link_type ) {
			if ( in_array( $link_type, array( 'linked', 'user' ), true ) ) {
				$author = $this->factory()->user->create_and_get(
					array(
						'role'         => 'author',
						'user_login'   => wp_rand( 1, 1000 ) . '_author_' . $author_key,
						'display_name' => 'Author ' . $author_key,
						'first_name'   => 'Author',
						'last_name'    => $author_key,
					)
				);

				if ( 'linked' === $link_type ) {

					$this->_cap->guest_authors->create_guest_author_from_user_id( $author->ID );

					$linked_author = $this->_cap->get_coauthor_by( 'id', $author->ID );

					$this->assertIsGuestAuthorNotWpUser( $linked_author );

					$coauthors[] = array(
						'user'     => $author,
						'coauthor' => $linked_author,
					);
				} else {
					$coauthors[] = array(
						'user' => $author,
					);
				}
			} else {
				$random_username = 'random_user_' . wp_rand( 1001, 2000 );
				$display_name    = str_replace( '_', ' ', $random_username );

				$guest_author_id = $this->_cap->guest_authors->create(
					array(
						'user_login'   => $random_username,
						'display_name' => $display_name,
					)
				);

				$guest_author = $this->_cap->get_coauthor_by( 'id', $guest_author_id );

				$this->assertIsGuestAuthorNotWpUser( $guest_author );

				$coauthors[] = array(
					'coauthor' => $guest_author,
				);
			}
		}

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->editor1->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$query = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		$this->assertEquals( 1, $query->found_posts );
		$this->assertEquals( $this->editor1->ID, $query->posts[0]->post_author );

		$result = $this->_cap->add_coauthors(
			$post_id,
			array_map(
				function ( $coauthor ) {
					if ( isset( $coauthor['coauthor'] ) ) {
						return $coauthor['coauthor']->user_login;
					}

					return $coauthor['user']->user_login;
				},
				$coauthors
			),
			$append
		);

		$this->assertTrue( $result );

		$second_query = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		$this->assertEquals( 1, $second_query->found_posts );

		$assigned_authors = array_map(
			function ( $coauthor ) {
				if ( isset( $coauthor['coauthor'] ) ) {
					return $coauthor['coauthor'];
				}

				return $coauthor['user'];
			},
			$coauthors
		);

		$first_user_account = null;
		foreach ( $coauthors as $coauthor ) {
			if ( isset( $coauthor['user'] ) ) {
				$first_user_account = $coauthor['user'];
				break;
			}
		}

		if ( $all_authors_linked ) {
			if ( $append ) {
				$this->assertEquals( $this->editor1->ID, $second_query->posts[0]->post_author );
				$this->assertPostHasCoAuthors( $post_id, array_merge( array( $this->editor1 ), $assigned_authors ) );
			} else {
				if ( $first_user_account ) {
					$this->assertEquals( $first_user_account->ID, $second_query->posts[0]->post_author );
				}
				$this->assertPostHasCoAuthors( $post_id, $assigned_authors );
			}
		} elseif ( $append ) {
				$this->assertEquals( $this->editor1->ID, $second_query->posts[0]->post_author );
				$this->assertPostHasCoAuthors(
					$post_id,
					array_merge(
						array(
							$this->editor1,
						),
						$assigned_authors
					)
				);
		} else {
			if ( $first_user_account ) {
				$this->assertEquals( $first_user_account->ID, $second_query->posts[0]->post_author );
			}
			$this->assertPostHasCoAuthors( $post_id, $assigned_authors );
		}
	}

	/**
	 * Provides ordering permutations for assigning multiple co-authors that mix a
	 * plain WP_User, guest authors and one user linked to a guest author.
	 *
	 * Each row exercises CoAuthors_Plus::add_coauthors() with a different ordering
	 * of the co-author logins to prove that placement within the array does not
	 * affect which WP_User becomes wp_posts.post_author.
	 *
	 * Author tokens used in the login order and expectations:
	 *  - 'guest1' / 'guest2': freshly created guest authors (never the post_author).
	 *  - 'author1' / 'author2': plain WP_User fixtures.
	 *  - 'author3': the linked user, passed by its guest-author login.
	 *  - 'author3_login': the linked user, passed by its WP_User login.
	 *
	 * @return array[]
	 */
	public function provide_multiple_post_author_ordering() {
		return array(
			'wp_user_guest_author_linked_user'         => array(
				'login_order'           => array( 'author1', 'guest1', 'author3' ),
				'expected_post_author'  => 'author1',
				'expected_coauthors'    => array( 'author1', 'guest1', 'author3' ),
				'assert_linked_term'    => false,
			),
			'only_one_linked_passed_last'              => array(
				'login_order'           => array( 'guest1', 'guest2', 'author3' ),
				'expected_post_author'  => 'author3',
				'expected_coauthors'    => array( 'author3', 'guest1', 'guest2' ),
				'assert_linked_term'    => false,
			),
			'one_user_before_one_linked_passed_last'   => array(
				'login_order'           => array( 'guest1', 'author2', 'author3' ),
				'expected_post_author'  => 'author2',
				'expected_coauthors'    => array( 'author2', 'author3', 'guest1' ),
				'assert_linked_term'    => false,
			),
			'one_linked_passed_first'                  => array(
				'login_order'           => array( 'author3', 'author2', 'guest1' ),
				'expected_post_author'  => 'author3',
				'expected_coauthors'    => array( 'author2', 'author3', 'guest1' ),
				'assert_linked_term'    => false,
			),
			'one_linked_passed_using_user_login'       => array(
				'login_order'           => array( 'guest1', 'author3_login', 'author2' ),
				'expected_post_author'  => 'author3',
				'expected_coauthors'    => array( 'author2', 'author3', 'guest1' ),
				'assert_linked_term'    => true,
			),
		);
	}

	/**
	 * Confirms that no matter the order in which a linked user is passed in the
	 * array, the wp_posts.post_author column is set to the linked user's WP_User,
	 * and that the correct co-author terms (including for the linked guest author)
	 * are associated with the post.
	 *
	 * @dataProvider provide_multiple_post_author_ordering
	 * @covers ::add_coauthors
	 *
	 * @param string[] $login_order          Author tokens in the order passed to add_coauthors().
	 * @param string   $expected_post_author Author token expected to own the post.
	 * @param string[] $expected_coauthors   Author tokens expected as co-author terms.
	 * @param bool     $assert_linked_term   Whether to also assert the linked guest author's own term.
	 */
	public function test_assign_multiple_post_authors_ordering( $login_order, $expected_post_author, $expected_coauthors, $assert_linked_term ) {
		// Two guest authors that are not linked to any WP_User.
		$guest_logins = array();
		foreach ( array( 'guest1', 'guest2' ) as $guest_key ) {
			$random_username = 'random_user_' . $guest_key . '_' . wp_rand( 1, 100000 );
			$display_name    = str_replace( '_', ' ', $random_username );

			$guest_author_id = $this->_cap->guest_authors->create(
				array(
					'user_login'   => $random_username,
					'display_name' => $display_name,
				)
			);

			$guest_author = $this->_cap->get_coauthor_by( 'id', $guest_author_id );
			$this->assertIsGuestAuthorNotWpUser( $guest_author );

			$guest_logins[ $guest_key ] = $guest_author;
		}

		// $author3 is linked to a guest author.
		$this->_cap->guest_authors->create_guest_author_from_user_id( $this->author3->ID );
		$linked_author = $this->_cap->get_coauthor_by( 'id', $this->author3->ID );
		$this->assertIsGuestAuthorNotWpUser( $linked_author );

		// Map an author token to the login string passed to add_coauthors().
		$resolve_login = function ( $token ) use ( $guest_logins, $linked_author ) {
			switch ( $token ) {
				case 'guest1':
				case 'guest2':
					return $guest_logins[ $token ]->user_login;
				case 'author1':
					return $this->author1->user_login;
				case 'author2':
					return $this->author2->user_login;
				case 'author3':
					// Linked user passed by its guest-author login.
					return $linked_author->user_login;
				case 'author3_login':
					// Linked user passed by its WP_User login; should resolve to the GA account.
					return $this->author3->user_login;
				default:
					$this->fail( 'Unknown author token: ' . $token );
			}
		};

		// Map an author token to the object passed to assertPostHasCoAuthors().
		$resolve_coauthor = function ( $token ) use ( $guest_logins ) {
			switch ( $token ) {
				case 'guest1':
				case 'guest2':
					return $guest_logins[ $token ];
				case 'author1':
					return $this->author1;
				case 'author2':
					return $this->author2;
				case 'author3':
					return $this->author3;
				default:
					$this->fail( 'Unknown author token: ' . $token );
			}
		};

		// Map an author token to its expected WP_User ID.
		$expected_author_ids = array(
			'author1' => $this->author1->ID,
			'author2' => $this->author2->ID,
			'author3' => $this->author3->ID,
		);

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->editor1->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$query = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		$this->assertEquals( 1, $query->found_posts );
		$this->assertEquals( $this->editor1->ID, $query->posts[0]->post_author );

		$result = $this->_cap->add_coauthors(
			$post_id,
			array_map( $resolve_login, $login_order )
		);

		$this->assertTrue( $result );

		$second_query = new WP_Query(
			array(
				'p' => $post_id,
			)
		);

		$this->assertEquals( 1, $second_query->found_posts );
		$this->assertEquals( $expected_author_ids[ $expected_post_author ], $second_query->posts[0]->post_author );

		$this->assertPostHasCoAuthors(
			$post_id,
			array_map( $resolve_coauthor, $expected_coauthors )
		);

		if ( $assert_linked_term ) {
			$guest_author_term = wp_get_post_terms( $linked_author->ID, $this->_cap->coauthor_taxonomy );

			$this->assertIsArray( $guest_author_term );
			$this->assertCount( 1, $guest_author_term );
			$this->assertEquals( 'cap-' . $linked_author->user_login, $guest_author_term[0]->slug );
		}
	}

	/**
	 * Confirms that add_coauthors() persists the order of the input array via
	 * wp_term_relationships.term_order, and that get_coauthors() (which orders
	 * by term_order ASC) returns the authors in that same order. See issue #1052.
	 *
	 * Also covers a re-call with a different order to prove the term_order
	 * write actually overwrites the previously persisted value, rather than
	 * only writing on the first call.
	 *
	 * @covers ::add_coauthors
	 */
	public function test_add_coauthors_preserves_input_order(): void {
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->editor1->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		// First assignment: alphabetic input order so it could plausibly match
		// the read path's accidental ordering.
		$first_order = array( $this->author1->user_login, $this->author2->user_login, $this->author3->user_login );
		$this->assertTrue( $this->_cap->add_coauthors( $post_id, $first_order ) );

		$coauthors = get_coauthors( $post_id );
		$this->assertCount( 3, $coauthors );
		$this->assertSame(
			$first_order,
			array_map(
				function ( $c ) {
					return $c->user_login;
				},
				$coauthors
			)
		);

		// Reorder: a non-alphabetic, non-term-id order to prove the term_order
		// write overrides the previous value rather than just touching new rows.
		$second_order = array( $this->author3->user_login, $this->author1->user_login, $this->author2->user_login );
		$this->assertTrue( $this->_cap->add_coauthors( $post_id, $second_order ) );

		$reordered = get_coauthors( $post_id );
		$this->assertCount( 3, $reordered );
		$this->assertSame(
			$second_order,
			array_map(
				function ( $c ) {
					return $c->user_login;
				},
				$reordered
			)
		);
	}

	/**
	 * Tests that deleting a user without a co-author term doesn't cause PHP warnings.
	 *
	 * @covers ::delete_user_action
	 */
	public function test_delete_user_without_coauthor_term_should_not_cause_warning(): void {
		global $coauthors_plus;

		// Create a user.
		$user = $this->create_author( 'test_user_deletion' );

		// Create a post for the user.
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $user->ID,
				'post_status' => 'publish',
			)
		);

		// Add the user as a co-author to ensure the term is created.
		$coauthors_plus->add_coauthors( $post_id, array( $user->user_nicename ), true );

		// Verify the term exists.
		$term = $coauthors_plus->get_author_term( $user );
		$this->assertNotFalse( $term, 'Co-author term should exist before deletion' );

		// Manually delete the co-author term to simulate the bug scenario
		// (e.g., term was manually deleted or corrupted).
		wp_delete_term( $term->term_id, $coauthors_plus->coauthor_taxonomy );

		// Verify the term is gone.
		$term_after_deletion = $coauthors_plus->get_author_term( $user );
		$this->assertFalse( $term_after_deletion, 'Co-author term should not exist after manual deletion' );

		// Now delete the user - this should not cause a PHP warning.
		// The bug is that the code tries to access $term->term_id when $term is false.
		$deleted = wp_delete_user( $user->ID );

		// No PHP warning should have been raised by the deletion handler (the test
		// runner enforces that), and the deletion must report success.
		$this->assertTrue( $deleted, 'wp_delete_user() should report success.' );

		// On single site the user record is removed entirely. On multisite,
		// wp_delete_user() only detaches the user from the current site — the network
		// user record persists — so only assert full removal on single site.
		if ( ! is_multisite() ) {
			$this->assertFalse( get_user_by( 'id', $user->ID ), 'The user should no longer exist after deletion.' );
		}
	}
}
