<?php
/**
 * Characterisation tests for how a co-author's identity is derived.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * What the plugin does today with the 'cap-' prefix, including where it is wrong.
 *
 * These are characterisation tests, not a specification. Several assertions
 * below pin behaviour that is clearly a bug; they are here so that whoever
 * untangles this can see, from a red test, exactly what they changed.
 *
 * A co-author's identity is derived in three places, by three rules that
 * differ in operation order as well as in condition:
 *
 * 1. `create()` prefixes the raw user_login, then sanitises:
 *    sanitize_title( get_post_meta_key( $user_login ) )
 * 2. `manage_guest_author_filter_post_data()` sanitises first, then prefixes:
 *    get_post_meta_key( sanitize_title( $user_login ) )
 * 3. `update_author_term()` prefixes the already-sanitised nicename,
 *    unconditionally: 'cap-' . $user_nicename
 *
 * `sanitize_title()` turns "Cap Ri" into "cap-ri", so whether the prefix is
 * applied before or after it decides whether get_post_meta_key() considers the
 * value already prefixed. For any guest author whose name begins with the word
 * "Cap", the three rules disagree.
 *
 * They also depend on each other's inconsistencies, which is why none of them
 * can be corrected on its own — see the last two tests.
 *
 * @see https://github.com/Automattic/co-authors-plus/issues/1397
 *
 * @covers \CoAuthors_Guest_Authors::create
 * @covers \CoAuthors_Guest_Authors::get_guest_author_by
 * @covers \CoAuthors_Guest_Authors::manage_guest_author_filter_post_data
 * @covers \CoAuthors_Plus::update_author_term
 */
class GuestAuthorIdentityDerivationTest extends TestCase {

	/**
	 * @var \CoAuthors_Guest_Authors
	 */
	private $guest_authors;

	public function set_up() {
		parent::set_up();

		$this->guest_authors = $this->_cap->guest_authors;

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		unset( $_POST['guest-author-nonce'], $_POST['cap-display_name'] );

		parent::tear_down();
	}

	/**
	 * Create a guest author and return its post ID.
	 *
	 * @param string $user_login The login to create it under.
	 * @return int
	 */
	private function create_guest_author_named( string $user_login ): int {
		$id = $this->guest_authors->create(
			array(
				'display_name' => $user_login,
				'user_login'   => $user_login,
			)
		);

		$this->assertIsInt( $id, 'Guest author creation should succeed.' );

		return $id;
	}

	/**
	 * Run the edit-screen post-data filter and return the post_name it computes.
	 *
	 * The $_POST values live only for the duration of the call: the filter is
	 * hooked to wp_insert_post_data, so a nonce left in place would make it
	 * engage during any later create() with no form data behind it.
	 *
	 * @param int    $guest_author_id Guest author post ID.
	 * @param string $display_name    Display name submitted with the form.
	 * @return string
	 */
	private function post_name_after_edit_save( int $guest_author_id, string $display_name ): string {
		$_POST['guest-author-nonce'] = wp_create_nonce( 'guest-author-nonce' );
		$_POST['cap-display_name']   = $display_name;

		try {
			$post_data = $this->guest_authors->manage_guest_author_filter_post_data(
				array( 'post_type' => $this->guest_authors->post_type ),
				array(
					'ID'        => $guest_author_id,
					'post_type' => $this->guest_authors->post_type,
				)
			);
		} finally {
			unset( $_POST['guest-author-nonce'], $_POST['cap-display_name'] );
		}

		return $post_data['post_name'];
	}

	/**
	 * The identity a given user_login produces at each of the three sites.
	 *
	 * The final column is the point: for an ordinary name every derivation
	 * agrees, and for a name beginning with "Cap" none of them do.
	 *
	 * @return array<string, array{string, string, string, string, string}>
	 */
	public function data_identity_derivations(): array {
		return array(
			// user_login, create() post_name, edit-save post_name, nicename, term slug.
			'ordinary name'          => array( 'Ada Lovelace', 'cap-ada-lovelace', 'cap-ada-lovelace', 'ada-lovelace', 'cap-ada-lovelace' ),
			'prefix inside the name' => array( 'Recap Caption', 'cap-recap-caption', 'cap-recap-caption', 'recap-caption', 'cap-recap-caption' ),
			'name beginning "Cap"'   => array( 'Cap Ri', 'cap-cap-ri', 'cap-ri', 'cap-ri', 'cap-cap-ri' ),
			'login already prefixed' => array( 'cap-ri', 'cap-ri', 'cap-ri', 'cap-ri', 'cap-cap-ri' ),
		);
	}

	/**
	 * Creation prefixes the raw login before sanitising it.
	 *
	 * @dataProvider data_identity_derivations
	 *
	 * @param string $user_login   Login to create under.
	 * @param string $created_slug post_name create() should store.
	 */
	public function test_create_derives_the_post_name_from_the_raw_login( string $user_login, string $created_slug ): void {
		$id = $this->create_guest_author_named( $user_login );

		$this->assertSame( $created_slug, get_post( $id )->post_name );
	}

	/**
	 * The edit screen sanitises first, so it can compute a different post_name.
	 *
	 * Where the two columns differ, saving an untouched guest author through
	 * the admin silently renames it.
	 *
	 * @dataProvider data_identity_derivations
	 *
	 * @param string $user_login   Login to create under.
	 * @param string $created_slug post_name create() stores.
	 * @param string $edited_slug  post_name the edit screen computes.
	 */
	public function test_the_edit_screen_can_compute_a_different_post_name( string $user_login, string $created_slug, string $edited_slug ): void {
		$id = $this->create_guest_author_named( $user_login );

		$this->assertSame( $created_slug, get_post( $id )->post_name );
		$this->assertSame( $edited_slug, $this->post_name_after_edit_save( $id, $user_login ) );
	}

