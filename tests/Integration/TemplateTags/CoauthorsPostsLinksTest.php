<?php
/**
 * Tests for the coauthors_posts_links() template tag.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers ::coauthors_posts_links()
 */
class CoauthorsPostsLinksTest extends TestCase {

	/**
	 * Test the author filter is retained.
	 */
	public function test_the_author_filter_is_retained(): void {
		global $coauthors_plus_template_filters;
		$coauthors_plus_template_filters = new \CoAuthors_Template_Filters();
		$coauthors_plus_template_filters->register_hooks();
		$this->assertEquals( 10, has_filter( 'the_author', array( $coauthors_plus_template_filters, 'filter_the_author' ) ) );
	}

	/**
	 * Test that single author posts link is retrieved via coauthors_posts_links_single(),
	 * and suitably prefixed / suffixed.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/279
	 */
	public function test_coauthors_posts_links_for_single_author(): void {
		$author = $this->create_author();
		$post   = $this->create_post( $author );
		$GLOBALS['post'] = $post;

		$coauthors_posts_links = coauthors_posts_links( null, null, null, null, false );

		$this->assertEquals(
			'<a href="' . get_author_posts_url( $author->ID, $author->user_nicename ) . '" title="Posts by author" class="author url fn" rel="author">author</a>',
			$coauthors_posts_links,
			'Single author post link incorrect.'
		);
	}

	/**
	 * Test co-author posts links are retrieved for multiple authors and default args.
	 */
	public function test_coauthors_posts_links_for_multiple_authors_with_default_args(): void {
		global $coauthors_plus;

		$author = $this->create_author();
		$editor = $this->create_editor();
		$post   = $this->create_post( $author );
		$GLOBALS['post'] = $post;
		$coauthors_plus->add_coauthors( $post->ID, array( $editor->user_login ), true );

		$coauthors_posts_links = coauthors_posts_links( null, null, null, null, false );

		$this->assertEquals(
			'<a href="' . get_author_posts_url( $author->ID, $author->user_nicename ) . '" title="Posts by author" class="author url fn" rel="author">author</a> and <a href="' . get_author_posts_url( $editor->ID, $editor->user_nicename ) . '" title="Posts by editor" class="author url fn" rel="author">editor</a>',
			$coauthors_posts_links,
			'Multiple author post links incorrect.'
		);
	}

		/**
		 * Test that a guest author byline links to the guest author archive rather
		 * than the user who originally published the post, with pretty permalinks.
		 *
		 * @see https://github.com/Automattic/co-authors-plus/issues/1351
		 */
	public function test_coauthors_posts_links_for_single_guest_author_with_pretty_permalinks(): void {
		$this->assert_single_guest_author_byline_links_to_guest_archive( '/%postname%/' );
	}

	/**
	 * Test that a guest author byline links to the guest author archive rather
	 * than the user who originally published the post, with plain permalinks.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/1351
	 */
	public function test_coauthors_posts_links_for_single_guest_author_with_plain_permalinks(): void {
		$this->assert_single_guest_author_byline_links_to_guest_archive( '' );
	}

		/**
		 * Test the old-theme pattern used with coauthors_auto_apply_template_tags,
		 * with pretty permalinks.
		 *
		 * @see https://github.com/Automattic/co-authors-plus/issues/1351
		 */
	public function test_old_theme_author_link_uses_guest_author_archive_with_pretty_permalinks(): void {
		$this->assert_old_theme_author_link_uses_guest_author_archive( '/%postname%/' );
	}

		/**
		 * Test the old-theme pattern used with coauthors_auto_apply_template_tags,
		 * with plain permalinks.
		 *
		 * @see https://github.com/Automattic/co-authors-plus/issues/1351
		 */
	public function test_old_theme_author_link_uses_guest_author_archive_with_plain_permalinks(): void {
		$this->assert_old_theme_author_link_uses_guest_author_archive( '' );
	}

