<?php

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

		$coauthors = coauthors_wp_list_authors(
			array(
				'echo'  => false,
				'style' => 'none',
			)
		);

		$this->assertStringNotContainsString( '<li>', $coauthors );
		$this->assertStringNotContainsString( '</li>', $coauthors );
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
}
