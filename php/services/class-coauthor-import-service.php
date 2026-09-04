<?php
/**
 * Restores guest authors and their post assignments from an export.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Services;

use InvalidArgumentException;

/**
 * Restores guest authors and their post assignments from an export.
 *
 * Reports problems by returning counts and warnings, and refuses malformed
 * input by throwing. Nothing here knows about WP-CLI, so the whole of it can
 * be exercised without a WordPress install: the caller decides how a warning
 * or a failure is presented.
 */
class Coauthor_Import_Service {

	/**
	 * Guest author profile reader and writer.
	 *
	 * @var Guest_Author_Service
	 */
	private $guest_authors;

	/**
	 * Byline reader and writer.
	 *
	 * @var Coauthor_Assignment_Service
	 */
	private $assignments;

	/**
	 * Constructor.
	 *
	 * @param Guest_Author_Service        $guest_authors Guest author profiles.
	 * @param Coauthor_Assignment_Service $assignments   Byline writer.
	 */
	public function __construct(
		Guest_Author_Service $guest_authors,
		Coauthor_Assignment_Service $assignments
	) {
		$this->guest_authors = $guest_authors;
		$this->assignments   = $assignments;
	}

	/**
	 * Restore an export.
	 *
	 * @param array<string, mixed> $data        Decoded export.
	 * @param bool                 $dry_run     Report what would happen, write nothing.
	 * @param bool                 $skip_create Only re-link posts; do not create profiles.
	 * @return array<string, mixed> Counts, plus any warnings for the caller to report.
	 * @throws InvalidArgumentException When the export is not the expected shape.
	 */
	public function import( array $data, bool $dry_run = false, bool $skip_create = false ): array {
		if ( ! isset( $data['guest_authors'] ) || ! is_array( $data['guest_authors'] ) ) {
			throw new InvalidArgumentException(
				'Export is missing a "guest_authors" list. Was the file produced by export-coauthors?'
			);
		}

		$summary = array(
			'authors_created' => 0,
			'authors_skipped' => 0,
			'posts_linked'    => 0,
			'posts_not_found' => 0,
			'warnings'        => array(),
		);

		foreach ( $data['guest_authors'] as $entry ) {
			$this->import_entry( (array) $entry, $dry_run, $skip_create, $summary );
		}

		return $summary;
	}

	/**
	 * Restore one guest author and its assignments.
	 *
	 * @param array<string, mixed> $entry       One guest_authors element.
	 * @param bool                 $dry_run     Whether to write anything.
	 * @param bool                 $skip_create Whether to create missing profiles.
	 * @param array<string, mixed> $summary     Running summary, by reference.
	 * @return void
	 */
	private function import_entry( array $entry, bool $dry_run, bool $skip_create, array &$summary ): void {
		$profile    = isset( $entry['profile'] ) ? (array) $entry['profile'] : array();
		$user_login = (string) ( $profile['user_login'] ?? '' );

		if ( '' === $user_login ) {
			$summary['warnings'][] = 'Skipped an entry with no user_login.';
			return;
		}

		$guest_author = $this->guest_authors->find_by( 'user_login', $user_login );

		if ( $guest_author ) {
			++$summary['authors_skipped'];
		} elseif ( $skip_create ) {
			// Deliberately not counted as skipped: that count is reported as
			// profiles that already existed, and this one did not. The missing
			// profile is reported against each post it cannot be linked to.
			$summary['warnings'][] = sprintf( 'No profile for "%s" and --skip-create was given.', $user_login );
		} elseif ( $dry_run ) {
			++$summary['authors_created'];
		} else {
			$guest_author = $this->create( $profile, $user_login, $summary );

			if ( ! $guest_author ) {
				return;
			}

			++$summary['authors_created'];
		}

		$post_refs = isset( $entry['post_refs'] ) ? (array) $entry['post_refs'] : array();

		foreach ( $post_refs as $ref ) {
			$this->link( (array) $ref, $guest_author, $dry_run, $summary );
		}
	}

	/**
	 * Create a missing guest author profile.
	 *
	 * @param array<string, mixed> $profile    Profile fields.
	 * @param string               $user_login Login being created.
	 * @param array<string, mixed> $summary    Running summary, by reference.
	 * @return object|false The created guest author, or false on failure.
	 */
	private function create( array $profile, string $user_login, array &$summary ) {
		$created = $this->guest_authors->create( $profile );

		if ( is_wp_error( $created ) ) {
			$summary['warnings'][] = sprintf(
				'Could not create "%s": %s',
				$user_login,
				$created->get_error_message()
			);

			return false;
		}

		// Re-read rather than assume: the byline is keyed on user_nicename,
		// which create() derives from the login and may not match it.
		return $this->guest_authors->find_by( 'ID', (string) $created );
	}

	/**
	 * Re-link one post to a guest author.
	 *
	 * @param array<string, mixed> $ref          One post_refs element.
	 * @param object|false         $guest_author Resolved guest author, if any.
	 * @param bool                 $dry_run      Whether to write anything.
	 * @param array<string, mixed> $summary      Running summary, by reference.
	 * @return void
	 */
	private function link( array $ref, $guest_author, bool $dry_run, array &$summary ): void {
		$slug = (string) ( $ref['post_slug'] ?? '' );

		if ( '' === $slug ) {
			$summary['warnings'][] = 'Skipped a post reference with no slug.';
			return;
		}

		$post_type = (string) ( $ref['post_type'] ?? 'post' );
		$post_id   = $this->find_post( $slug, $post_type );

		if ( ! $post_id ) {
			++$summary['posts_not_found'];
			$summary['warnings'][] = sprintf( 'No %s found with slug "%s".', $post_type, $slug );
			return;
		}

		if ( $dry_run ) {
			++$summary['posts_linked'];
			return;
		}

		if ( ! $guest_author ) {
			$summary['warnings'][] = sprintf(
				'No profile to link to "%s"; run without --skip-create to create it.',
				$slug
			);
			return;
		}

		$changed = $this->assignments->add_at_position(
			$post_id,
			(string) $guest_author->user_nicename,
			(int) ( $ref['position'] ?? 0 )
		);

		if ( $changed ) {
			++$summary['posts_linked'];
		}
	}

	/**
	 * Resolve a post by slug and post type.
	 *
	 * @param string $slug      Post slug.
	 * @param string $post_type Post type.
	 * @return int|null Post ID, or null when absent.
	 */
	private function find_post( string $slug, string $post_type ): ?int {
		$posts = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => $post_type,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		if ( empty( $posts ) ) {
			return null;
		}

		return (int) $posts[0];
	}
}
