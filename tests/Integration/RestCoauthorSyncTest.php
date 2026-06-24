<?php
/**
 * REST save/read co-author integrity.
 *
 * Consolidates the regression coverage for the block-editor REST flow that keeps
 * core's post_author column and the author taxonomy in step:
 *
 *  - #1269: post_author must be derived from the co-author terms at
 *    rest_pre_insert, before wp_insert_post fires, so listeners such as Jetpack
 *    Sync never see a stale author.
 *  - #1297: reordering co-authors must move post_author to the new first
 *    co-author, including on the follow-up non-REST meta-box-loader save.
 *  - #1252: a save whose submitted term IDs all drop out must fall back to the
 *    post_author rather than leave the post termless, and the term-id lookup
 *    must skip orphan terms instead of returning termId: null.
 *  - #1241: a REST read must never mutate the co-author taxonomy assignments.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use CoAuthors\API\Endpoints;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \CoAuthors_Plus::set_post_author_for_rest_save
 * @covers \CoAuthors_Plus::sync_coauthors_on_rest_save
 */
class RestCoauthorSyncTest extends TestCase {

	/**
	 * The post_author seen by wp_insert_post listeners during the most recent
	 * REST save, captured by the spy so we can assert what Jetpack Sync would see.
	 *
	 * @var int|null
	 */
	private $post_author_at_insert = null;

	public function set_up() {
		parent::set_up();
		$this->post_author_at_insert = null;
	}

	// -- helpers ------------------------------------------------------------

