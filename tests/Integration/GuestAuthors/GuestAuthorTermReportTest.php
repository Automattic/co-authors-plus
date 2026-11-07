<?php
/**
 * Tests for the report of guest authors with no usable author term.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Services\Guest_Author_Term_Report_Service;
use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_Term;

/**
 * @covers \Automattic\CoAuthorsPlus\Services\Guest_Author_Term_Report_Service
 */
class GuestAuthorTermReportTest extends TestCase {

	/**
	 * The report.
	 *
	 * @var Guest_Author_Term_Report_Service
	 */
	private $report;

	public function set_up() {
		parent::set_up();

		// The service is only loaded for WP-CLI.
		require_once dirname( __DIR__, 3 ) . '/php/services/class-guest-author-term-report-service.php';

		$this->report = new Guest_Author_Term_Report_Service( $this->_cap );
	}

	/**
	 * Create a guest author whose login was stored with the prefix.
	 *
	 * @param string $clean     The login without its prefix.
	 * @param string $term_name The name of the guest author's term.
	 * @return int Guest author ID.
	 */
	private function create_affected_guest( string $clean, string $term_name = 'an old name' ): int {
		$guest_id = $this->create_guest_author( $clean );

		update_post_meta( $guest_id, 'cap-user_login', 'cap-' . $clean );
		wp_update_term( $this->get_term( 'cap-' . $clean )->term_id, $this->_cap->coauthor_taxonomy, array( 'name' => $term_name ) );
		$this->forget( $guest_id );

		return $guest_id;
	}

	/**
	 * Clear what's cached about a guest author.
	 *
	 * @param int $guest_id Guest author ID.
	 * @return void
	 */
	private function forget( int $guest_id ): void {
		$guest = $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_id, true );

