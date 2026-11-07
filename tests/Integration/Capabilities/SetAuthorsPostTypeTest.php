<?php
/**
 * Tests that setting co-authors needs the post type's own edit_others cap.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Capabilities;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_REST_Request;

/**
 * A user with edit_others_posts but not edit_others_pages can't set the
 * co-authors of a page, even one they can edit as its co-author.
 *
 * @covers \CoAuthors_Plus::set_post_author_for_rest_save
 * @covers \CoAuthors_Plus::filter_assign_author_terms_cap
 */
class SetAuthorsPostTypeTest extends TestCase {

	/** @var \WP_User */
	private $editor;

	/** @var \WP_User */
	private $user;

	public function set_up() {
		parent::set_up();

		$this->editor = $this->create_editor();
		$this->user   = $this->create_author( 'posts_only_editor' );
		$this->user->add_cap( 'edit_others_posts' );
		$this->user->add_cap( 'edit_pages' );

		wp_set_current_user( $this->user->ID );
	}

	/**
	 * Creates a draft owned by the Editor, with the Editor and the user as co-authors.
	 *
	 * @param string $post_type Post type.
	 * @return int Post ID.
	 */
	private function create_shared_draft( string $post_type ): int {
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->editor->ID,
				'post_status' => 'draft',
				'post_type'   => $post_type,
			)
		);
		$this->_cap->add_coauthors( $post_id, array( $this->editor->user_login, $this->user->user_login ) );

		return $post_id;
	}

	/**
	 * @return string[] Co-author logins, in order.
	 */
	private function coauthor_logins( int $post_id ): array {
		return wp_list_pluck( get_coauthors( $post_id ), 'user_login' );
	}

	public function test_rest_refuses_coauthors_on_a_page(): void {
		$page_id = $this->create_shared_draft( 'page' );
		$this->assertTrue( current_user_can( 'edit_post', $page_id ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $page_id );
		$request->set_param( 'coauthors', array( $this->_cap->get_author_term( $this->user )->term_id ) );
		$request->set_param( 'author', $this->user->ID );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$this->assertSame( array( $this->editor->user_login, $this->user->user_login ), $this->coauthor_logins( $page_id ) );
		$this->assertSame( $this->editor->ID, (int) get_post( $page_id )->post_author );
	}

	public function test_tax_input_cannot_set_coauthors_on_a_page(): void {
		$page_id = $this->create_shared_draft( 'page' );

		wp_update_post(
			array(
				'ID'         => $page_id,
				'tax_input'  => array( $this->_cap->coauthor_taxonomy => array( $this->_cap->get_author_term( $this->user )->slug ) ),
				'post_title' => 'Saved with tax_input',
			)
		);

		$this->assertSame( 'Saved with tax_input', get_post( $page_id )->post_title );
		$this->assertSame( array( $this->editor->user_login, $this->user->user_login ), $this->coauthor_logins( $page_id ) );
	}

	public function test_rest_still_sets_coauthors_on_a_post(): void {
		$post_id = $this->create_shared_draft( 'post' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'coauthors', array( $this->_cap->get_author_term( $this->user )->term_id ) );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( array( $this->user->user_login ), $this->coauthor_logins( $post_id ) );
	}

	public function test_tax_input_still_sets_coauthors_on_a_post(): void {
		$post_id = $this->create_shared_draft( 'post' );

		wp_update_post(
			array(
				'ID'        => $post_id,
				'tax_input' => array( $this->_cap->coauthor_taxonomy => array( $this->_cap->get_author_term( $this->user )->slug ) ),
			)
		);

		$this->assertSame( array( $this->user->user_login ), $this->coauthor_logins( $post_id ) );
	}
}