	/**
	 * Test that without coauthors_auto_apply_template_tags, an old-theme link
	 * built from post_author still points at the post owner's archive.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/1351
	 */
	public function test_old_theme_author_link_keeps_owner_archive_without_auto_apply_template_tags(): void {
		global $coauthors_plus;

		$this->set_permalink_structure( '/%postname%/' );

		$publisher = $this->create_author( 'publisher-bob' );
		$this->create_guest_author( 'jane-guest' );
		$post = $this->create_post( $publisher );
		$coauthors_plus->add_coauthors( $post->ID, array( 'jane-guest' ), false );

		$link = $this->render_old_theme_byline( $post->ID );

		$this->assertStringContainsString( home_url( '/author/publisher-bob/' ), $link );
		$this->assertStringNotContainsString( '/author/jane-guest/', $link );
	}

	/**
	 * Test that a user whose ID matches the guest author's post ID keeps their
	 * own archive link, because user IDs and post IDs are unrelated.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/1351
	 */
	public function test_old_theme_author_link_keeps_user_archive_when_user_id_matches_guest_author_id(): void {
		global $coauthors_plus, $wpdb;

		add_filter( 'coauthors_auto_apply_template_tags', '__return_true' );
		$this->set_permalink_structure( '/%postname%/' );

		$publisher = $this->create_author( 'publisher-bob' );
		$guest_id  = $this->create_guest_author( 'jane-guest' );
		$post      = $this->create_post( $publisher );
		$coauthors_plus->add_coauthors( $post->ID, array( 'jane-guest' ), false );

		// wp_insert_user() ignores an ID on insert, so reserve the guest author's
		// post ID for a user directly.
		$this->assertFalse( get_userdata( $guest_id ), 'No user has the guest author post ID yet.' );
		$inserted = $wpdb->insert(
			// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users -- Test-only: a user must share the guest author's post ID.
			$wpdb->users,
			array(
				'ID'              => $guest_id,
				'user_login'      => 'id-twin',
				'user_pass'       => 'password',
				'user_nicename'   => 'id-twin',
				'user_email'      => 'id-twin@example.org',
				'user_registered' => current_time( 'mysql' ),
				'display_name'    => 'id-twin',
			)
		);
		$this->assertSame( 1, $inserted, 'The id-twin user was inserted.' );
		clean_user_cache( $guest_id );

		$this->go_to( get_permalink( $post->ID ) );

		$this->assertSame( home_url( '/author/id-twin/' ), get_author_posts_url( $guest_id ) );
		$this->assertSame( home_url( '/author/jane-guest/' ), get_author_posts_url( $publisher->ID ) );
	}

	/**
	 * Assert that an old-theme byline using get_the_author_meta( 'ID' ) for the
	 * href and get_the_author() for the label points at the guest archive when
	 * coauthors_auto_apply_template_tags is on.
	 *
	 * @param string $permalink_structure Permalink structure to test with.
	 */
	private function assert_old_theme_author_link_uses_guest_author_archive( string $permalink_structure ): void {
		global $coauthors_plus;

		add_filter( 'coauthors_auto_apply_template_tags', '__return_true' );
		$this->set_permalink_structure( $permalink_structure );

		$publisher = $this->create_author( 'publisher-bob' );
		$this->create_guest_author( 'jane-guest' );
		$post      = $this->create_post( $publisher );
		$coauthors_plus->add_coauthors( $post->ID, array( 'jane-guest' ), false );

		$link = $this->render_old_theme_byline( $post->ID );

		$expected_href = $permalink_structure
			? home_url( '/author/jane-guest/' )
			: add_query_arg( 'author_name', 'jane-guest', home_url() );

		$this->assertStringContainsString( $expected_href, $link );
		$this->assertStringNotContainsString( 'publisher-bob', $link );
		$this->assertSame( $publisher->ID, (int) get_the_author_meta( 'ID' ) );
	}

