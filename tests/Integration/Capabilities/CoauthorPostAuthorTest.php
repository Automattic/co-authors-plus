<?php
/**
 * Tests that a co-author who can edit a post can't make themselves its post_author.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Capabilities;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_REST_Request;

/**
 * Core only asks for edit_others_posts when the new author is someone else, because
 * in core anyone who can edit another user's post has it. A co-author can edit the
 * post without it, so post_author must still follow the first co-author.
 *
 * @covers \CoAuthors_Plus::coauthors_set_post_author_field
 * @covers \CoAuthors_Plus::set_post_author_for_rest_save
 */
class CoauthorPostAuthorTest extends TestCase {

	/** @var \WP_User */
	private $editor;

	/** @var \WP_User */
	private $contributor;

	/** @var int */
	private $post_id;

	public function set_up() {
		parent::set_up();

		$this->editor      = $this->create_editor();
		$this->contributor = $this->create_contributor();
		$this->post_id     = $this->factory()->post->create(
			array(
				'post_author' => $this->editor->ID,
				'post_status' => 'draft',
			)
		);
		$this->_cap->add_coauthors( $this->post_id, array( $this->editor->user_login, $this->contributor->user_login ) );

		wp_set_current_user( $this->contributor->ID );
	}

	public function tear_down() {
		$_POST = array();
		unset( $_REQUEST['_wpnonce'], $_REQUEST['coauthors-nonce'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_a_coauthor_cannot_take_post_author_over_rest(): void {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id );
		$request->set_param( 'author', $this->contributor->ID );
		$request->set_param( 'title', 'Saved over REST' );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( 'Saved over REST', get_post( $this->post_id )->post_title );
		$this->assertSame( $this->editor->ID, (int) get_post( $this->post_id )->post_author );
	}

	public function test_a_coauthor_cannot_take_post_author_with_refused_classic_form_fields(): void {
		set_current_screen( 'post' );

		$_POST = array(
			'post_ID'         => $this->post_id,
			'post_author'     => $this->contributor->ID,
			'post_title'      => 'Saved in the classic editor',
			'coauthors-nonce' => 'not-a-nonce',
			'coauthors'       => array( $this->contributor->user_login ),
		);

		$_REQUEST['coauthors-nonce'] = 'not-a-nonce';
		$_REQUEST['_wpnonce']        = wp_create_nonce( 'update-post_' . $this->post_id );
		$this->assertSame( 1, check_admin_referer( 'update-post_' . $this->post_id ) );

		$this->assertSame( $this->post_id, edit_post() );
		$this->assertSame( 'Saved in the classic editor', get_post( $this->post_id )->post_title );
		$this->assertSame( $this->editor->ID, (int) get_post( $this->post_id )->post_author );
	}

	/**
	 * Reordering is the only way to change post_author, for those who can set authors too.
	 */
	public function test_an_editor_rest_save_without_coauthors_keeps_post_author_on_the_first_coauthor(): void {
		wp_set_current_user( $this->editor->ID );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id );
		$request->set_param( 'author', $this->contributor->ID );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( $this->editor->ID, (int) get_post( $this->post_id )->post_author );
	}
}
