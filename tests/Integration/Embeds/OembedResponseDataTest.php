<?php
/**
 * Integration tests for CoAuthors_Plus::filter_oembed_response_data().
 *
 * @see https://github.com/Automattic/co-authors-plus/issues/921
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Embeds;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers \CoAuthors_Plus::filter_oembed_response_data
 */
class OembedResponseDataTest extends TestCase {

	/**
	 * The WordPress user who published the posts under test.
	 *
	 * Deliberately not one of the co-authors, so that a byline resolved from
	 * `post_author` is visibly different from one resolved from the co-authors.
	 *
	 * @var \WP_User
	 */
	private $publisher;

	public function set_up() {
		parent::set_up();

		$this->set_permalink_structure( '/%postname%/' );

		$this->publisher = $this->factory()->user->create_and_get(
			array(
				'role'         => 'editor',
				'user_login'   => 'oembed-publishing-editor',
				'display_name' => 'Publishing Editor',
				'user_url'     => 'https://publisher.example',
			)
		);
	}

	public function tear_down() {
		$this->set_permalink_structure( '' );
		$this->_cap->force_guest_authors = false;

		parent::tear_down();
	}

	/**
	 * Verify that oEmbed response data preserves expected output for a single user co-author.
	 */
	public function test_filter_oembed_response_data_with_single_user_coauthor(): void {
		$coauthor = $this->create_author( 'oembed_single_coauthor' );
		$post     = $this->create_post( $this->publisher );

		$this->_cap->add_coauthors( $post->ID, array( $coauthor->user_login ) );

		$data = get_oembed_response_data( $post, 400 );

		$this->assertIsArray( $data );
		$this->assertSame( $coauthor->display_name, $data['author_name'] );
		$this->assertSame( get_author_posts_url( $coauthor->ID, $coauthor->user_nicename ), $data['author_url'] );
	}

	/**
	 * Verify that oEmbed response data resolves a solo guest author instead of the publishing user.
	 */
	public function test_filter_oembed_response_data_with_guest_author(): void {
		$guest_author_id = $this->create_guest_author( 'oembed_solo_guest' );
		$guest_author    = $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_author_id );
		$post            = $this->create_post( $this->publisher );

		$this->_cap->add_coauthors( $post->ID, array( $guest_author->user_login ) );

		$data = get_oembed_response_data( $post, 400 );

		$this->assertIsArray( $data );
		$this->assertSame( $guest_author->display_name, $data['author_name'] );
		$this->assertSame( get_author_posts_url( $guest_author->ID, $guest_author->user_nicename ), $data['author_url'] );
		$this->assertNotSame( $this->publisher->display_name, $data['author_name'] );
	}

	/**
	 * Verify that a post with a guest author and a user author uses the first co-author over post_author.
	 */
	public function test_filter_oembed_response_data_with_guest_and_user_uses_first_guest_author(): void {
		$guest_author_id = $this->create_guest_author( 'oembed_guest_lead' );
		$guest_author    = $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_author_id );
		$user_author     = $this->create_author( 'oembed_user_second' );
		$post            = $this->create_post( $this->publisher );

		$this->_cap->add_coauthors( $post->ID, array( $guest_author->user_login, $user_author->user_login ) );

		// Co-Authors Plus sets post_author to the user author since guest authors lack a WP user ID.
		$this->assertSame( $user_author->ID, (int) get_post_field( 'post_author', $post->ID ) );

		$data = get_oembed_response_data( $post, 400 );

		$this->assertIsArray( $data );
		$this->assertSame( $guest_author->display_name, $data['author_name'] );
		$this->assertSame( get_author_posts_url( $guest_author->ID, $guest_author->user_nicename ), $data['author_url'] );
		$this->assertNotSame( $user_author->display_name, $data['author_name'] );
	}

	/**
	 * Verify that a guest author linked to a WordPress account uses the guest author's display name and archive URL.
	 */
	public function test_filter_oembed_response_data_with_guest_author_linked_to_account(): void {
		$linked_user     = $this->create_author( 'oembed_linked_user' );
		$guest_author_id = $this->_cap->guest_authors->create(
			array(
				'user_login'     => 'oembed-linked-guest',
				'display_name'   => 'Guest Pen Name',
				'linked_account' => $linked_user->user_login,
			)
		);
		$guest_author    = $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_author_id );
		$post            = $this->create_post( $this->publisher );

		$this->_cap->add_coauthors( $post->ID, array( $guest_author->user_login ) );

		$data = get_oembed_response_data( $post, 400 );

		$this->assertIsArray( $data );
		$this->assertSame( 'Guest Pen Name', $data['author_name'] );
		$this->assertSame( get_author_posts_url( $guest_author->ID, $guest_author->user_nicename ), $data['author_url'] );
		$this->assertNotSame( $linked_user->display_name, $data['author_name'] );
	}

	/**
	 * Verify that core byline behaviour is preserved when no co-authors are assigned.
	 */
	public function test_filter_oembed_response_data_preserves_core_when_no_coauthors_assigned(): void {
		$this->_cap->force_guest_authors = true;

		$author = $this->create_author( 'oembed_sole_core_author' );
		$post   = $this->create_post( $author );

		// Clear all author terms so get_coauthors() returns empty array when force_guest_authors is true.
		wp_set_object_terms( $post->ID, array(), $this->_cap->coauthor_taxonomy );

		$this->assertEmpty( get_coauthors( $post->ID ) );

		$data = get_oembed_response_data( $post, 400 );

		$this->assertIsArray( $data );
		$this->assertSame( $author->display_name, $data['author_name'] );
		$this->assertSame( get_author_posts_url( $author->ID ), $data['author_url'] );
	}

	/**
	 * Verify that the filter returns data untouched for unsupported post types.
	 */
	public function test_filter_oembed_response_data_bails_on_unsupported_post_type(): void {
		register_post_type( 'unsupported_cpt', array( 'public' => true ) );

		$post = $this->factory()->post->create_and_get(
			array(
				'post_author' => $this->publisher->ID,
				'post_type'   => 'unsupported_cpt',
			)
		);

		$data = array(
			'author_name' => 'Original CPT Author',
			'author_url'  => 'https://cpt.example',
		);

		$result = $this->_cap->filter_oembed_response_data( $data, $post );

		$this->assertSame( $data, $result );
	}

	/**
	 * Verify that non-array data returns untouched.
	 */
	public function test_filter_oembed_response_data_bails_on_non_array_data(): void {
		$post = $this->create_post( $this->publisher );

		$this->assertFalse( $this->_cap->filter_oembed_response_data( false, $post ) );
	}
}
