<?php
/**
 * Who can change a post's co-authors through core's post saves.
 *
 * The author taxonomy is exposed to core's REST API as the `coauthors` field,
 * and core also writes terms passed as `tax_input`. Only users who can set
 * authors may change a byline through either, however the post is reached.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Rest;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * @covers \CoAuthors_Plus::set_post_author_for_rest_save
 * @covers \CoAuthors_Plus::filter_assign_author_terms_cap
 */
class RestCoauthorPermissionsTest extends TestCase {

	private function author_term_id( object $author ): int {
		$this->_cap->update_author_term( $author );
		return (int) $this->_cap->get_author_term( $author )->term_id;
	}

	private function coauthor_slugs_of( int $post_id ): array {
		return wp_list_pluck(
			wp_get_object_terms(
				$post_id,
				$this->_cap->coauthor_taxonomy,
				array( 'orderby' => 'term_order' )
			),
			'slug'
		);
	}

	private function rest_request( string $route, array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	private function save_via_rest( int $post_id, array $params ): WP_REST_Response {
		return $this->rest_request( '/wp/v2/posts/' . $post_id, $params + array( 'id' => $post_id ) );
	}

	/**
	 * A post written by $user that they can edit: published for an Author, a
	 * draft for a Contributor.
	 */
	private function create_own_post( WP_User $user ): int {
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $user->ID,
				'post_status' => in_array( 'contributor', $user->roles, true ) ? 'draft' : 'publish',
			)
		);
		$this->_cap->add_coauthors( $post_id, array( $user->user_nicename ) );