	/**
	 * Render a post's byline the way old themes do: the href from
	 * get_the_author_meta( 'ID' ) and the label from get_the_author().
	 *
	 * The template filters are registered directly, because
	 * coauthors_auto_apply_template_tags is only read on init.
	 *
	 * @param int $post_id Post to render.
	 * @return string Byline markup.
	 */
	private function render_old_theme_byline( int $post_id ): string {
		$filters = new \CoAuthors_Template_Filters();
		$filters->register_hooks();
		$this->go_to( get_permalink( $post_id ) );

		// Old themes render the byline inside the loop, where the_post() sets
		// $authordata from the post_author field (the original publisher).
		$link = '';
		while ( have_posts() ) {
			the_post();
			$link = sprintf(
				'<a href="%s">%s</a>',
				esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ),
				esc_html( get_the_author() )
			);
		}

		return $link;
	}

	/**
	 * Assert that a post whose single coauthor is a guest author renders a byline
	 * pointing to the guest author's own archive, and not to the user who
	 * originally published the post.
	 *
	 * The expected URL is built from the guest author's nicename directly rather
	 * than by re-running the production author_link filter, so expected and actual
	 * cannot share a source of truth.
	 *
	 * @param string $permalink_structure Permalink structure to test with.
	 */
	private function assert_single_guest_author_byline_links_to_guest_archive( string $permalink_structure ): void {
		global $coauthors_plus;

		$this->set_permalink_structure( $permalink_structure );

		$publisher    = $this->create_author( 'publisher-bob' );
		$guest_author = $this->create_guest_author( 'jane-guest' );
		$guest_object = $coauthors_plus->guest_authors->get_guest_author_by( 'ID', $guest_author );
		$post         = $this->create_post( $publisher );
		$coauthors_plus->add_coauthors( $post->ID, array( 'jane-guest' ), false );

		$coauthors = get_coauthors( $post->ID );
		$this->assertCount( 1, $coauthors );
		$this->assertIsGuestAuthorNotWpUser( $coauthors[0] );
		$this->assertSame( 'jane-guest', $coauthors[0]->user_nicename );

		// Simulate the frontend rendering of the byline from a fresh URL context.
		$this->go_to( get_permalink( $post->ID ) );

		$expected_href = $permalink_structure
			? home_url( '/author/jane-guest/' )
			: add_query_arg( 'author_name', 'jane-guest', home_url() );

		$byline = coauthors_posts_links( null, null, null, null, false );

		$this->assertSame(
			'<a href="' . $expected_href . '" title="Posts by jane-guest" class="author url fn" rel="author">jane-guest</a>',
			$byline,
			'Guest author post link does not point to the guest author archive.'
		);
		$this->assertStringNotContainsString(
			'publisher-bob',
			$byline,
			'Byline must not link to the user who originally published the post.'
		);
	}

	/**
	 * Test that co-author posts link is retrieved via coauthors_posts_links_single() but for multiple authors.
	 */
	public function test_coauthors_posts_links_for_multiple_authors_with_amended_args(): void {
		global $coauthors_plus;

		$author1 = $this->create_author( 'author1' );
		$author2 = $this->create_author( 'author2' );
		$editor = $this->create_editor();
		$post   = $this->create_post( $author1 );
		$GLOBALS['post'] = $post;
		$coauthors_plus->add_coauthors( $post->ID, array( $author2->user_login, $editor->user_login ), true );

		$coauthors_posts_links = coauthors_posts_links( ' and ', ' & ', 'By ', '.', false );

		$this->assertEquals(
			'By <a href="' . get_author_posts_url( $author1->ID, $author1->user_nicename ) . '" title="Posts by author1" class="author url fn" rel="author">author1</a> and <a href="' . get_author_posts_url( $author2->ID, $author2->user_nicename ) . '" title="Posts by author2" class="author url fn" rel="author">author2</a> & <a href="' . get_author_posts_url( $editor->ID, $editor->user_nicename ) . '" title="Posts by editor" class="author url fn" rel="author">editor</a>.',
			$coauthors_posts_links,
			'Multiple author post links incorrect.'
		);
	}
}
