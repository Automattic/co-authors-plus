<?php
/**
 * Tests that a co-author only ever resolves to their own author term.
 *
 * An author term's slug is 'cap-' plus the nicename, so a nicename that starts
 * with 'cap-' reads the same as another author's term slug.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_REST_Request;
use WP_User;

/**
 * @covers \CoAuthors_Plus::get_author_term
 * @covers \CoAuthors_Plus::posts_where_filter
 * @covers \CoAuthors_Plus::search_authors
 * @covers \CoAuthors_Plus::get_coauthor_by_term
 */
class AuthorTermOwnershipTest extends TestCase {

	/**
	 * Create a private post with the given byline.
	 *
	 * @param WP_User  $post_author The post author.
	 * @param string[] $coauthors   Co-author nicenames.
	 * @return int Post ID.
	 */
	private function create_private_post( WP_User $post_author, array $coauthors ): int {
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $post_author->ID,
				'post_status' => 'private',
			)
		);
		$this->_cap->add_coauthors( $post_id, $coauthors );

		return $post_id;
	}

	/**
	 * Get the post IDs an author archive shows a user.
	 *
	 * @param WP_User    $viewer The logged in user.
	 * @param string|int $author The nicename or user ID of the author whose archive is viewed.
	 * @return int[] Post IDs.
	 */
	private function get_archive_posts_for( WP_User $viewer, $author ): array {
		wp_set_current_user( $viewer->ID );
		$this->go_to( home_url( '/?' . ( is_int( $author ) ? 'author=' : 'author_name=' ) . $author ) );

		return wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );
	}

	/**
	 * Create a guest author whose login was stored with the prefix.
	 *
	 * The login, the post_name and the term slug all read cap-jane-doe, which is
	 * what the plugin only ever writes when it's given that login.
	 *
	 * @param string $term_name The name of the guest author's term.
	 * @return object The guest author.
	 */
	private function create_guest_author_with_prefixed_login( string $term_name ): object {
		$guest_id = $this->create_guest_author( 'jane-doe' );
		$term     = get_term_by( 'slug', 'cap-jane-doe', $this->_cap->coauthor_taxonomy );

		update_post_meta( $guest_id, 'cap-user_login', 'cap-jane-doe' );
		wp_update_term( $term->term_id, $this->_cap->coauthor_taxonomy, array( 'name' => $term_name ) );

		$this->_cap->guest_authors->delete_guest_author_cache( $guest_id );
		wp_cache_delete( 'author-term-cap-jane-doe', 'co-authors-plus' );

		return $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_id, true );
	}

	/**
	 * @dataProvider data_prefixed_usernames
	 *
	 * @param string $user_login The login of the other user.
	 */
	public function test_a_prefixed_username_is_not_matched_to_another_users_term_on_their_archive( string $user_login ): void {
		$jordan  = $this->create_author( 'jordan' );
		$post_id = $this->create_private_post( $jordan, array( 'jordan' ) );
		$viewer  = $this->create_subscriber( $user_login );

		$this->assertSame( 'cap-jordan', $viewer->user_nicename );
		$this->assertNotContains( $post_id, $this->get_archive_posts_for( $viewer, 'jordan' ) );
		$this->assertNotContains( $post_id, $this->get_archive_posts_for( $viewer, $jordan->ID ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function data_prefixed_usernames(): array {
		return array(
			'login matches the nicename' => array( 'cap-jordan' ),
			'login differs from it'      => array( 'cap.jordan' ),
		);
	}

	/**
	 * @dataProvider data_usernames_matching_a_guest_author
	 *
	 * @param string $user_login The login of the other user.
	 */
	public function test_a_username_matching_a_guest_author_is_not_matched_to_their_term_on_an_archive( string $user_login ): void {
		$author = $this->create_author( 'first' );
		$this->create_guest_author( 'guest' );
		$post_id = $this->create_private_post( $author, array( 'first', 'guest' ) );
		$viewer  = $this->create_subscriber( $user_login );

		$this->assertNotContains( $post_id, $this->get_archive_posts_for( $viewer, 'first' ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function data_usernames_matching_a_guest_author(): array {
		return array(
			'prefixed'     => array( 'cap-guest' ),
			'unprefixed'   => array( 'guest' ),
			'case differs' => array( 'Guest' ),
		);
	}

	public function test_a_coauthor_keeps_their_shared_posts_on_the_other_authors_archive(): void {
		$author   = $this->create_author( 'first' );
		$coauthor = $this->create_author( 'second' );
		$post_id  = $this->create_private_post( $author, array( 'first', 'second' ) );

		$this->assertContains( $post_id, $this->get_archive_posts_for( $coauthor, 'first' ) );
		$this->assertContains( $post_id, $this->get_archive_posts_for( $coauthor, $author->ID ) );
	}

	public function test_a_linked_user_keeps_their_guest_authors_posts_on_another_archive(): void {
		$author = $this->create_author( 'first' );
		$linked = $this->create_author( 'linked' );

		$guest_id = $this->create_guest_author( 'guest' );
		update_post_meta( $guest_id, 'cap-linked_account', 'linked' );
		$this->_cap->guest_authors->delete_guest_author_cache( $guest_id );

		$post_id = $this->create_private_post( $author, array( 'first', 'guest' ) );

		$this->assertContains( $post_id, $this->get_archive_posts_for( $linked, 'first' ) );
	}

	public function test_a_prefixed_username_has_no_term_until_it_gets_its_own(): void {
		$jordan = $this->create_author( 'jordan' );
		$this->_cap->update_author_term( $jordan );

		$other = $this->create_author( 'cap-jordan' );
		$this->assertFalse( $this->_cap->get_author_term( $other ) );

		$this->assertSame( 'cap-cap-jordan', $this->_cap->update_author_term( $other )->slug );
		$this->assertSame( 'cap-jordan', $this->_cap->get_author_term( $jordan )->slug );
	}

	public function test_a_term_cached_for_a_prefixed_username_before_upgrading_is_ignored(): void {
		$jordan = $this->create_author( 'jordan' );
		$term   = $this->_cap->update_author_term( $jordan );
		$other  = $this->create_author( 'cap-jordan' );

		wp_cache_set( 'author-term-cap-jordan', $term, 'co-authors-plus' );

		$this->assertFalse( $this->_cap->get_author_term( $other ) );
	}

	public function test_updating_a_prefixed_username_leaves_the_other_users_term_alone(): void {
		$jordan      = $this->create_author( 'jordan' );
		$description = $this->_cap->update_author_term( $jordan )->description;
		$other       = $this->create_author( 'cap-jordan' );

		wp_update_user(
			array(
				'ID'           => $other->ID,
				'display_name' => 'Someone Else',
			)
		);

		$this->assertSame( $description, get_term_by( 'slug', 'cap-jordan', $this->_cap->coauthor_taxonomy )->description );
	}

	public function test_deleting_a_prefixed_username_keeps_the_other_users_byline(): void {
		$jordan  = $this->create_author( 'jordan' );
		$post_id = $this->create_post( $jordan )->ID;
		$this->_cap->add_coauthors( $post_id, array( 'jordan' ) );
		$other = $this->create_author( 'cap-jordan' );

		wp_delete_user( $other->ID );

		$this->assertPostHasCoAuthors( $post_id, array( $jordan ) );
	}

	public function test_adding_a_prefixed_username_does_not_add_the_other_user(): void {
		$jordan = $this->create_author( 'jordan' );
		$this->_cap->update_author_term( $jordan );
		$other   = $this->create_author( 'cap-jordan' );
		$post_id = $this->create_post( $other )->ID;

		$this->_cap->add_coauthors( $post_id, array( 'cap-jordan' ) );

		$this->assertSame( array( 'cap-jordan' ), wp_list_pluck( get_coauthors( $post_id ), 'user_login' ) );
	}

	public function test_a_prefixed_username_does_not_count_the_other_users_posts(): void {
		$jordan  = $this->create_author( 'jordan' );
		$post_id = $this->create_post( $jordan )->ID;
		$this->_cap->add_coauthors( $post_id, array( 'jordan' ) );
		$other = $this->create_author( 'cap-jordan' );

		$this->assertSame( 0, (int) count_user_posts( $other->ID ) );
	}

	public function test_a_guest_author_starting_cap_does_not_take_a_users_term(): void {
		$user = $this->create_author( 'ri' );
		$this->_cap->update_author_term( $user );

		$guest = $this->_cap->guest_authors->get_guest_author_by( 'ID', $this->create_guest_author( 'Cap Ri' ) );

		$this->assertSame( 'cap-cap-ri', $this->_cap->update_author_term( $guest )->slug );
	}

	/**
	 * @dataProvider data_prefixed_username_roles
	 *
	 * @param string $role The other user's role.
	 */
	public function test_searching_finds_the_user_rather_than_their_prefixed_username( string $role ): void {
		$jordan = $this->create_author( 'jordan' );
		$this->_cap->update_author_term( $jordan );
		$this->factory()->user->create(
			array(
				'role'       => $role,
				'user_login' => 'cap-jordan',
				'user_email' => 'other@example.org',
			)
		);

		$found = $this->_cap->search_authors( 'jordan' );

		$this->assertArrayHasKey( 'jordan', $found );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function data_prefixed_username_roles(): array {
		return array(
			'subscriber'  => array( 'subscriber' ),
			'contributor' => array( 'contributor' ),
		);
	}

	public function test_an_unprefixed_term_still_belongs_to_the_user_it_was_made_for(): void {
		$user = $this->create_author( 'legacy' );
		wp_insert_term( 'legacy', $this->_cap->coauthor_taxonomy, array( 'slug' => 'legacy' ) );

		$this->assertSame( 'legacy', $this->_cap->get_author_term( $user )->slug );
	}

	public function test_a_user_starting_cap_keeps_their_prefixed_term_if_its_name_is_out_of_date(): void {
		$user = $this->create_author( 'cap-renamed' );
		$term = $this->_cap->update_author_term( $user );
		wp_update_term( $term->term_id, $this->_cap->coauthor_taxonomy, array( 'name' => 'old-login' ) );
		wp_cache_delete( 'author-term-cap-renamed', 'co-authors-plus' );

		$this->assertSame( 'cap-cap-renamed', $this->_cap->get_author_term( $user )->slug );
	}

	public function test_an_unprefixed_term_still_belongs_to_a_user_starting_cap(): void {
		$user = $this->create_author( 'cap-legacy' );
		wp_insert_term( 'cap-legacy', $this->_cap->coauthor_taxonomy, array( 'slug' => 'cap-legacy' ) );

		$this->assertSame( 'cap-legacy', $this->_cap->get_author_term( $user )->slug );
	}

	public function test_a_term_belongs_to_its_user_rather_than_a_prefixed_username(): void {
		$jordan = $this->create_author( 'jordan' );
		$term   = $this->_cap->update_author_term( $jordan );
		$this->create_subscriber( 'cap-jordan' );

		$this->assertSame( $jordan->ID, $this->_cap->get_coauthor_by_term( $term )->ID );
	}

	public function test_a_term_belongs_to_a_prefixed_username_when_it_is_their_own(): void {
		$other = $this->create_author( 'cap-jordan' );
		$term  = $this->_cap->update_author_term( $other );

		$this->assertSame( $other->ID, $this->_cap->get_coauthor_by_term( $term )->ID );
	}

	public function test_a_term_belongs_to_a_guest_author_starting_cap(): void {
		$guest_id = $this->create_guest_author( 'Cap Ri' );
		$this->create_author( 'ri' );
		$term = $this->_cap->update_author_term( $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_id ) );

		$this->assertSame( $guest_id, (int) $this->_cap->get_coauthor_by_term( $term )->ID );
	}

	public function test_a_guest_author_with_a_prefixed_login_has_no_term_when_its_name_is_not_their_login(): void {
		$guest = $this->create_guest_author_with_prefixed_login( 'jane doe' );

		$this->assertSame( 'cap-jane-doe', $guest->user_nicename );
		$this->assertFalse( $this->_cap->get_author_term( $guest ) );
	}

	public function test_a_guest_author_with_a_prefixed_login_owns_a_term_named_after_their_login(): void {
		$guest = $this->create_guest_author_with_prefixed_login( 'cap-jane-doe' );

		$this->assertSame( 'cap-jane-doe', $this->_cap->get_author_term( $guest )->slug );
	}

	public function test_a_term_with_no_author_does_not_belong_to_a_prefixed_username(): void {
		$term = wp_insert_term( 'gone', $this->_cap->coauthor_taxonomy, array( 'slug' => 'cap-gone' ) );
		$this->create_subscriber( 'cap-gone' );

		$this->assertFalse( $this->_cap->get_coauthor_by_term( get_term( $term['term_id'] ) ) );
	}

	public function test_a_rest_save_keeps_the_post_author_and_byline_away_from_a_prefixed_username(): void {
		$jordan  = $this->create_author( 'jordan' );
		$post_id = $this->create_private_post( $jordan, array( 'jordan' ) );
		$term_id = $this->_cap->get_author_term( $jordan )->term_id;
		$this->create_author( 'cap-jordan' );

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'coauthors', array( $term_id ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $jordan->ID, (int) get_post_field( 'post_author', $post_id ) );
		$this->assertSame( array( 'jordan' ), wp_list_pluck( get_coauthors( $post_id ), 'user_login' ) );
	}

	public function test_any_other_save_keeps_the_post_author_away_from_a_prefixed_username(): void {
		$jordan  = $this->create_author( 'jordan' );
		$post_id = $this->create_private_post( $jordan, array( 'jordan' ) );
		$this->create_author( 'cap-jordan' );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Updated',
			)
		);

		$this->assertSame( $jordan->ID, (int) get_post_field( 'post_author', $post_id ) );
	}

	public function test_the_byline_lookup_by_term_id_returns_the_user_rather_than_a_prefixed_username(): void {
		$jordan = $this->create_author( 'jordan' );
		$term   = $this->_cap->update_author_term( $jordan );
		$this->create_author( 'cap-jordan' );

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request  = new WP_REST_Request( 'GET', '/coauthors/v1/authors-by-term-ids' );
		$request->set_param( 'ids', (string) $term->term_id );
		$response = rest_do_request( $request );

		$this->assertSame( array( 'jordan' ), wp_list_pluck( $response->get_data(), 'login' ) );
	}

	public function test_the_term_post_count_is_for_the_user_rather_than_a_prefixed_username(): void {
		$jordan = $this->create_author( 'jordan' );
		$other  = $this->create_author( 'cap-jordan' );
		wp_delete_object_term_relationships( $this->create_post( $other )->ID, $this->_cap->coauthor_taxonomy );
		$term = $this->_cap->update_author_term( $jordan );

		$post_id = $this->create_post( $jordan )->ID;
		$this->_cap->add_coauthors( $post_id, array( 'jordan' ) );

		$this->_cap->update_author_term_post_count( $term );

		$this->assertSame( 1, get_term( $term->term_id )->count );
	}
}
