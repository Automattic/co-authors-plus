<?php
/**
 * Test which author each loop on an author archive shows.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Queries;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_Query;

/**
 * @covers \CoAuthors_Plus::fix_author_page_for_main_query_post()
 * @covers \CoAuthors_Plus::fix_author_page()
 */
class AuthorArchiveLoopsTest extends TestCase {

	/**
	 * The author whose archive is viewed.
	 *
	 * @var \WP_User
	 */
	private $archive_author;

	/**
	 * Another author, with no post on the archive unless a test adds one.
	 *
	 * @var \WP_User
	 */
	private $other_author;

	/**
	 * A post by the archive's author.
	 *
	 * @var \WP_Post
	 */
	private $archive_post;

	/**
	 * A post by the other author, which isn't on the archive.
	 *
	 * @var \WP_Post
	 */
	private $other_post;

	public function set_up() {
		parent::set_up();

		$this->archive_author = $this->create_named_author( 'alice', 'Alice Archive' );
		$this->other_author   = $this->create_named_author( 'bob', 'Bob Other' );
		$this->archive_post   = $this->create_post( $this->archive_author );
		$this->other_post     = $this->create_post( $this->other_author );
	}

	private function create_named_author( string $user_login, string $display_name ): \WP_User {
		return $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => $user_login,
				'display_name' => $display_name,
				'description'  => $display_name . ' bio',
			)
		);
	}

	/**
	 * A post bylined to the other author and the archive's author, in that order,
	 * so its post_author isn't the archive's author but it's still on the archive.
	 */
	private function create_shared_post(): \WP_Post {
		$post = $this->create_post( $this->other_author );
		$this->_cap->add_coauthors( $post->ID, array( $this->other_author->user_login, $this->archive_author->user_login ) );

		return $post;
	}

	/**
	 * Run a query's loop, keyed by post ID, with the author shown for each post.
	 *
	 * @return array<int, string>
	 */
	private function authors_in_loop( WP_Query $query ): array {
		$authors = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$authors[ get_the_ID() ] = get_the_author();
		}

		return $authors;
	}

	/**
	 * Query the two posts created in set_up(), as a sidebar widget might.
	 */
	private function secondary_query(): WP_Query {
		return new WP_Query(
			array(
				'post__in' => array( $this->archive_post->ID, $this->other_post->ID ),
				'orderby'  => 'post__in',
			)
		);
	}

	/**
	 * An archive's own loop credits its author on a co-authored post whose
	 * post_author is someone else.
	 */
	public function test_main_loop_shows_the_archive_author_on_a_shared_post(): void {
		$shared_post = $this->create_shared_post();

		$this->go_to( home_url( '/?author_name=alice' ) );

		$this->assertTrue( is_author() );
		$this->assertSame(
			array(
				$this->archive_post->ID => 'Alice Archive',
				$shared_post->ID        => 'Alice Archive',
			),
			$this->sort_keys( $this->authors_in_loop( $GLOBALS['wp_query'] ) )
		);
	}

	/**
	 * A guest author's posts have a post_author who isn't the guest author.
	 */
	public function test_main_loop_shows_the_guest_author_on_their_archive(): void {
		$this->_cap->guest_authors->create(
			array(
				'display_name' => 'Gina Guest',
				'user_login'   => 'gina',
			)
		);
		$guest_post = $this->create_post( $this->other_author );
		$this->_cap->add_coauthors( $guest_post->ID, array( 'gina' ) );

		$this->go_to( home_url( '/?author_name=gina' ) );

		$this->assertTrue( is_author() );
		$this->assertSame( array( $guest_post->ID => 'Gina Guest' ), $this->authors_in_loop( $GLOBALS['wp_query'] ) );
	}

	public function test_secondary_loop_shows_each_posts_own_author(): void {
		$this->go_to( home_url( '/?author_name=alice' ) );

		$this->assertSame(
			array(
				$this->archive_post->ID => 'Alice Archive',
				$this->other_post->ID   => 'Bob Other',
			),
			$this->authors_in_loop( $this->secondary_query() )
		);
	}

	/**
	 * The same on a guest author's archive, where the archive's author isn't a user.
	 */
	public function test_secondary_loop_shows_each_posts_own_author_on_a_guest_archive(): void {
		$this->_cap->guest_authors->create(
			array(
				'display_name' => 'Gina Guest',
				'user_login'   => 'gina',
			)
		);
		$this->_cap->add_coauthors( $this->create_post( $this->other_author )->ID, array( 'gina' ) );

		$this->go_to( home_url( '/?author_name=gina' ) );

		$this->assertSame(
			array(
				$this->archive_post->ID => 'Alice Archive',
				$this->other_post->ID   => 'Bob Other',
			),
			$this->authors_in_loop( $this->secondary_query() )
		);
	}

	/**
	 * Core's setup_postdata() passes the main query along with a post that isn't in it.
	 */
	public function test_setup_postdata_on_another_post_shows_that_posts_author(): void {
		$this->go_to( home_url( '/?author_name=alice' ) );

		setup_postdata( $this->other_post );

		$this->assertSame( 'Bob Other', get_the_author() );
	}

	public function test_latest_posts_block_shows_each_posts_own_author(): void {
		$this->go_to( home_url( '/?author_name=alice' ) );

		$block = do_blocks( '<!-- wp:latest-posts {"displayAuthor":true} /-->' );

		$this->assertStringContainsString( 'by Alice Archive', $block );
		$this->assertStringContainsString( 'by Bob Other', $block );
	}

	/**
	 * A theme can print the author's name and bio after a sidebar's loop.
	 */
	public function test_wp_reset_postdata_restores_the_archive_author(): void {
		$this->go_to( home_url( '/?author_name=alice' ) );

		$this->authors_in_loop( $this->secondary_query() );
		wp_reset_postdata();

		$this->assertSame( 'Alice Archive', get_the_author() );
		$this->assertSame( 'Alice Archive bio', get_the_author_meta( 'description' ) );
	}

	/**
	 * The Latest Posts block restores the global post with setup_postdata(), and
	 * after rewind_posts() that's no longer the main query's current post.
	 */
	public function test_setup_postdata_on_a_main_query_post_after_rewinding_restores_the_archive_author(): void {
		$first_shared_post  = $this->create_shared_post();
		$second_shared_post = $this->create_shared_post();

		$this->go_to( home_url( '/?author_name=alice' ) );

		$this->authors_in_loop( $GLOBALS['wp_query'] );
		rewind_posts();
		$this->authors_in_loop( $this->secondary_query() );

		// Either could sort first, so take the one that isn't the current post.
		$is_first_current = $first_shared_post->ID === $GLOBALS['wp_query']->post->ID;
		setup_postdata( $is_first_current ? $second_shared_post : $first_shared_post );

		$this->assertSame( 'Alice Archive', get_the_author() );
	}

	/**
	 * The archive's own author data must not follow the posts a loop sets up.
	 */
	public function test_queried_object_stays_on_the_archive_author_during_a_secondary_loop(): void {
		$this->go_to( home_url( '/?author_name=alice' ) );

		$query = $this->secondary_query();
		while ( $query->have_posts() ) {
			$query->the_post();
			$this->assertSame( $this->archive_author->ID, get_queried_object_id() );
		}
	}

	/**
	 * Third-party code can fire the hook with only the post.
	 */
	public function test_the_post_fired_with_only_a_post_is_ignored(): void {
		$this->go_to( home_url( '/?author_name=alice' ) );

		setup_postdata( $this->other_post );
		do_action( 'the_post', $this->other_post );

		$this->assertSame( 'Bob Other', get_the_author() );
	}

	/**
	 * Sort by post ID, since posts created in one second have no stable date order.
	 *
	 * @param array<int, string> $authors Authors keyed by post ID.
	 * @return array<int, string>
	 */
	private function sort_keys( array $authors ): array {
		ksort( $authors );

		return $authors;
	}
}
