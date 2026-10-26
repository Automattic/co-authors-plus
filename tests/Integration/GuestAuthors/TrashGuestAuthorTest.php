<?php
/**
 * Tests for trashing a guest author outside the plugin's delete flow, for
 * example from the post list screen, the REST API or `wp post delete`.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Guest_Authors
 */
class TrashGuestAuthorTest extends TestCase {

	/**
	 * Guest author post ID.
	 *
	 * @var int
	 */
	private $guest_author_id;

	/**
	 * Published post bylined to the guest author.
	 *
	 * @var int
	 */
	private $post_id;

	public function set_up() {
		parent::set_up();

		$this->guest_author_id = $this->create_guest_author( 'trashed-ga' );
		$this->post_id         = $this->factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->_cap->add_coauthors( $this->post_id, array( 'trashed-ga' ), false, 'user_login' );
	}

	/**
	 * Guest author IDs in the post's byline.
	 *
	 * @return int[]
	 */
	private function get_byline_ids(): array {
		return array_map( 'intval', wp_list_pluck( get_coauthors( $this->post_id ), 'ID' ) );
	}

	/**
	 * Bylines look guest authors up by post_name, so trashing must not rename it.
	 *
	 * @covers ::filter_keep_post_name_when_trashed
	 */
	public function test_trashing_keeps_the_guest_author_post_name(): void {
		wp_trash_post( $this->guest_author_id );

		$this->assertSame( 'trash', get_post_status( $this->guest_author_id ) );
		$this->assertSame( 'cap-trashed-ga', get_post_field( 'post_name', $this->guest_author_id ) );
	}

	/**
	 * The byline still shows the guest author while the profile is in the trash.
	 *
	 * @covers ::filter_keep_post_name_when_trashed
	 */
	public function test_byline_still_resolves_while_the_guest_author_is_trashed(): void {
		wp_trash_post( $this->guest_author_id );

		// A later request, without the lookups made above still in the cache.
		wp_cache_flush();

		$this->assertSame( array( $this->guest_author_id ), $this->get_byline_ids() );
	}

	/**
	 * Restoring the profile brings the byline back, even when the byline was
	 * viewed (and the lookup cached) while the profile was in the trash.
	 *
	 * @covers ::filter_keep_post_name_when_trashed
	 */
	public function test_byline_resolves_after_the_guest_author_is_restored(): void {
		wp_trash_post( $this->guest_author_id );
		wp_cache_flush();
		get_coauthors( $this->post_id );

		wp_untrash_post( $this->guest_author_id );

		$this->assertSame( 'cap-trashed-ga', get_post_field( 'post_name', $this->guest_author_id ) );
		$this->assertSame( '', get_post_meta( $this->guest_author_id, '_wp_desired_post_slug', true ) );
		$this->assertSame( array( $this->guest_author_id ), $this->get_byline_ids() );
	}

	/**
	 * Other post types keep core's behaviour of freeing the slug on trash.
	 *
	 * @covers ::filter_keep_post_name_when_trashed
	 */
	public function test_trashing_other_post_types_still_frees_the_slug(): void {
		$post_id = $this->factory()->post->create( array( 'post_name' => 'ordinary-post' ) );

		wp_trash_post( $post_id );

		$this->assertSame( 'ordinary-post__trashed', get_post_field( 'post_name', $post_id ) );
	}
}
