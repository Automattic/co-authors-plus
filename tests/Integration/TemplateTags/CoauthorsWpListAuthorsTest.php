<?php
/**
 * Tests for the coauthors_wp_list_authors() template tag.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers ::coauthors_wp_list_authors()
 */
class CoauthorsWpListAuthorsTest extends TestCase {

	/**
	 * Checks all the co-authors of the blog with default args.
	 */
	public function test_list_authors_with_default_args(): void {

		global $coauthors_plus;

		$author = $this->create_author();
		$editor = $this->create_editor();
		$post   = $this->create_post( $author );

		$args = array(
			'echo' => false,
		);

		// Because the author has a published post, but the editor doesn't, the author would be included but not the editor.
		$coauthors = coauthors_wp_list_authors( $args );

		$this->assertStringContainsString( 'href="' . get_author_posts_url( $author->ID, $author->user_nicename ) . '"', $coauthors, 'Author link not found.' );
		$this->assertStringContainsString( $author->display_name, $coauthors, 'Author name not found.' );

		$this->assertStringNotContainsString( 'href="' . get_author_posts_url( $editor->ID, $editor->user_nicename ) . '"', $coauthors );
		$this->assertStringNotContainsString( $editor->display_name, $coauthors );

		// Now add the editor as a co-author to a post.
		$coauthors_plus->add_coauthors( $post->ID, array( $editor->user_login ), true );

		$coauthors = coauthors_wp_list_authors( $args );

		$this->assertStringContainsString( 'href="' . get_author_posts_url( $author->ID, $author->user_nicename ) . '"', $coauthors, 'Main author link not found.' );
		$this->assertStringContainsString( $author->display_name, $coauthors, 'Main author name not found.' );

		// Here we are checking author name should not be more than one time.
		// Asserting ">{$author->display_name}<" because "$author->display_name" can be multiple times like in href, title, etc.
		$this->assertEquals( 1, substr_count( $coauthors, ">{$author->display_name}<" ) );

		$this->assertStringContainsString( '</li><li>', $coauthors, 'Co-authors name separator is not matched.' );
		$this->assertStringContainsString( 'href="' . get_author_posts_url( $editor->ID, $editor->user_nicename ) . '"', $coauthors, 'Co-author link not found.' );
		$this->assertStringContainsString( $editor->display_name, $coauthors, 'Co-author name not found.' );

		// Here we are checking editor name should not be more than one time.
		// Asserting ">{$editor->display_name}<" because "$editor->display_name" can be multiple times like in href, title, etc.
		$this->assertEquals( 1, substr_count( $coauthors, ">{$editor->display_name}<" ) );
	}

	/**
	 * Checks all the co-authors of the blog with optioncount option.
	 */
	public function test_list_authors_with_optioncount_arg_enabled(): void {
		$author = $this->create_author();
		$post   = $this->create_post( $author );

		$this->assertStringContainsString(
			'(' . count_user_posts( $author->ID ) . ')',
			coauthors_wp_list_authors(
				array(
					'echo'        => false,
					'optioncount' => true,
				)
			)
		);
	}

	/**
	 * Checks all the co-authors of the blog with show_fullname option.
	 */
	public function test_list_authors_with_show_fullname_arg_enabled(): void {
		$author = $this->create_author();
		$post   = $this->create_post( $author );

		$args = array(
			'echo'          => false,
			'show_fullname' => true,
		);

		$this->assertStringContainsString( $author->display_name, coauthors_wp_list_authors( $args ) );

		$user = $this->factory()->user->create_and_get(
			array(
				'first_name' => 'First',
				'last_name'  => 'Last',
			)
		);

		$this->factory()->post->create(
			array(
				'post_author' => $user->ID,
			)
		);

		$this->assertStringContainsString( "{$user->user_firstname} {$user->user_lastname}", coauthors_wp_list_authors( $args ) );
	}

	/**
	 * Checks all the co-authors of the blog with hide_empty option.
	 */
	public function test_list_authors_with_hide_empty_arg_enabled(): void {

		global $coauthors_plus;

		$coauthors_plus->guest_authors->create(
			array(
				'user_login'   => 'author2',
				'display_name' => 'author2',
			)
		);

		$this->assertStringContainsString(
			'author2',
			coauthors_wp_list_authors(
				array(
					'echo'       => false,
					'hide_empty' => false,
				)
			)
		);
	}

	/**
	 * Checks all the co-authors of the blog with feed option.
	 */
	public function test_list_authors_with_feed_arg_enabled(): void {
		$author = $this->create_author();
		$post   = $this->create_post( $author );

		$feed_text = 'link to feed';
		$coauthors = coauthors_wp_list_authors(
			array(
				'echo' => false,
				'feed' => $feed_text,
			)
		);

		$this->assertStringContainsString( esc_url( get_author_feed_link( $author->ID ) ), $coauthors );
		$this->assertStringContainsString( $feed_text, $coauthors );
	}

