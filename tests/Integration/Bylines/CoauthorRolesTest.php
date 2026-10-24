<?php
/**
 * Tests for per-post co-author roles.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use CoAuthors\Blocks\Block_CoAuthor_Role;
use WP_Block;
use WP_Block_Type_Registry;
use WP_REST_Request;

/**
 * Roles are saved in post meta, keyed by author term ID, and read back through
 * the template tag, the REST API and the Co-Author Role block.
 *
 * @see https://github.com/Automattic/Co-Authors-Plus/issues/164
 *
 * @covers \CoAuthors_Plus::set_coauthor_roles
 * @covers \CoAuthors_Plus::get_coauthor_roles
 * @covers \CoAuthors_Plus::get_coauthor_role
 */
class CoauthorRolesTest extends TestCase {

	/**
	 * @var \WP_User
	 */
	private $admin;

	/**
	 * @var \WP_User
	 */
	private $writer;

	/**
	 * @var \WP_User
	 */
	private $photographer;

	public function set_up() {
		parent::set_up();

		$this->admin        = $this->factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$this->writer       = $this->create_author( 'writer' );
		$this->photographer = $this->create_author( 'photographer' );
	}

	public function tear_down(): void {
		unset( $_REQUEST['coauthors-nonce'], $_POST['coauthors'], $_POST['coauthors-nonce'], $_POST['coauthorsroles'] );
		parent::tear_down();
	}

	/**
	 * Create a post credited to the writer and the photographer.
	 */
	private function create_credited_post(): int {
		$post_id = $this->create_post( $this->writer )->ID;
		$this->_cap->add_coauthors( $post_id, array( $this->writer->user_nicename, $this->photographer->user_nicename ) );

		return $post_id;
	}

	private function term_id( object $author ): int {
		return (int) $this->_cap->get_author_term( $author )->term_id;
	}

	public function test_roles_round_trip_for_authors_on_the_post(): void {
		$post_id = $this->create_credited_post();

		$this->_cap->set_coauthor_roles(
			$post_id,
			array(
				$this->term_id( $this->writer )       => 'Writer',
				$this->term_id( $this->photographer ) => 'Photographer',
			)
		);

		$this->assertSame( 'Writer', get_coauthor_role( $this->writer, $post_id ) );
		$this->assertSame( 'Photographer', get_coauthor_role( $this->photographer, $post_id ) );
	}

	public function test_template_tag_defaults_to_the_global_post(): void {
		global $post;

		$post_id = $this->create_credited_post();
		$this->_cap->set_coauthor_roles( $post_id, array( $this->term_id( $this->writer ) => 'Writer' ) );

		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertSame( 'Writer', get_coauthor_role( $this->writer ) );
	}

	public function test_author_without_role_gets_empty_string(): void {
		$post_id = $this->create_credited_post();

		$this->assertSame( '', get_coauthor_role( $this->writer, $post_id ) );
		$this->assertSame( '', get_coauthor_role( null, $post_id ) );
	}

	public function test_empty_roles_and_authors_not_on_post_are_dropped(): void {
		$post_id  = $this->create_credited_post();
		$stranger = $this->create_author( 'stranger' );
		$this->_cap->update_author_term( $stranger );

		$this->_cap->set_coauthor_roles(
			$post_id,
			array(
				$this->term_id( $this->writer )       => '  ',
				$this->term_id( $this->photographer ) => array( 'not a string' ),
				$this->term_id( $stranger )           => 'Editor',
			)
		);

		$this->assertSame( array(), $this->_cap->get_coauthor_roles( $post_id ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, '_coauthor_roles' ) );
	}

	public function test_roles_are_sanitized_and_keep_backslashes(): void {
		$post_id = $this->create_credited_post();

		$this->_cap->set_coauthor_roles( $post_id, array( $this->term_id( $this->writer ) => '<b>Writer</b> \\ Editor' ) );

		$this->assertSame( 'Writer \\ Editor', get_coauthor_role( $this->writer, $post_id ) );
	}

	/**
	 * @covers \CoAuthors_Plus::add_coauthors
	 */
	public function test_removing_an_author_drops_their_role(): void {
		$post_id = $this->create_credited_post();
		$this->_cap->set_coauthor_roles(
			$post_id,
			array(
				$this->term_id( $this->writer )       => 'Writer',
				$this->term_id( $this->photographer ) => 'Photographer',
			)
		);

		$this->_cap->add_coauthors( $post_id, array( $this->writer->user_nicename ) );

		$this->assertSame( array( $this->term_id( $this->writer ) => 'Writer' ), $this->_cap->get_coauthor_roles( $post_id ) );
	}

	/**
	 * Post the classic Authors box form, as coauthors_update_post() reads it.
	 *
	 * @param int        $post_id Post ID.
	 * @param array      $authors Author nicenames.
	 * @param array|null $roles   Roles, or null to leave them out like Quick Edit does.
	 */
	private function post_classic_form( int $post_id, array $authors, ?array $roles ): void {
		wp_set_current_user( $this->admin->ID );

		$nonce                       = wp_create_nonce( 'coauthors-edit' );
		$_POST['coauthors-nonce']    = $nonce;
		$_REQUEST['coauthors-nonce'] = $nonce;
		$_POST['coauthors']          = $authors;
		if ( null !== $roles ) {
			$_POST['coauthorsroles'] = $roles;
		}

		$this->_cap->coauthors_update_post( $post_id, get_post( $post_id ) );
	}

