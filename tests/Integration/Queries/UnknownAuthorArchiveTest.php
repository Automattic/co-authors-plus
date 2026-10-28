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

		// Core resolves an unknown author_name to post_author = 0, so these
		// posts (bylined only by a guest author) are what such an archive lists.
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
	}

	public static function data_unknown_author_urls(): array {
		return array(
			'archive'     => array( '/author/does-not-exist/' ),
			'second page' => array( '/author/does-not-exist/page/2/' ),
		);
	}

	public function test_user_and_guest_author_archives_are_not_404(): void {
		$user = $this->create_author( 'realwriter' );
		$this->factory()->post->create( array( 'post_author' => $user->ID ) );
		$this->create_guest_author( 'guestwriter' );

		foreach ( array( 'realwriter', 'guestwriter' ) as $slug ) {
			$this->go_to( home_url( "/author/$slug/" ) );

			$this->assertQueryTrue( 'is_author', 'is_archive' );
			$this->assertSame( $slug, get_queried_object()->user_nicename );
		}
	}
}