	/**
	 * Checks all the co-authors of the blog with feed_image option.
	 */
	public function test_list_authors_with_feed_image_arg_enabled(): void {
		$author = $this->create_author();
		$post   = $this->create_post( $author );

		$feed_image = includes_url( 'images/w-logo-blue-white-bg.png' );
		$coauthors  = coauthors_wp_list_authors(
			array(
				'echo'       => false,
				'feed_image' => $feed_image,
			)
		);

		$this->assertStringContainsString( esc_url( get_author_feed_link( $author->ID ) ), $coauthors );
		$this->assertStringContainsString( 'w-logo-blue-white-bg.png', $coauthors );
	}

	/**
	 * Checks all the co-authors of the blog with feed_type option.
	 */
	public function test_list_authors_with_feed_type_arg_enabled(): void {
		$author = $this->create_author();
		$post   = $this->create_post( $author );

		$feed_type = 'atom';
		$feed_text = 'link to feed';
		$coauthors = coauthors_wp_list_authors(
			array(
				'echo'      => false,
				'feed_type' => $feed_type,
				'feed'      => $feed_text,
			)
		);

		$this->assertStringContainsString( esc_url( get_author_feed_link( $author->ID, $feed_type ) ), $coauthors );
		$this->assertStringContainsString( $feed_type, $coauthors );
		$this->assertStringContainsString( $feed_text, $coauthors );
	}

	/**
	 * Checks all the co-authors of the blog with style option.
	 */
	public function test_list_authors_with_style_arg_enabled(): void {
		$author = $this->create_author();
		$editor = $this->create_editor();
		$this->create_post( $author );
		$this->create_post( $editor );
		$this->create_guest_author( 'empty_guest' );

		$coauthors = coauthors_wp_list_authors(
			array(
				'echo'  => false,
				'style' => 'none',
			)
		);

		$this->assertStringNotContainsString( '<li>', $coauthors );
		$this->assertStringNotContainsString( '</li>', $coauthors );
		$this->assertStringContainsString( 'href="' . get_author_posts_url( $author->ID, $author->user_nicename ) . '"', $coauthors, 'Author link not found.' );
		$this->assertStringContainsString( 'href="' . get_author_posts_url( $editor->ID, $editor->user_nicename ) . '"', $coauthors, 'Editor link not found.' );
		$this->assertStringContainsString( '</a>, <a ', $coauthors, 'Authors are not comma-separated.' );
		$this->assertStringNotContainsString( 'empty_guest', $coauthors, 'Author without posts should be hidden.' );
		$this->assertStringEndsWith( '</a>', $coauthors, 'Trailing separator not trimmed.' );
	}

	/**
	 * Checks a non-list style still includes authors without posts when hide_empty is disabled.
	 */
	public function test_list_authors_with_style_arg_enabled_and_hide_empty_disabled(): void {
		$author = $this->create_author();
		$this->create_post( $author );
		$this->create_guest_author( 'empty_guest' );

		$coauthors = coauthors_wp_list_authors(
			array(
				'echo'       => false,
				'style'      => 'none',
				'hide_empty' => false,
			)
		);

		$this->assertStringNotContainsString( '<li>', $coauthors );
		$this->assertStringContainsString( 'href="' . get_author_posts_url( $author->ID, $author->user_nicename ) . '"', $coauthors, 'Author link not found.' );
		$this->assertStringContainsString( ', empty_guest', $coauthors, 'Author without posts not listed.' );
	}

	/**
	 * Checks all the co-authors of the blog with html option.
	 */
	public function test_list_authors_with_html_arg_enabled(): void {
		global $coauthors_plus;

		$author = $this->create_author();
		$editor = $this->create_editor();
		$post   = $this->create_post( $author );

		$args = array(
			'echo' => false,
			'html' => false,
		);

		$this->assertEquals( $author->display_name, coauthors_wp_list_authors( $args ) );

		$coauthors_plus->add_coauthors( $post->ID, array( $editor->user_login ), true );

		$this->assertEquals( "{$author->display_name}, {$editor->display_name}", coauthors_wp_list_authors( $args ) );
	}

