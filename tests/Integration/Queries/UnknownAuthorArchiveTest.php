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
 * @covers \CoAuthors_Plus::handle_unknown_author_404()
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
	public function test_unknown_author_slug_is_404( string $path, array $query_flags ): void {
		$this->go_to( home_url( $path ) );

		$this->assertQueryTrue( ...$query_flags );
		$this->assertSame( 404, $this->status_code );
	}

	public static function data_unknown_author_urls(): array {
		return array(
			'archive'     => array( '/author/does-not-exist/', array( 'is_404' ) ),
			'second page' => array( '/author/does-not-exist/page/2/', array( 'is_404' ) ),
			// WP_Query::set_404() keeps is_feed.
			'feed'        => array( '/author/does-not-exist/feed/', array( 'is_404', 'is_feed' ) ),
		);
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
	 * Core never sends a 404 in wp-admin, where author_name filters the posts list.
	 */
	public function test_unknown_author_name_in_admin_is_not_404(): void {
		global $wp_query;

		// go_to() leaves wp-admin, so call fix_author_page() as edit.php's query would.
		set_current_screen( 'edit-post' );
		$wp_query->is_author = true;
		set_query_var( 'author_name', 'does-not-exist' );
		$this->_cap->fix_author_page( '' );
		$has_404_callback = has_filter( 'pre_handle_404', array( $this->_cap, 'handle_unknown_author_404' ) );
		$GLOBALS['current_screen'] = null;

		$this->assertFalse( $has_404_callback );
	}

	/**
	 * A plugin that already handled the request through pre_handle_404 keeps its decision.
	 */
	public function test_earlier_pre_handle_404_decision_is_kept(): void {
		add_filter( 'pre_handle_404', '__return_true' );

		$this->go_to( home_url( '/author/does-not-exist/' ) );

		$this->assertFalse( is_404() );
		$this->assertNull( $this->status_code );
	}
}
