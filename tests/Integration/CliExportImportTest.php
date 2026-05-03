<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use WP_CLI\ExitException;

/**
 * Integration tests for the WP-CLI export-coauthors and import-coauthors commands.
 *
 * These tests exercise the command logic by calling the underlying plugin APIs
 * and inspecting database state. They do not invoke WP-CLI itself, which would
 * require a separate process and cannot run inside wp-env PHPUnit.
 *
 * @covers CoAuthorsPlus_Command::export_coauthors()
 * @covers CoAuthorsPlus_Command::import_coauthors()
 *
 * @link https://github.com/Automattic/co-authors-plus/issues/1067
 */
class CliExportImportTest extends TestCase {

	/**
	 * Temporary file used for export/import JSON during tests.
	 *
	 * @var string
	 */
	private string $tmp_file;

	/**
	 * Reflection property for WP_CLI::$capture_exit.
	 *
	 * @var \ReflectionProperty
	 */
	private static \ReflectionProperty $capture_exit_prop;

	/**
	 * Original value of WP_CLI::$capture_exit before tests.
	 *
	 * @var bool
	 */
	private bool $original_capture_exit;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// WP_CLI::$capture_exit is private. Use reflection so that
		// WP_CLI::error() throws ExitException instead of calling exit(),
		// which would kill the entire PHPUnit process.
		self::$capture_exit_prop = new \ReflectionProperty( 'WP_CLI', 'capture_exit' );
		self::$capture_exit_prop->setAccessible( true );
	}

	public function set_up(): void {
		parent::set_up();
		$this->tmp_file = tempnam( sys_get_temp_dir(), 'cap-test-' ) . '.json';

		// Enable capture mode so WP_CLI::error() throws instead of exit().
		$this->original_capture_exit = self::$capture_exit_prop->getValue();
		self::$capture_exit_prop->setValue( null, true );
	}

	public function tear_down(): void {
		// Restore original capture_exit value.
		self::$capture_exit_prop->setValue( null, $this->original_capture_exit );

		if ( file_exists( $this->tmp_file ) ) {
			unlink( $this->tmp_file );
		}
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a guest author and return the full object.
	 *
	 * @param string $login     user_login for the guest author.
	 * @param string $name      display_name for the guest author.
	 * @param string $email     user_email for the guest author (optional).
	 * @return object|false Guest author object or false on failure.
	 */
	private function make_guest_author( string $login, string $name, string $email = '' ) {
		global $coauthors_plus;
		$id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => $name,
				'user_login'   => $login,
				'user_email'   => $email,
			)
		);
		$this->assertIsInt( $id, "Guest author '{$login}' should be created." );
		return $coauthors_plus->guest_authors->get_guest_author_by( 'ID', $id );
	}

	/**
	 * Create a published post with a specific slug and assign coauthors.
	 *
	 * @param string   $slug       post_name / slug.
	 * @param array    $coauthors  Array of guest-author objects to assign.
	 * @return \WP_Post
	 */
	private function make_post_with_coauthors( string $slug, array $coauthors ): \WP_Post {
		global $coauthors_plus;

		$author = $this->create_author();
		$post   = $this->factory()->post->create_and_get(
			array(
				'post_author'  => $author->ID,
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => $slug,
				'post_type'    => 'post',
			)
		);

		$logins = array_map(
			function ( $ga ) {
				return $ga->user_login;
			},
			$coauthors
		);
		$coauthors_plus->add_coauthors( $post->ID, $logins, false );

		return $post;
	}

	/**
	 * Export to $this->tmp_file and decode the result.
	 *
	 * @param array $post_types Optional list of post types to export.
	 * @return array Decoded export data.
	 */
	private function do_export( array $post_types = array() ): array {
		global $coauthors_plus;

		$assoc_args = array( 'file' => $this->tmp_file );
		if ( $post_types ) {
			$assoc_args['post-types'] = implode( ',', $post_types );
		}

		// Instantiate the command class directly — avoids needing a real CLI process.
		$command = new \CoAuthorsPlus_Command();
		$command->export_coauthors( array(), $assoc_args );

		$raw = file_get_contents( $this->tmp_file );
		$this->assertNotFalse( $raw, 'Export file should be readable.' );

		$data = json_decode( $raw, true );
		$this->assertIsArray( $data, 'Export output should be valid JSON.' );

		return $data;
	}

	/**
	 * Import from $this->tmp_file.
	 *
	 * @param bool $dry_run     Whether to run in dry-run mode.
	 * @param bool $skip_create Whether to skip guest-author profile creation.
	 */
	private function do_import( bool $dry_run = false, bool $skip_create = false ): void {
		$assoc_args = array( 'file' => $this->tmp_file );
		if ( $dry_run ) {
			$assoc_args['dry-run'] = true;
		}
		if ( $skip_create ) {
			$assoc_args['skip-create'] = true;
		}

		$command = new \CoAuthorsPlus_Command();
		$command->import_coauthors( array(), $assoc_args );
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * A round-trip export → import on the same site reproduces coauthor state exactly.
	 *
	 * After importing, get_coauthors() on each post should include the guest author
	 * at the correct position.
	 */
	public function test_round_trip_reproduces_coauthor_state(): void {
		global $coauthors_plus;

		$ga1  = $this->make_guest_author( 'roundtrip-author', 'Round Trip Author', 'rt@example.com' );
		$post = $this->make_post_with_coauthors( 'roundtrip-post', array( $ga1 ) );

		// Capture the coauthor list before export.
		$before = wp_list_pluck( get_coauthors( $post->ID ), 'user_login' );

		// Export, then delete the guest author + re-import on the same site.
		$this->do_export();
		$coauthors_plus->guest_authors->delete( $ga1->ID, false );
		$coauthors_plus->add_coauthors( $post->ID, array(), false );

		$this->do_import();

		$after = wp_list_pluck( get_coauthors( $post->ID ), 'user_login' );

		$this->assertEquals(
			$before,
			$after,
			'Coauthor list should be identical after round-trip.'
		);
	}

	/**
	 * Running import-coauthors twice (idempotency) does not duplicate coauthors
	 * or shift their positions.
	 */
	public function test_idempotency_running_import_twice(): void {
		$ga   = $this->make_guest_author( 'idempotent-author', 'Idempotent Author' );
		$post = $this->make_post_with_coauthors( 'idempotent-post', array( $ga ) );

		$this->do_export();

		// First import — author is already on the post, so nothing changes.
		$this->do_import();
		$after_first = wp_list_pluck( get_coauthors( $post->ID ), 'user_login' );

		// Second import — must be a no-op.
		$this->do_import();
		$after_second = wp_list_pluck( get_coauthors( $post->ID ), 'user_login' );

		$this->assertEquals(
			$after_first,
			$after_second,
			'Running import twice should not change coauthor assignments.'
		);
		$this->assertEquals(
			1,
			count( array_filter( $after_second, fn( $l ) => 'idempotent-author' === $l ) ),
			'Author should appear exactly once after two imports.'
		);
	}

	/**
	 * --dry-run flag makes no database writes: no guest authors created,
	 * no coauthor term relationships changed.
	 */
	public function test_dry_run_makes_no_db_writes(): void {
		global $coauthors_plus, $wpdb;

		$ga = $this->make_guest_author( 'dryrun-author', 'Dry Run Author' );
		$this->make_post_with_coauthors( 'dryrun-post', array( $ga ) );

		$this->do_export();

		// Delete the guest author so import would normally recreate it.
		$coauthors_plus->guest_authors->delete( $ga->ID, false );

		// Count guest-author CPT posts before dry-run import.
		$before_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash'",
				$coauthors_plus->guest_authors->post_type
			)
		);

		$this->do_import( true ); // dry-run = true

		$after_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash'",
				$coauthors_plus->guest_authors->post_type
			)
		);

		$this->assertEquals(
			$before_count,
			$after_count,
			'Dry-run should not create any guest author profiles in the database.'
		);
	}

	/**
	 * --skip-create does not create profiles, but does link posts where
	 * the profile already exists on the destination site.
	 */
	public function test_skip_create_links_existing_profile_without_creating(): void {
		global $coauthors_plus;

		$ga   = $this->make_guest_author( 'skipcreate-author', 'Skip Create Author' );
		$post = $this->make_post_with_coauthors( 'skipcreate-post', array( $ga ) );

		$this->do_export();

		// Remove the coauthor link so the import has something to do.
		$coauthors_plus->add_coauthors( $post->ID, array(), false );

		// The profile still exists — import with --skip-create should re-link it.
		$this->do_import( false, true );

		$logins = wp_list_pluck( get_coauthors( $post->ID ), 'user_login' );
		$this->assertContains(
			'skipcreate-author',
			$logins,
			'--skip-create should still link posts where the profile already exists.'
		);
	}

	/**
	 * Posts not found by slug increment the not-found counter but do not fatal.
	 * The import completes and other posts are still processed.
	 */
	public function test_post_not_found_does_not_fatal(): void {
		$ga = $this->make_guest_author( 'missing-post-author', 'Missing Post Author' );

		// Build a fake export referencing a non-existent slug.
		$export = array(
			'version'       => COAUTHORS_PLUS_VERSION,
			'exported_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'post_types'    => array( 'post' ),
			'guest_authors' => array(
				array(
					'profile'   => array(
						'display_name' => $ga->display_name,
						'user_login'   => $ga->user_login,
						'user_email'   => '',
						'first_name'   => '',
						'last_name'    => '',
						'website'      => '',
						'description'  => '',
						'linked_account' => '',
					),
					'post_refs' => array(
						array(
							'post_slug' => 'this-slug-does-not-exist-xyzzy',
							'post_type' => 'post',
							'position'  => 0,
						),
					),
				),
			),
		);

		file_put_contents( $this->tmp_file, wp_json_encode( $export ) );

		// Should complete without a fatal error or exception.
		$this->do_import();

		// The test passing without an exception is the primary assertion.
		$this->assertTrue( true, 'Import should complete cleanly even when posts are not found.' );
	}

	/**
	 * Malformed (non-JSON) input triggers WP_CLI::error() which throws
	 * ExitException (capture_exit is enabled in set_up).
	 */
	public function test_malformed_json_fails_cleanly(): void {
		file_put_contents( $this->tmp_file, 'this is not json {{{' );

		$this->expectException( ExitException::class );
		$this->do_import();
	}

	/**
	 * A file where guest_authors is null (not an array) triggers
	 * WP_CLI::error() → ExitException, not a PHP TypeError.
	 */
	public function test_guest_authors_null_fails_cleanly(): void {
		$export = array(
			'version'       => COAUTHORS_PLUS_VERSION,
			'exported_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'post_types'    => array( 'post' ),
			'guest_authors' => null, // intentionally invalid
		);

		file_put_contents( $this->tmp_file, wp_json_encode( $export ) );

		$this->expectException( ExitException::class );
		$this->do_import();
	}

	/**
	 * An author entry with an empty post_refs array creates the profile
	 * but does not attempt to link any posts.
	 */
	public function test_author_with_no_post_refs_creates_profile(): void {
		global $coauthors_plus;

		$export = array(
			'version'       => COAUTHORS_PLUS_VERSION,
			'exported_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'post_types'    => array( 'post' ),
			'guest_authors' => array(
				array(
					'profile'   => array(
						'display_name'   => 'No Posts Author',
						'user_login'     => 'no-posts-author',
						'user_email'     => 'noposts@example.com',
						'first_name'     => 'No',
						'last_name'      => 'Posts',
						'website'        => '',
						'description'    => '',
						'linked_account' => '',
					),
					'post_refs' => array(),
				),
			),
		);

		file_put_contents( $this->tmp_file, wp_json_encode( $export ) );

		$this->do_import();

		$created = $coauthors_plus->guest_authors->get_guest_author_by( 'user_login', 'no-posts-author' );

		$this->assertIsObject(
			$created,
			'Guest author profile should be created even when post_refs is empty.'
		);
		$this->assertSame(
			'No Posts Author',
			$created->display_name,
			'Display name should match the imported profile.'
		);
	}

	/**
	 * Importing twice does not produce duplicate coauthor entries on a post
	 * (tests the explicit add_coauthors($append=false) fix).
	 */
	public function test_add_coauthors_does_not_duplicate(): void {
		global $coauthors_plus;

		$ga   = $this->make_guest_author( 'nodupe-author', 'No Dupe Author' );
		$post = $this->make_post_with_coauthors( 'nodupe-post', array( $ga ) );

		$this->do_export();

		// Remove the link so the first import re-adds it.
		$coauthors_plus->add_coauthors( $post->ID, array(), false );

		$this->do_import();
		$this->do_import();

		$logins = wp_list_pluck( get_coauthors( $post->ID ), 'user_login' );
		$count  = count( array_filter( $logins, fn( $l ) => 'nodupe-author' === $l ) );

		$this->assertEquals(
			1,
			$count,
			'Author should appear exactly once even after two import runs (no duplicates).'
		);
	}

	/**
	 * The export --file option uses an absolute default under WP_CONTENT_DIR,
	 * not the CLI working directory.
	 */
	public function test_export_default_file_is_absolute(): void {
		$ga = $this->make_guest_author( 'default-file-author', 'Default File Author' );
		$this->make_post_with_coauthors( 'default-file-post', array( $ga ) );

		$command = new \CoAuthorsPlus_Command();
		// Export without specifying --file; the default should be under WP_CONTENT_DIR.
		$command->export_coauthors( array(), array() );

		$default_path = WP_CONTENT_DIR . '/cap-export.json';
		$this->assertFileExists(
			$default_path,
			'Default export file should be created under WP_CONTENT_DIR.'
		);

		// Cleanup.
		if ( file_exists( $default_path ) ) {
			unlink( $default_path );
		}
	}
}