	/**
	 * Checks all the co-authors of the blog with guest_authors_only option.
	 */
	public function test_list_authors_with_guest_authors_only_arg_enabled(): void {

		global $coauthors_plus;

		$author = $this->create_author();
		$post   = $this->create_post( $author );

		$args = array(
			'echo'               => false,
			'guest_authors_only' => true,
		);

		$this->assertEmpty( coauthors_wp_list_authors( $args ) );

		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'user_login'   => 'author2',
				'display_name' => 'author2',
			)
		);

		$this->assertEmpty( coauthors_wp_list_authors( $args ) );

		$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'id', $guest_author_id );

		$coauthors_plus->add_coauthors( $post->ID, array( $guest_author->user_login ), true );

		$this->assertStringContainsString( $guest_author->display_name, coauthors_wp_list_authors( $args ) );
	}

	/**
	 * A linked guest author can share one term with their user. They're the
	 * only entry for that person, so removing duplicates mustn't remove them.
	 *
	 * @covers ::coauthors_get_users()
	 */
	public function test_linked_guest_author_sharing_users_term_is_listed(): void {
		$author       = $this->create_author( 'sharedterm' );
		$guest_author = $this->create_linked_guest_author( $author );

		// Precondition for this scenario: the pair has one term.
		$this->assertSame( $author->user_nicename, $guest_author->user_nicename );

		$this->factory()->post->create_many(
			3,
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
			)
		);

		$authors = coauthors_get_users();

		$this->assertCount( 1, $authors );
		$author_entry = reset( $authors );
		$this->assertSame( 'guest-author', $author_entry->type );
		$this->assertSame( 3, $author_entry->post_count );
	}

	/**
	 * The login is the term's name and the link's value, so a nicename that
	 * differs from it doesn't stop the pair sharing a term.
	 *
	 * @covers ::coauthors_get_users()
	 */
	public function test_linked_guest_author_sharing_term_with_user_whose_login_differs_from_nicename_is_listed(): void {
		$author = $this->factory()->user->create_and_get(
			array(
				'role'          => 'author',
				'user_login'    => 'jo.bloggs',
				'user_nicename' => 'jo-bloggs',
				'display_name'  => 'Jo Bloggs',
			)
		);

		$this->factory()->post->create(
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
			)
		);

		$guest_author = $this->create_linked_guest_author( $author );

		// Precondition for this scenario: the pair has one term.
		$this->assertSame( $author->user_nicename, $guest_author->user_nicename );

		$this->assertCount( 1, coauthors_get_users() );
	}

	/**
	 * A pair with a term each is still listed once, with the posts on either
	 * term, and a post on both counts once.
	 *
	 * @covers ::coauthors_get_users()
	 */
	public function test_linked_guest_author_with_own_term_is_listed_once_with_all_posts(): void {
		global $coauthors_plus;

		$author = $this->create_author_with_own_slug_display_name();

		// Posts from before the link have the user's own term.
		$post_ids = $this->factory()->post->create_many(
			3,
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
			)
		);

		$guest_author = $this->create_linked_guest_author( $author );

		// Precondition for this scenario: the pair has two different terms.
		$this->assertNotSame( $author->user_nicename, $guest_author->user_nicename );

		foreach ( $this->factory()->post->create_many( 2, array( 'post_status' => 'publish' ) ) as $post_id ) {
			$coauthors_plus->add_coauthors( $post_id, array( $guest_author->user_login ) );
		}

		wp_add_object_terms( $post_ids[0], $coauthors_plus->get_author_term( $guest_author )->term_id, $coauthors_plus->coauthor_taxonomy );

		$authors = coauthors_get_users();

		$this->assertCount( 1, $authors );
		$this->assertSame( 5, reset( $authors )->post_count );
	}

	/**
	 * A pair's posts can all be on the user's term, which hide_empty mustn't
	 * mistake for a person with no posts.
	 *
	 * @covers ::coauthors_wp_list_authors()
	 */
	public function test_linked_guest_author_with_posts_only_on_users_term_is_not_hidden_as_empty(): void {
		$author = $this->create_author_with_own_slug_display_name();

		$this->factory()->post->create_many(
			3,
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
			)
		);

		$this->create_linked_guest_author( $author );

		$this->assertStringContainsString(
			$author->display_name . '</a> (3)',
			coauthors_wp_list_authors(
				array(
					'echo'        => false,
					'optioncount' => true,
				)
			)
		);
	}

	/**
	 * Only published posts are counted, as for anyone else in the list.
	 *
	 * @covers ::coauthors_get_users()
	 */
	public function test_linked_guest_author_with_own_term_count_only_includes_published_posts(): void {
		$author = $this->create_author_with_own_slug_display_name();

		$this->factory()->post->create(
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
			)
		);
		$this->factory()->post->create(
			array(
				'post_author' => $author->ID,
				'post_status' => 'private',
			)
		);

		$this->create_linked_guest_author( $author );

		$authors = coauthors_get_users();

		$this->assertSame( 1, reset( $authors )->post_count );
	}

	/**
	 * Creates an author whose display name gives their guest author a different slug.
	 *
	 * @return \WP_User The created user.
	 */
	private function create_author_with_own_slug_display_name(): \WP_User {
		return $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => 'ownslug',
				'display_name' => 'Own Slug Author',
			)
		);
	}

	/**
	 * Creates a guest author linked to the given user.
	 *
	 * @param \WP_User $user User to link the guest author to.
	 * @return object The guest author.
	 */
	private function create_linked_guest_author( \WP_User $user ) {
		global $coauthors_plus;

		return $coauthors_plus->guest_authors->get_guest_author_by(
			'ID',
			$coauthors_plus->guest_authors->create_guest_author_from_user_id( $user->ID )
		);
	}
}