		return $post_id;
	}

	private function assert_byline_unchanged( int $post_id, array $slugs, int $post_author ): void {
		$this->assertSame( $slugs, $this->coauthor_slugs_of( $post_id ) );
		$this->assertSame( $post_author, (int) get_post( $post_id )->post_author );
	}

	public static function data_users_who_cannot_set_authors(): array {
		return array(
			'author'      => array( 'author' ),
			'contributor' => array( 'contributor' ),
		);
	}

	/**
	 * @dataProvider data_users_who_cannot_set_authors
	 */
	public function test_rest_save_rejects_coauthors_from_user_who_cannot_set_authors( string $role ): void {
		$user    = $this->factory()->user->create_and_get( array( 'role' => $role ) );
		$other   = $this->create_editor( 'other-editor' );
		$post_id = $this->create_own_post( $user );
		$before  = $this->coauthor_slugs_of( $post_id );
		wp_set_current_user( $user->ID );

		$response = $this->save_via_rest(
			$post_id,
			array( 'coauthors' => array( $this->author_term_id( $other ), $this->author_term_id( $user ) ) )
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assert_byline_unchanged( $post_id, $before, $user->ID );
	}

	/**
	 * Core's term permission check skips an empty list and IDs that aren't
	 * terms, so these need rejecting separately.
	 *
	 * @dataProvider data_users_who_cannot_set_authors
	 */
	public function test_rest_save_rejects_empty_or_invalid_coauthors_from_user_who_cannot_set_authors( string $role ): void {
		$owner = $this->create_author( 'owner' );
		$user  = $this->factory()->user->create_and_get( array( 'role' => $role ) );

		// $user co-authors $owner's post, so can edit it, but isn't its post_author.
		$post_id = $this->create_own_post( $user );
		$this->_cap->add_coauthors( $post_id, array( $owner->user_nicename, $user->user_nicename ) );
		$before = $this->coauthor_slugs_of( $post_id );
		wp_set_current_user( $user->ID );

		foreach ( array( array(), array( PHP_INT_MAX ) ) as $coauthors ) {
			$response = $this->save_via_rest( $post_id, array( 'coauthors' => $coauthors ) );

			$this->assertSame( 403, $response->get_status() );
			$this->assert_byline_unchanged( $post_id, $before, $owner->ID );
		}
	}

	public function test_rest_save_rejects_coauthor_removing_post_author(): void {
		$owner   = $this->create_author( 'owner' );
		$coauthor = $this->create_author( 'coauthor' );
		$post_id = $this->create_own_post( $owner );
		$this->_cap->add_coauthors( $post_id, array( $owner->user_nicename, $coauthor->user_nicename ) );
		$before = $this->coauthor_slugs_of( $post_id );
		wp_set_current_user( $coauthor->ID );

		$response = $this->save_via_rest(
			$post_id,
			array( 'coauthors' => array( $this->author_term_id( $coauthor ) ) )
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assert_byline_unchanged( $post_id, $before, $owner->ID );
	}

	public function test_rest_create_rejects_coauthors_from_user_who_cannot_set_authors(): void {
		$user  = $this->create_author( 'creator' );
		$other = $this->create_editor( 'other-editor' );
		wp_set_current_user( $user->ID );

		$response = $this->rest_request(
			'/wp/v2/posts',
			array(
				'title'     => 'Created with someone else as the author',
				'status'    => 'publish',
				'coauthors' => array( $this->author_term_id( $other ) ),
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), get_posts( array( 'title' => 'Created with someone else as the author' ) ) );
	}

	public function test_rest_save_without_coauthors_still_works_for_user_who_cannot_set_authors(): void {
		$user    = $this->create_author( 'author' );
		$post_id = $this->create_own_post( $user );
		$before  = $this->coauthor_slugs_of( $post_id );
		wp_set_current_user( $user->ID );

		$response = $this->save_via_rest( $post_id, array( 'title' => 'Just a new title' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Just a new title', get_post( $post_id )->post_title );
		$this->assert_byline_unchanged( $post_id, $before, $user->ID );
	}

	public function test_rest_save_lets_editor_set_coauthors(): void {
		$owner   = $this->create_author( 'owner' );
		$other   = $this->create_author( 'other' );
		$post_id = $this->create_own_post( $owner );
		wp_set_current_user( $this->create_editor( 'editor' )->ID );

		$response = $this->save_via_rest(
			$post_id,
			array( 'coauthors' => array( $this->author_term_id( $other ), $this->author_term_id( $owner ) ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'cap-other', 'cap-owner' ), $this->coauthor_slugs_of( $post_id ) );
		$this->assertSame( $other->ID, (int) get_post( $post_id )->post_author );
	}

	/**
	 * The classic editor, quick edit and XML-RPC all pass terms to core as
	 * tax_input, which core only writes if the user can assign them.
	 */
	public function test_tax_input_ignores_coauthors_from_user_who_cannot_set_authors(): void {
		$user    = $this->create_author( 'author' );
		$other   = $this->create_editor( 'other-editor' );
		$post_id = $this->create_own_post( $user );
		$before  = $this->coauthor_slugs_of( $post_id );
		wp_set_current_user( $user->ID );

		wp_update_post(
			array(
				'ID'        => $post_id,
				'tax_input' => array( $this->_cap->coauthor_taxonomy => array( $this->author_term_id( $other ) ) ),
			)
		);

		$this->assert_byline_unchanged( $post_id, $before, $user->ID );
	}

	public function test_tax_input_sets_coauthors_for_editor(): void {
		$owner   = $this->create_author( 'owner' );
		$other   = $this->create_author( 'other' );
		$post_id = $this->create_own_post( $owner );
		wp_set_current_user( $this->create_editor( 'editor' )->ID );

		wp_update_post(
			array(
				'ID'        => $post_id,
				'tax_input' => array( $this->_cap->coauthor_taxonomy => array( $this->author_term_id( $other ) ) ),
			)
		);

		$this->assertSame( array( 'cap-other' ), $this->coauthor_slugs_of( $post_id ) );
	}

	public function test_assign_author_terms_cap_for_user_other_than_current_user(): void {
		$term_id = $this->author_term_id( $this->create_author( 'someone' ) );
		wp_set_current_user( $this->create_editor( 'current-editor' )->ID );

		$this->assertTrue( user_can( $this->create_editor( 'other-editor' ), 'assign_term', $term_id ) );
		$this->assertFalse( user_can( $this->create_author( 'other-author' ), 'assign_term', $term_id ) );
	}
}