		$this->_cap->guest_authors->delete_guest_author_cache( $guest_id );
		wp_cache_delete( 'author-term-' . $guest->user_nicename, 'co-authors-plus' );
	}

	/**
	 * Get an author term.
	 *
	 * @param string $slug The term slug.
	 * @return WP_Term
	 */
	private function get_term( string $slug ): WP_Term {
		return get_term_by( 'slug', $slug, $this->_cap->coauthor_taxonomy );
	}

	/**
	 * Attach a new post to an author term.
	 *
	 * @param string $slug   The term slug.
	 * @param string $status The post status.
	 * @return int Post ID.
	 */
	private function add_post_to( string $slug, string $status = 'publish' ): int {
		$post_id = $this->factory()->post->create( array( 'post_status' => $status ) );

		wp_set_object_terms( $post_id, array( $this->get_term( $slug )->term_id ), $this->_cap->coauthor_taxonomy );

		return $post_id;
	}

	/**
	 * Give a guest author the doubled term that saving them creates.
	 *
	 * @param int $guest_id Guest author ID.
	 * @return void
	 */
	private function double_the_term( int $guest_id ): void {
		$this->_cap->update_author_term( $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_id ) );
		$this->forget( $guest_id );
	}

	public function test_a_guest_with_a_prefixed_login_and_no_term_is_reported(): void {
		$guest_id = $this->create_affected_guest( 'jane-doe', 'jane doe' );

		$row = $this->report->inspect( $guest_id );

		$this->assertSame( 'no-term', $row['status'] );
		$this->assertSame( $guest_id, $row['ID'] );
		$this->assertSame( 'cap-jane-doe', $row['user_login'] );
		$this->assertSame( '', $row['resolved_term'] );
		$this->assertSame( 'cap-jane-doe', $row['matching_term'] );
		$this->assertSame( 'jane doe', $row['matching_term_name'] );
		$this->assertSame( '', $row['matching_term_owner'] );
		$this->assertSame( '', $row['doubled_term'] );
	}

	public function test_a_healthy_guest_is_not_reported(): void {
		$this->assertNull( $this->report->inspect( $this->create_guest_author( 'healthy' ) ) );
	}

	public function test_a_guest_whose_term_holds_their_prefixed_login_is_not_reported(): void {
		$this->assertNull( $this->report->inspect( $this->create_affected_guest( 'jane-doe', 'cap-jane-doe' ) ) );
	}

	public function test_a_legitimate_cap_login_with_nothing_beside_it_is_not_reported(): void {
		$this->assertNull( $this->report->inspect( $this->create_guest_author( 'Cap Ri' ) ) );
	}

	public function test_a_legitimate_cap_login_beside_a_user_it_resembles_is_not_reported(): void {
		$this->_cap->update_author_term( $this->create_author( 'ri' ) );

		$this->add_post_to( 'cap-ri' );

		$guest_id = $this->create_guest_author( 'Cap Ri' );

		$this->assertSame( 'cap-cap-ri', $this->_cap->get_author_term( $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_id ) )->slug );
		$this->assertNull( $this->report->inspect( $guest_id ) );
	}

	public function test_the_count_leaves_out_unpublished_posts_and_the_objects_leave_out_the_profile(): void {
		$guest_id = $this->create_affected_guest( 'jane-doe' );

		$this->add_post_to( 'cap-jane-doe' );
		$this->add_post_to( 'cap-jane-doe', 'draft' );

		$this->assertContains( $guest_id, array_map( 'intval', get_objects_in_term( $this->get_term( 'cap-jane-doe' )->term_id, $this->_cap->coauthor_taxonomy ) ) );

		$row = $this->report->inspect( $guest_id );

		$this->assertSame( 1, $row['matching_term_count'] );
		$this->assertSame( 2, $row['matching_term_objects'] );
	}

	public function test_a_guest_with_a_doubled_term_and_posts_on_the_other_is_reported_as_split(): void {
		$guest_id = $this->create_affected_guest( 'jane-doe' );
		$this->add_post_to( 'cap-jane-doe' );
		$this->double_the_term( $guest_id );
		$this->add_post_to( 'cap-cap-jane-doe' );
		$this->add_post_to( 'cap-cap-jane-doe' );

		$row = $this->report->inspect( $guest_id );

		$this->assertSame( 'split', $row['status'] );
		$this->assertSame( 'cap-cap-jane-doe', $row['resolved_term'] );
		$this->assertSame( 'cap-jane-doe', $row['matching_term'] );
		$this->assertSame( 1, $row['matching_term_objects'] );
		$this->assertSame( 'cap-cap-jane-doe', $row['doubled_term'] );
		$this->assertSame( 2, $row['doubled_term_count'] );
		$this->assertSame( 2, $row['doubled_term_objects'] );
	}

	public function test_a_doubled_term_with_nothing_left_on_the_other_is_not_reported(): void {
		$guest_id = $this->create_affected_guest( 'jane-doe' );
		$this->double_the_term( $guest_id );

		$this->assertNull( $this->report->inspect( $guest_id ) );
	}

	public function test_a_term_belonging_to_a_user_is_not_called_unclaimed(): void {
		$guest_id = $this->create_affected_guest( 'jane-doe' );
		$user     = $this->create_author( 'jane-doe' );

		$this->assertSame( 'cap-jane-doe', $this->_cap->get_author_term( $user )->slug );

		$row = $this->report->inspect( $guest_id );

		$this->assertSame( 'term-owned-by-other', $row['status'] );
		$this->assertSame( 'user:jane-doe', $row['matching_term_owner'] );
		$this->assertSame( '', $row['resolved_term'] );
	}

	public function test_a_term_belonging_to_another_guest_is_not_called_unclaimed(): void {
		$this->create_guest_author( 'jane-doe' );
		$guest_id = $this->create_guest_author( 'someone-else' );

		update_post_meta( $guest_id, 'cap-user_login', 'cap-jane-doe' );
		$this->forget( $guest_id );

		$row = $this->report->inspect( $guest_id );

		$this->assertSame( 'term-owned-by-other', $row['status'] );
		$this->assertSame( 'guest-author:jane-doe', $row['matching_term_owner'] );
	}

	public function test_a_linked_guest_with_no_term_shows_the_link(): void {
		$linked   = $this->create_author( 'linked' );
		$guest_id = $this->create_affected_guest( 'jane-doe' );

		update_post_meta( $guest_id, 'cap-linked_account', $linked->user_login );
		$this->forget( $guest_id );

		$row = $this->report->inspect( $guest_id );

		$this->assertSame( 'no-term', $row['status'] );
		$this->assertSame( 'linked', $row['linked_account'] );
	}

	public function test_a_healthy_linked_guest_is_not_reported(): void {
		$user     = $this->create_author( 'linked' );
		$guest_id = $this->_cap->guest_authors->create_guest_author_from_user_id( $user->ID );

		$this->assertNull( $this->report->inspect( $guest_id ) );
	}

	public function test_a_guest_with_no_term_at_all_is_reported(): void {
		$guest_id = $this->create_guest_author( 'no-term-left' );

		wp_delete_term( $this->get_term( 'cap-no-term-left' )->term_id, $this->_cap->coauthor_taxonomy );
		$this->forget( $guest_id );

		$row = $this->report->inspect( $guest_id );

		$this->assertSame( 'no-term', $row['status'] );
		$this->assertSame( '', $row['matching_term'] );
		$this->assertSame( 0, $row['matching_term_count'] );
	}

	public function test_a_guest_with_an_empty_login_and_name_is_reported_without_a_term(): void {
		$guest_id = $this->create_guest_author( 'blank' );

		update_post_meta( $guest_id, 'cap-user_login', '' );
		update_post_meta( $guest_id, 'cap-display_name', '' );
		$this->forget( $guest_id );

		$row = $this->report->inspect( $guest_id );

		$this->assertSame( 'no-term', $row['status'] );
		$this->assertSame( '', $row['user_login'] );
		$this->assertSame( '', $row['display_name'] );
	}

	public function test_a_clean_login_on_a_plain_term_is_not_reported(): void {
		$guest_id = $this->create_guest_author( 'plain' );

		wp_update_term( $this->get_term( 'cap-plain' )->term_id, $this->_cap->coauthor_taxonomy, array( 'slug' => 'plain' ) );
		$this->forget( $guest_id );

		$this->assertNull( $this->report->inspect( $guest_id ) );
	}

	public function test_a_prefixed_login_beside_a_plain_term_is_still_reported(): void {
		$guest_id = $this->create_affected_guest( 'jane-doe' );

		wp_insert_term( 'jane-doe', $this->_cap->coauthor_taxonomy, array( 'slug' => 'jane-doe' ) );

		$this->assertSame( 'no-term', $this->report->inspect( $guest_id )['status'] );
	}

	public function test_an_ampersand_in_a_login_is_reported_as_the_lookup_sees_it(): void {
		$guest_id = $this->create_affected_guest( 'ab', 'cap-a&b' );

		update_post_meta( $guest_id, 'cap-user_login', 'cap-a&b' );
		update_post_meta( $guest_id, 'cap-display_name', 'A & B, Reporters' );
		$this->forget( $guest_id );

		$row = $this->report->inspect( $guest_id );

		$this->assertSame( 'no-term', $row['status'] );
		$this->assertSame( 'cap-a&b', $row['user_login'] );
		$this->assertSame( 'A & B, Reporters', $row['display_name'] );
		$this->assertSame( 'cap-ab', $row['matching_term'] );
		$this->assertSame( 'cap-a&amp;b', $row['matching_term_name'] );
	}

	public function test_guest_author_meta_on_another_post_type_is_never_read(): void {
		$this->create_affected_guest( 'jane-doe' );
		$post_id = $this->factory()->post->create();
		update_post_meta( $post_id, 'cap-user_login', 'cap-jane-doe' );

		$this->assertNull( $this->report->inspect( $post_id ) );
		$this->assertSame( 1, $this->report->get_page( 1, 100 )['checked'] );
	}

	public function test_a_draft_guest_is_checked_and_a_trashed_guest_is_not(): void {
		$draft = $this->create_affected_guest( 'in-draft' );
		$trash = $this->create_affected_guest( 'in-trash' );

		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);
		wp_trash_post( $trash );

		$this->assertSame( array( $draft ), wp_list_pluck( $this->report->get_page( 1, 100 )['rows'], 'ID' ) );
	}

	public function test_guests_are_walked_a_page_at_a_time_without_repeats(): void {
		$first  = $this->create_affected_guest( 'first' );
		$second = $this->create_affected_guest( 'second' );
		$this->create_guest_author( 'healthy' );
		$fourth = $this->create_affected_guest( 'fourth' );

		$page_one = $this->report->get_page( 1, 2 );
		$page_two = $this->report->get_page( 2, 2 );

		$this->assertSame( 2, $page_one['checked'] );
		$this->assertSame( array( $first, $second ), wp_list_pluck( $page_one['rows'], 'ID' ) );
		$this->assertSame( 2, $page_two['checked'] );
		$this->assertSame( array( $fourth ), wp_list_pluck( $page_two['rows'], 'ID' ) );
		$this->assertSame( 0, $this->report->get_page( 3, 2 )['checked'] );
	}

	public function test_the_report_runs_no_write_queries(): void {
		$this->create_affected_guest( 'no-term' );
		$split = $this->create_affected_guest( 'split' );
		$this->add_post_to( 'cap-split' );
		$this->double_the_term( $split );
		$this->create_affected_guest( 'owned' );
		$this->create_author( 'owned' );

		$writes  = array();
		$capture = static function ( string $query ) use ( &$writes ): string {
			if ( ! preg_match( '/^\s*(SELECT|SHOW|SET)\b/i', $query ) ) {
				$writes[] = $query;
			}

			return $query;
		};

		add_filter( 'query', $capture );
		$rows = $this->report->get_page( 1, 100 )['rows'];
		remove_filter( 'query', $capture );

		$this->assertSame( array(), $writes );
		$this->assertCount( 3, $rows );
	}
}