	/**
	 * Create an administrator (who passes current_user_can_set_authors()) and log them in.
	 */
	private function login_as_admin(): \WP_User {
		$admin = $this->factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );
		return $admin;
	}

	/**
	 * Ensure the given author/guest-author has an author term and return its ID.
	 *
	 * @param object $author WP_User or guest-author object.
	 */
	private function author_term_id( object $author ): int {
		$this->_cap->update_author_term( $author );
		return (int) $this->_cap->get_author_term( $author )->term_id;
	}

	/**
	 * Save a post through the REST API the way the block editor does, assert the
	 * save succeeded, and return the response.
	 *
	 * @param int   $post_id Post being saved.
	 * @param array $params  Extra request params (e.g. coauthors term IDs, title).
	 */
	private function save_via_rest( int $post_id, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'id', $post_id );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		return $response;
	}

	private function post_author_of( int $post_id ): int {
		return (int) get_post( $post_id )->post_author;
	}

	private function coauthor_slugs_of( int $post_id ): array {
		$slugs = wp_list_pluck(
			wp_get_object_terms( $post_id, $this->_cap->coauthor_taxonomy ),
			'slug'
		);
		sort( $slugs );
		return $slugs;
	}

	/**
	 * Record the post_author at the moment wp_insert_post fires, mirroring where
	 * Jetpack Sync queues the post for synchronisation.
	 */
	private function spy_on_wp_insert_post( int $post_id ): void {
		add_action(
			'wp_insert_post',
			function ( $captured_id, $post ) use ( $post_id ) {
				if ( (int) $captured_id === $post_id ) {
					$this->post_author_at_insert = (int) $post->post_author;
				}
			},
			5,
			2
		);
	}

	// -- #1269: post_author derived before wp_insert_post -------------------

	public function test_rest_save_updates_post_author_before_wp_insert_post_fires(): void {
		$original  = $this->create_author( 'cap-1269-original' );
		$switch_to = $this->create_author( 'cap-1269-replacement' );
		$post      = $this->create_post( $original );

		$replacement_term_id = $this->author_term_id( $switch_to );
		$this->login_as_admin();
		$this->spy_on_wp_insert_post( $post->ID );

		$this->save_via_rest(
			$post->ID,
			array(
				'coauthors' => array( $replacement_term_id ),
				'title'     => 'After switching primary author',
			)
		);

		$this->assertSame(
			$switch_to->ID,
			$this->post_author_at_insert,
			'wp_insert_post listeners must see the new co-author as post_author, not the previous one.'
		);
		$this->assertSame(
			$switch_to->ID,
			$this->post_author_of( $post->ID ),
			'post_author in the DB after the save must reflect the new co-author.'
		);
	}

	public function test_rest_save_uses_linked_wp_user_for_guest_author_coauthor(): void {
		$linked_user = $this->create_author( 'linked-user-1269' );
		$post        = $this->create_post( $this->create_author( 'original-1269-2' ) );

		$guest_id = $this->_cap->guest_authors->create(
			array(
				'display_name'   => 'Linked Guest',
				'user_login'     => 'guest-linked-1269',
				'linked_account' => $linked_user->user_login,
			)
		);
		$guest         = $this->_cap->guest_authors->get_guest_author_by( 'id', $guest_id );
		$guest_term_id = $this->author_term_id( $guest );

		$this->login_as_admin();
		$this->spy_on_wp_insert_post( $post->ID );

		$this->save_via_rest( $post->ID, array( 'coauthors' => array( $guest_term_id ) ) );

		$this->assertSame(
			$linked_user->ID,
			$this->post_author_at_insert,
			'A guest author with a linked WP user must surface that user as post_author at wp_insert_post.'
		);
	}

	public function test_rest_save_leaves_post_author_unchanged_when_still_a_coauthor(): void {
		$author    = $this->create_author( 'cap-1269-stays' );
		$secondary = $this->create_author( 'cap-1269-secondary' );
		$post      = $this->create_post( $author );

		$author_term_id    = $this->author_term_id( $author );
		$secondary_term_id = $this->author_term_id( $secondary );

		$this->login_as_admin();
		$this->spy_on_wp_insert_post( $post->ID );

		$this->save_via_rest( $post->ID, array( 'coauthors' => array( $author_term_id, $secondary_term_id ) ) );

		$this->assertSame(
			$author->ID,
			$this->post_author_at_insert,
			'When the existing post_author remains in the coauthors list, post_author must not be reassigned.'
		);
	}

	public function test_rest_save_leaves_post_author_unchanged_when_no_coauthors_param(): void {
		$author = $this->create_author( 'cap-1269-no-coauthors-param' );
		$post   = $this->create_post( $author );

		$this->login_as_admin();
		$this->spy_on_wp_insert_post( $post->ID );

		$this->save_via_rest( $post->ID, array( 'title' => 'No coauthors data in this save' ) );

		$this->assertSame(
			$author->ID,
			$this->post_author_at_insert,
			'When no coauthors taxonomy data is sent, post_author must be preserved as-is.'
		);
	}

	public function test_rest_save_leaves_post_author_unchanged_for_guest_only_coauthor(): void {
		$author = $this->create_author( 'cap-1269-guest-only' );
		$post   = $this->create_post( $author );

		// Guest author with NO linked WP user — there is no WP_User to surface.
		$guest_id = $this->_cap->guest_authors->create(
			array(
				'display_name' => 'Standalone Guest',
				'user_login'   => 'guest-only-1269',
			)
		);
		$guest         = $this->_cap->guest_authors->get_guest_author_by( 'id', $guest_id );
		$guest_term_id = $this->author_term_id( $guest );

		$this->login_as_admin();
		$this->spy_on_wp_insert_post( $post->ID );

		$this->save_via_rest( $post->ID, array( 'coauthors' => array( $guest_term_id ) ) );

		$this->assertSame(
			$author->ID,
			$this->post_author_at_insert,
			'A guest-only coauthor cannot replace post_author because there is no underlying WP_User. Known gap; see #1269.'
		);
	}

	// -- #1297: reordering moves post_author to the new first co-author -----

	public function test_reordering_coauthors_moves_post_author_to_new_first(): void {
		$author_a = $this->create_author( 'cap-1297-a' );
		$author_b = $this->create_author( 'cap-1297-b' );
		$post     = $this->create_post( $author_a );

		$term_a = $this->author_term_id( $author_a );
		$term_b = $this->author_term_id( $author_b );

		$this->login_as_admin();

		$this->save_via_rest( $post->ID, array( 'coauthors' => array( $term_a, $term_b ) ) );
		$this->assertSame( $author_a->ID, $this->post_author_of( $post->ID ), 'post_author should be A while A is first.' );

		$this->save_via_rest( $post->ID, array( 'coauthors' => array( $term_b, $term_a ) ) );
		$this->assertSame( $author_b->ID, $this->post_author_of( $post->ID ), 'post_author should move to B after B becomes first.' );

		$terms = wp_get_object_terms(
			$post->ID,
			$this->_cap->coauthor_taxonomy,
			array(
				'orderby' => 'term_order',
				'order'   => 'ASC',
				'fields'  => 'slugs',
			)
		);
		$this->assertSame(
			array(
				$this->_cap->get_author_term( $author_b )->slug,
				$this->_cap->get_author_term( $author_a )->slug,
			),
			$terms,
			'Author terms should be ordered B, A after the reorder.'
		);
	}

	public function test_reordering_back_moves_post_author_back(): void {
		$author_a = $this->create_author( 'cap-1297-back-a' );
		$author_b = $this->create_author( 'cap-1297-back-b' );
		$post     = $this->create_post( $author_a );

		$term_a = $this->author_term_id( $author_a );
		$term_b = $this->author_term_id( $author_b );

		$this->login_as_admin();

		$this->save_via_rest( $post->ID, array( 'coauthors' => array( $term_b, $term_a ) ) );
		$this->save_via_rest( $post->ID, array( 'coauthors' => array( $term_a, $term_b ) ) );

		$this->assertSame(
			$author_a->ID,
			$this->post_author_of( $post->ID ),
			'post_author should move back to A when A becomes the first co-author again.'
		);
	}

	/**
	 * Gutenberg fires a second, non-REST "meta-box-loader" save (action=editpost)
	 * carrying no co-author data; core's edit_post() would revert post_author to
	 * the editing user. CAP must re-derive it from the first co-author term.
	 */
	public function test_non_rest_save_reasserts_post_author_from_first_coauthor(): void {
		$author_a = $this->create_author( 'cap-1297-mbl-a' );
		$author_b = $this->create_author( 'cap-1297-mbl-b' );
		$post     = $this->create_post( $author_a );

		// Store co-authors as B, A — add_coauthors keeps post_author as A (still a
		// co-author), reproducing the stale state after a reorder.
		$this->_cap->add_coauthors( $post->ID, array( $author_b->user_nicename, $author_a->user_nicename ) );

		$admin = $this->login_as_admin();

		unset( $_POST['coauthors'], $_REQUEST['coauthors-nonce'] );
		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_author' => $admin->ID,
			)
		);

		$this->assertSame(
			$author_b->ID,
			$this->post_author_of( $post->ID ),
			'A non-REST save must re-assert post_author from the first co-author, not revert it.'
		);
	}

	// -- #1252: term-id resolution and termless fallback --------------------

	/**
	 * @covers \CoAuthors\API\Endpoints::get_coauthors_by_term_ids
	 */
	public function test_authors_by_term_ids_skips_terms_with_no_resolvable_author(): void {
		$editor = $this->create_editor( 'rest-fallback-editor' );
		wp_set_current_user( $editor->ID );

		$editor_term_id = $this->author_term_id( $editor );

		// An orphan term whose slug points at no WP user or guest author.
		$orphan_term = wp_insert_term(
			'orphan-author',
			$this->_cap->coauthor_taxonomy,
			array(
				'slug'        => 'cap-no-such-author',
				'description' => 'Orphan author term',
			)
		);
		$this->assertIsArray( $orphan_term );

		$request = new WP_REST_Request( 'GET', '/coauthors/v1/' . Endpoints::AUTHORS_BY_TERMS_ROUTE );
		$request->set_param( 'ids', $editor_term_id . ',' . $orphan_term['term_id'] );

		$data = ( new Endpoints( $this->_cap ) )->get_coauthors_by_term_ids( $request )->get_data();

		$this->assertCount( 1, $data, 'Orphan term should be skipped, so only the editor is returned.' );
		$this->assertSame( $editor_term_id, $data[0]['termId'] );
		$this->assertIsInt( $data[0]['termId'], 'Returned termId must be an int, never null.' );
	}

	public function test_rest_save_with_no_resolvable_term_ids_falls_back_to_post_author(): void {
		$editor = $this->create_editor( 'rest-fallback-author' );
		$post   = $this->create_post( $editor );

		$this->assertSame(
			array( 'cap-' . $editor->user_login ),
			$this->coauthor_slugs_of( $post->ID ),
			'The editor is the only co-author before the save.'
		);

		wp_set_current_user( $editor->ID );

		// Save with a bogus term ID; wp_set_object_terms drops it, leaving the post
		// termless unless the fallback restores the post_author.
		$this->save_via_rest(
			$post->ID,
			array(
				'coauthors' => array( 999999 ),
				'title'     => 'After bogus save',
			)
		);

		$this->assertSame(
			array( 'cap-' . $editor->user_login ),
			$this->coauthor_slugs_of( $post->ID ),
			'A save that drops every submitted term must fall back to the post_author, not leave the post termless.'
		);
	}

	// -- #1241: a REST read must not mutate co-author assignments -----------

	public function test_rest_post_read_with_fields_filter_preserves_guest_author(): void {
		$editor          = $this->create_editor( 'rest-read-editor' );
		$post            = $this->create_post( $editor );
		$guest_author_id = $this->create_guest_author( 'rest_read_ga' );
		$guest_author    = $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_author_id );

		$this->_cap->add_coauthors( $post->ID, array( $guest_author->user_login ) );

		$terms_before = $this->coauthor_slugs_of( $post->ID );
		$this->assertContains(
			'cap-' . $guest_author->user_login,
			$terms_before,
			'Guest author term should be assigned before the REST read.'
		);

		wp_set_current_user( $editor->ID );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post->ID );
		$request->set_param( 'context', 'edit' );
		$request->set_param( '_fields', 'id' );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$this->assertSame(
			$terms_before,
			$this->coauthor_slugs_of( $post->ID ),
			'A REST read with _fields=id must not mutate the coauthor taxonomy assignments.'
		);
	}
}