	/**
	 * The nicename is re-derived from the user_login meta on every read.
	 *
	 * @dataProvider data_identity_derivations
	 *
	 * @param string $user_login Login to create under.
	 * @param string $_created   Unused here.
	 * @param string $_edited    Unused here.
	 * @param string $nicename   Expected user_nicename.
	 */
	public function test_the_nicename_is_derived_from_the_user_login( string $user_login, string $_created, string $_edited, string $nicename ): void {
		$id = $this->create_guest_author_named( $user_login );

		$this->assertSame( $nicename, $this->guest_authors->get_guest_author_by( 'ID', $id )->user_nicename );
	}

	/**
	 * The author term slug prefixes the nicename unconditionally.
	 *
	 * @dataProvider data_identity_derivations
	 *
	 * @param string $user_login Login to create under.
	 * @param string $_created   Unused here.
	 * @param string $_edited    Unused here.
	 * @param string $_nicename  Unused here.
	 * @param string $term_slug  Expected author term slug.
	 */
	public function test_the_author_term_slug_prefixes_the_nicename( string $user_login, string $_created, string $_edited, string $_nicename, string $term_slug ): void {
		$id     = $this->create_guest_author_named( $user_login );
		$author = $this->guest_authors->get_guest_author_by( 'ID', $id );

		$this->_cap->update_author_term( $author );

		$this->assertSame( $term_slug, $this->_cap->get_author_term( $author )->slug );
	}

	/**
	 * BUG: a guest author whose name begins with "Cap" cannot be found by nicename.
	 *
	 * Creation stores post_name 'cap-cap-ri'; the nicename is 'cap-ri'; and the
	 * lookup converts 'cap-ri' with the meta key rule, which leaves it alone
	 * because it already looks prefixed. It then searches for post_name
	 * 'cap-ri', which does not exist.
	 */
	public function test_a_name_beginning_cap_cannot_be_found_by_nicename_after_creation(): void {
		$id     = $this->create_guest_author_named( 'Cap Ri' );
		$author = $this->guest_authors->get_guest_author_by( 'ID', $id );

		$this->assertSame( 'cap-ri', $author->user_nicename );
		$this->assertFalse(
			$this->guest_authors->get_guest_author_by( 'user_nicename', $author->user_nicename ),
			'Characterisation, not desired behaviour: the author is unreachable by its own nicename.'
		);
	}

	/**
	 * The control: an ordinary guest author is found by nicename straight away.
	 */
	public function test_an_ordinary_name_is_found_by_nicename_after_creation(): void {
		$id     = $this->create_guest_author_named( 'Ada Lovelace' );
		$author = $this->guest_authors->get_guest_author_by( 'ID', $id );

		$found = $this->guest_authors->get_guest_author_by( 'user_nicename', $author->user_nicename );

		$this->assertIsObject( $found );
		$this->assertSame( $id, (int) $found->ID );
	}

	/**
	 * BUG, and the reason none of the three rules can be fixed alone: the
	 * broken post_name is what repairs the broken lookup.
	 *
	 * Saving the author through the edit screen rewrites post_name from
	 * 'cap-cap-ri' to 'cap-ri', which makes the nicename lookup succeed — and
	 * simultaneously puts post_name out of step with the author term, which is
	 * still 'cap-cap-ri'. Correcting either one in isolation breaks the other.
	 */
	public function test_saving_through_the_edit_screen_repairs_the_lookup_and_breaks_the_term(): void {
		$id     = $this->create_guest_author_named( 'Cap Ri' );
		$author = $this->guest_authors->get_guest_author_by( 'ID', $id );
		$this->_cap->update_author_term( $author );

		$this->assertSame( 'cap-cap-ri', $this->_cap->get_author_term( $author )->slug );

		// Apply what the edit screen would have saved.
		wp_update_post(
			array(
				'ID'        => $id,
				'post_name' => $this->post_name_after_edit_save( $id, 'Cap Ri' ),
			)
		);

		$this->assertSame( 'cap-ri', get_post( $id )->post_name );

		$found = $this->guest_authors->get_guest_author_by( 'user_nicename', 'cap-ri', true );
		$this->assertIsObject( $found, 'The rename is what makes the lookup work.' );
		$this->assertSame( $id, (int) $found->ID );

		// And now the post and its term disagree about who this author is.
		$this->assertNotSame(
			get_post( $id )->post_name,
			$this->_cap->get_author_term( $author )->slug
		);
	}

	/**
	 * The nicename lookup doubles as a term-slug lookup, by accident.
	 *
	 * CoAuthors_Plus::search_authors() passes a whole author term slug where a nicename is
	 * expected, and it resolves only because the meta key rule leaves an
	 * already-prefixed value alone. Making that conversion unconditional — the
	 * obvious way to align it with the term slug rule — would break every
	 * ordinary author lookup, not just the edge case.
	 */
	public function test_the_nicename_lookup_also_accepts_a_whole_term_slug(): void {
		$id     = $this->create_guest_author_named( 'Ada Lovelace' );
		$author = $this->guest_authors->get_guest_author_by( 'ID', $id );
		$this->_cap->update_author_term( $author );

		$term_slug = $this->_cap->get_author_term( $author )->slug;
		$this->assertSame( 'cap-ada-lovelace', $term_slug );

		$found = $this->_cap->get_coauthor_by( 'user_nicename', $term_slug );

		$this->assertIsObject( $found );
		$this->assertSame( $id, (int) $found->ID );
	}
}