	/**
	 * @covers \CoAuthors_Plus::coauthors_update_post
	 * @covers \CoAuthors_Plus::save_posted_coauthor_roles
	 */
	public function test_classic_form_saves_roles_in_author_order(): void {
		$post_id = $this->create_post( $this->writer )->ID;

		$this->post_classic_form(
			$post_id,
			array( $this->photographer->user_nicename, $this->writer->user_nicename ),
			array( 'Photographer', 'Writer' )
		);

		$this->assertSame( 'Photographer', get_coauthor_role( $this->photographer, $post_id ) );
		$this->assertSame( 'Writer', get_coauthor_role( $this->writer, $post_id ) );
	}

	/**
	 * @covers \CoAuthors_Plus::coauthors_update_post
	 */
	public function test_classic_form_without_roles_keeps_saved_roles(): void {
		$post_id = $this->create_credited_post();
		$this->_cap->set_coauthor_roles( $post_id, array( $this->term_id( $this->writer ) => 'Writer' ) );

		$this->post_classic_form( $post_id, array( $this->writer->user_nicename, $this->photographer->user_nicename ), null );

		$this->assertSame( 'Writer', get_coauthor_role( $this->writer, $post_id ) );
	}

	/**
	 * @covers \CoAuthors_Plus::rest_update_coauthor_roles
	 * @covers \CoAuthors_Plus::rest_get_coauthor_roles
	 */
	public function test_rest_saves_role_for_author_added_in_same_request(): void {
		wp_set_current_user( $this->admin->ID );
		$post_id = $this->create_post( $this->writer )->ID;
		$this->_cap->add_coauthors( $post_id, array( $this->writer->user_nicename ) );
		$this->_cap->update_author_term( $this->photographer );

		$writer_term       = $this->term_id( $this->writer );
		$photographer_term = $this->term_id( $this->photographer );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'coauthors', array( $writer_term, $photographer_term ) );
		$request->set_param( 'coauthor_roles', array( (string) $photographer_term => 'Photographer' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Photographer', get_coauthor_role( $this->photographer, $post_id ) );
		$this->assertEquals( (object) array( $photographer_term => 'Photographer' ), $response->get_data()['coauthor_roles'] );
	}

	public function test_rest_sends_empty_object_when_no_roles(): void {
		$post_id = $this->create_credited_post();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );

		$this->assertSame( '{}', wp_json_encode( $response->get_data()['coauthor_roles'] ) );
	}

	/**
	 * @covers \CoAuthors_Plus::rest_update_coauthor_roles
	 */
	public function test_rest_rejects_roles_from_user_who_cannot_set_authors(): void {
		$post_id = $this->create_credited_post();
		wp_set_current_user( $this->writer->ID );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'coauthor_roles', array( (string) $this->term_id( $this->writer ) => 'Boss' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( '', get_coauthor_role( $this->writer, $post_id ) );
	}

	/**
	 * @covers \CoAuthors\API\Endpoints\CoAuthors_Controller::prepare_item_for_response
	 */
	public function test_coauthors_endpoint_returns_role_for_the_post(): void {
		$post_id = $this->create_credited_post();
		$this->_cap->set_coauthor_roles( $post_id, array( $this->term_id( $this->photographer ) => 'Photographer' ) );

		$request = new WP_REST_Request( 'GET', '/coauthors/v1/coauthors' );
		$request->set_param( 'post_id', $post_id );
		$data = rest_do_request( $request )->get_data();

		$this->assertSame( array( '', 'Photographer' ), wp_list_pluck( $data, 'role' ) );
	}

	/**
	 * The single author route doesn't check post_id, so it must not leak a draft's roles.
	 *
	 * @covers \CoAuthors\API\Endpoints\CoAuthors_Controller::prepare_item_for_response
	 */
	public function test_single_author_endpoint_hides_role_on_unreadable_post(): void {
		$post_id = $this->create_credited_post();
		$this->_cap->set_coauthor_roles( $post_id, array( $this->term_id( $this->writer ) => 'Writer' ) );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		// The author still needs a public post for the route to answer anonymous requests.
		$this->_cap->add_coauthors( $this->create_post( $this->writer )->ID, array( $this->writer->user_nicename ) );
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/coauthors/v1/coauthors/' . $this->writer->user_nicename );
		$request->set_param( 'post_id', $post_id );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', $response->get_data()['role'] );
	}

	/**
	 * Render the role block with the given author context.
	 *
	 * @param array $author Author context.
	 */
	private function render_role_block( array $author ): string {
		$registry = WP_Block_Type_Registry::get_instance();
		if ( ! $registry->is_registered( 'co-authors-plus/role' ) ) {
			register_block_type(
				'co-authors-plus/role',
				array(
					'uses_context'    => array( 'co-authors-plus/author' ),
					'render_callback' => array( Block_CoAuthor_Role::class, 'render_block' ),
				)
			);
		}

		$block = new WP_Block(
			array(
				'blockName'    => 'co-authors-plus/role',
				'attrs'        => array( 'textAlign' => 'center' ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array( 'co-authors-plus/author' => $author )
		);

		return $block->render();
	}

	/**
	 * @covers \CoAuthors\Blocks\Block_CoAuthor_Role::render_block
	 */
	public function test_role_block_renders_escaped_role(): void {
		$html = $this->render_role_block( array( 'role' => 'Photos & <i>art</i>' ) );

		$this->assertStringStartsWith( '<p ', $html );
		$this->assertStringContainsString( 'has-text-align-center', $html );
		$this->assertStringContainsString( 'Photos &amp; &lt;i&gt;art&lt;/i&gt;', $html );
	}

	/**
	 * @covers \CoAuthors\Blocks\Block_CoAuthor_Role::render_block
	 */
	public function test_role_block_renders_nothing_without_role(): void {
		$this->assertSame( '', $this->render_role_block( array( 'role' => '' ) ) );
		$this->assertSame( '', $this->render_role_block( array() ) );
	}
}
