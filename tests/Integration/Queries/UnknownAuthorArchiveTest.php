<?php
/**
 * Test author archives for slugs that match no co-author.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Queries;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers \CoAuthors_Plus::fix_author_page()
 * @covers \CoAuthors_Plus::empty_unknown_author_query()
 */
class UnknownAuthorArchiveTest extends TestCase {

	/**
	 * The status code the request last sent.
	 *
	 * @var int|null
	 */
	private $status_code;

	public function set_up() {
		parent::set_up();

		$this->set_permalink_structure( '/%postname%/' );
		update_option( 'posts_per_page', 2 );

		// Core resolves an unknown author_name to post_author = 0. These posts
		// have no WP user author, as on a site where guest authors byline them.
		global $wpdb;
		foreach ( $this->factory()->post->create_many( 3 ) as $post_id ) {
			$wpdb->update( $wpdb->posts, array( 'post_author' => 0 ), array( 'ID' => $post_id ) );
			clean_post_cache( $post_id );
		}

		$this->status_code = null;
		add_filter(
			'status_header',
			function ( $status_header, $code ) {
				$this->status_code = $code;
				return $status_header;
			},
			10,
			2
		);
	}

	/**
	 * An unknown slug must not render an archive of the posts with no WP user
	 * author, or every string after /author/ is a crawlable, paginated page.
	 *
	 * @dataProvider data_unknown_author_urls
	 */
	public function test_unknown_author_slug_is_404( string $path ): void {
		$this->go_to( home_url( $path ) );

		$this->assertQueryTrue( 'is_404' );
		$this->assertSame( 404, $this->status_code );
		$this->assertSame( 0, $GLOBALS['wp_query']->post_count );
	}

	public static function data_unknown_author_urls(): array {
		return array(
			'archive'     => array( '/author/does-not-exist/' ),
			'second page' => array( '/author/does-not-exist/page/2/' ),
		);
	}

	/**
	 * Core never sends a 404 for a feed with no posts, so the feed is empty instead.
	 */
	public function test_unknown_author_feed_is_empty(): void {
		$this->go_to( home_url( '/author/does-not-exist/feed/' ) );

		$this->assertQueryTrue( 'is_feed' );
		$this->assertSame( 200, $this->status_code );
		$this->assertSame( 0, $GLOBALS['wp_query']->post_count );
	}

	/**
	 * @dataProvider data_known_author_slugs
	 */
	public function test_user_and_guest_author_archives_are_not_404( string $slug ): void {
		$user = $this->create_author( 'realwriter' );
		$this->factory()->post->create( array( 'post_author' => $user->ID ) );
		$this->create_guest_author( 'guestwriter' );

		$this->go_to( home_url( "/author/$slug/" ) );

		$this->assertQueryTrue( 'is_author', 'is_archive' );
		$this->assertSame( $slug, get_queried_object()->user_nicename );
		$this->assertNotSame( 404, $this->status_code );
	}

	public static function data_known_author_slugs(): array {
		return array(
			'user'         => array( 'realwriter' ),
			'guest author' => array( 'guestwriter' ),
		);
	}

	/**
	 * The wp-admin posts list, where author_name filters by author, is left alone.
	 */
	public function test_unknown_author_name_in_admin_is_not_emptied(): void {
		global $wp_query;

		// go_to() leaves wp-admin, so call fix_author_page() as edit.php's query would.
		set_current_screen( 'edit-post' );
		$wp_query->is_author = true;
		set_query_var( 'author_name', 'does-not-exist' );
		$this->_cap->fix_author_page( '' );
		$has_empty_query_callback = has_filter( 'posts_pre_query', array( $this->_cap, 'empty_unknown_author_query' ) );
		$GLOBALS['current_screen'] = null;

		$this->assertFalse( $has_empty_query_callback );
	}

	/**
	 * Only the main query is emptied, not another query on the same request.
	 */
	public function test_secondary_query_is_not_emptied(): void {
		global $wp_query;

		$wp_query->is_author = true;
		set_query_var( 'author_name', 'does-not-exist' );
		$this->_cap->fix_author_page( '' );

		$secondary = new \WP_Query( array( 'post_type' => 'post' ) );

		$this->assertSame( 2, $secondary->post_count );
	}
}
