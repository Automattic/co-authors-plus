<?php
/**
 * Read-only checks over the co-author term index and the data it mirrors.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Services;

use Automattic\CoAuthorsPlus\CLI\Create_Author_Terms_For_Posts_Command;
use CoAuthors\Prefix;
use CoAuthors_Guest_Authors;
use CoAuthors_Plus;

/**
 * Reports where the term index and the bylines agree, and where they do not.
 *
 * Every fixer command in the plugin repairs one kind of drift between the
 * mirrored `author` taxonomy and the posts, users and guest author profiles it
 * is meant to reflect. Each of those commands already knows how to detect the
 * condition it repairs, but that detection is private to the command and only
 * runs on the way to a write. This service collects the same detection into one
 * read-only pass, so an operator can see what is wrong before deciding whether
 * to run a fixer.
 *
 * The checks deliberately compose the plugin's own read paths (get_author_term,
 * get_author_term_post_count, Prefix) rather than restating their rules, so a
 * check cannot disagree with the repair it describes.
 */
class Coauthor_Checks_Service {

	/**
	 * Number of terms to read per page while enumerating.
	 *
	 * @var int
	 */
	private const TERM_BATCH_SIZE = 500;

	/**
	 * Number of guest authors to read per page while enumerating.
	 *
	 * @var int
	 */
	private const GUEST_AUTHOR_BATCH_SIZE = 100;

	/**
	 * The canonical list of checks, in the order they are reported.
	 *
	 * @var string[]
	 */
	const CHECKS = array(
		'posts-missing-terms',
		'missing-user-terms',
		'missing-guest-author-terms',
		'stale-term-counts',
		'stale-term-descriptions',
		'unprefixed-terms',
		'revision-terms',
		'orphaned-skip-markers',
		'guest-author-drift',
	);

	/**
	 * Plugin instance the checks read through.
	 *
	 * @var CoAuthors_Plus
	 */
	private $coauthors_plus;

	/**
	 * Constructor.
	 *
	 * @param CoAuthors_Plus $coauthors_plus Plugin instance.
	 */
	public function __construct( CoAuthors_Plus $coauthors_plus ) {
		$this->coauthors_plus = $coauthors_plus;
	}

	/**
	 * Run every check, or the named subset.
	 *
	 * Names not in CHECKS are ignored, so callers can validate their own input.
	 *
	 * @param string[] $only Check names to run. Empty runs every check.
	 * @return array<int, array<string, mixed>> One row per check.
	 */
	public function run( array $only = array() ): array {
		$names = empty( $only ) ? self::CHECKS : array_values( array_intersect( self::CHECKS, $only ) );
		$rows  = array();

		foreach ( $names as $name ) {
			$method = 'check_' . str_replace( '-', '_', $name );
			$row    = $this->$method();

			$rows[] = array(
				'check'   => $name,
				'status'  => $row['status'],
				'count'   => $row['count'],
				'summary' => $row['summary'],
			);
		}

		return $rows;
	}

	/**
	 * Posts with no co-author terms at all.
	 *
	 * Trash, auto-drafts and revisions are left out: the first two are not
	 * binned content, and a revision is covered by its own check below. Posts
	 * carrying a backfill skip marker are still counted, because they really do
	 * have no terms; the marker only means a fixer has already passed them by.
	 */
	private function check_posts_missing_terms(): array {
		global $wpdb;

		$post_types = $this->coauthors_plus->supported_post_types();

		if ( empty( $post_types ) ) {
			return $this->result( 0, __( 'No post types support co-authors.', 'co-authors-plus' ) );
		}

		$placeholder = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$args        = array_merge( $post_types, array( $this->coauthors_plus->coauthor_taxonomy ) );

		// phpcs:disable -- Query is properly prepared.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				WHERE p.post_type IN ( $placeholder )
				  AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )
				  AND NOT EXISTS (
				      SELECT 1
				      FROM {$wpdb->term_relationships} tr
				      INNER JOIN {$wpdb->term_taxonomy} tt
				          ON tr.term_taxonomy_id = tt.term_taxonomy_id
				      WHERE tr.object_id = p.ID
				        AND tt.taxonomy = %s
				  )",
				$args
			)
		);
		// phpcs:enable

		return $this->result(
			$count,
			sprintf(
				/* translators: %s: Number of posts. */
				_n(
					'%s post has no co-author terms.',
					'%s posts have no co-author terms.',
					$count,
					'co-authors-plus'
				),
				number_format_i18n( $count )
			)
		);
	}

	/**
	 * WordPress users with no co-author term.
	 *
	 * A single query rather than a call per user, because a site can have tens
	 * of thousands. The two slug arms match get_author_term(), which looks for
	 * the prefixed slug and then falls back to the bare nicename, so a user
	 * whose term is unprefixed is not reported as missing.
	 */
	private function check_missing_user_terms(): array {
		global $wpdb;

		// phpcs:disable -- Query is properly prepared.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(u.ID)
				FROM {$wpdb->users} u
				WHERE NOT EXISTS (
				    SELECT 1
				    FROM {$wpdb->terms} t
				    INNER JOIN {$wpdb->term_taxonomy} tt
				        ON t.term_id = tt.term_id
				    WHERE tt.taxonomy = %s
				      AND (
				          t.slug = CONCAT( %s, u.user_nicename )
				          OR t.slug = u.user_nicename
				      )
				)",
				$this->coauthors_plus->coauthor_taxonomy,
				Prefix::VALUE
			)
		);
		// phpcs:enable

		return $this->result(
			$count,
			sprintf(
				/* translators: %s: Number of users. */
				_n(
					'%s user has no author term.',
					'%s users have no author term.',
					$count,
					'co-authors-plus'
				),
				number_format_i18n( $count )
			)
		);
	}

	/**
	 * Guest authors with no co-author term.
	 *
	 * Enumerated and resolved through get_author_term() rather than a query,
	 * because a guest author's nicename is derived from their login field
	 * rather than stored where SQL can reach it. The guest author list is a
	 * contributor list, so this stays bounded.
	 */
	private function check_missing_guest_author_terms(): array {
		if ( ! $this->guest_authors_enabled() ) {
			return $this->skipped( __( 'Guest authors are disabled.', 'co-authors-plus' ) );
		}

		$count = 0;

		foreach ( $this->guest_author_ids() as $guest_author_id ) {
			$guest_author = $this->coauthors_plus->guest_authors->get_guest_author_by( 'ID', $guest_author_id );

			if ( ! $guest_author ) {
				continue;
			}

			if ( ! $this->coauthors_plus->get_author_term( $guest_author ) ) {
				++$count;
			}
		}

		return $this->result(
			$count,
			sprintf(
				/* translators: %s: Number of guest authors. */
				_n(
					'%s guest author has no author term.',
					'%s guest authors have no author term.',
					$count,
					'co-authors-plus'
				),
				number_format_i18n( $count )
			)
		);
	}

	/**
	 * Author terms whose stored post count is out of date.
	 *
	 * The stored count is on the term, the expected one is computed by the same
	 * method the fixer writes from, so the two cannot drift apart.
	 */
	private function check_stale_term_counts(): array {
		$count = 0;

		foreach ( $this->author_terms() as $term ) {
			$expected = $this->coauthors_plus->get_author_term_post_count( $term );

			// A null here means the count could not be determined, either
			// because no co-author backs the term or because the query failed.
			// Neither is a stale count, so neither is reported by this check.
			if ( null === $expected ) {
				continue;
			}

			if ( (int) $term->count !== $expected ) {
				++$count;
			}
		}

		return $this->result(
			$count,
			sprintf(
				/* translators: %s: Number of author terms. */
				_n(
					'%s author term has an out of date post count.',
					'%s author terms have an out of date post count.',
					$count,
					'co-authors-plus'
				),
				number_format_i18n( $count )
			)
		);
	}

	/**
	 * Author terms whose stored description is out of date.
	 *
	 * The description holds the co-author's searchable fields, so a profile edit
	 * that did not refresh the term shows up here.
	 */
	private function check_stale_term_descriptions(): array {
		$count = 0;

		foreach ( $this->author_terms() as $term ) {
			$coauthor = $this->coauthors_plus->get_coauthor_by( 'user_nicename', $term->slug );

			if ( ! $coauthor ) {
				continue;
			}

			if ( (string) $term->description !== $this->description_for( $coauthor ) ) {
				++$count;
			}
		}

		return $this->result(
			$count,
			sprintf(
				/* translators: %s: Number of author terms. */
				_n(
					'%s author term has an out of date description.',
					'%s author terms have an out of date description.',
					$count,
					'co-authors-plus'
				),
				number_format_i18n( $count )
			)
		);
	}

	/**
	 * Author terms whose slug is missing the plugin's prefix.
	 *
	 * These are the pre-3.0 terms migrate-author-terms repairs. An unprefixed
	 * term still resolves through get_author_term()'s fallback, so it does not
	 * announce itself, it just quietly makes an author archive wrong.
	 */
	private function check_unprefixed_terms(): array {
		global $wpdb;

		// phpcs:disable -- Query is properly prepared.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(t.term_id)
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt
				    ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s
				  AND t.slug NOT LIKE %s",
				$this->coauthors_plus->coauthor_taxonomy,
				$wpdb->esc_like( Prefix::VALUE ) . '%'
			)
		);
		// phpcs:enable

		return $this->result(
			$count,
			sprintf(
				/* translators: 1: Number of author terms. 2: The term slug prefix. */
				_n(
					'%1$s author term has a slug without the %2$s prefix.',
					'%1$s author terms have a slug without the %2$s prefix.',
					$count,
					'co-authors-plus'
				),
				number_format_i18n( $count ),
				Prefix::VALUE
			)
		);
	}

	/**
	 * Revisions carrying co-author terms.
	 *
	 * An old bug gave revisions author terms. remove-terms-from-revisions
	 * repairs it, and any site that never ran that cleanup still carries them.
	 */
	private function check_revision_terms(): array {
		global $wpdb;

		// phpcs:disable -- Query is properly prepared.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr
				    ON p.ID = tr.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt
				    ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE p.post_type = 'revision'
				  AND p.post_status = 'inherit'
				  AND tt.taxonomy = %s",
				$this->coauthors_plus->coauthor_taxonomy
			)
		);
		// phpcs:enable

		return $this->result(
			$count,
			sprintf(
				/* translators: %s: Number of revisions. */
				_n(
					'%s revision carries co-author terms.',
					'%s revisions carry co-author terms.',
					$count,
					'co-authors-plus'
				),
				number_format_i18n( $count )
			)
		);
	}

	/**
	 * Backfill skip markers that are no longer doing anything.
	 *
	 * The marker tells the backfill not to retry a post it could not handle. It
	 * is orphaned when the post is gone, or when the post later gained its
	 * terms anyway, which leaves the marker blocking nothing.
	 */
	private function check_orphaned_skip_markers(): array {
		global $wpdb;

		// phpcs:disable -- Query is properly prepared.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(pm.meta_id)
				FROM {$wpdb->postmeta} pm
				LEFT JOIN {$wpdb->posts} p
				    ON pm.post_id = p.ID
				WHERE pm.meta_key = %s
				  AND (
				      p.ID IS NULL
				      OR EXISTS (
				          SELECT 1
				          FROM {$wpdb->term_relationships} tr
				          INNER JOIN {$wpdb->term_taxonomy} tt
				              ON tr.term_taxonomy_id = tt.term_taxonomy_id
				          WHERE tr.object_id = pm.post_id
				            AND tt.taxonomy = %s
				      )
				  )",
				Create_Author_Terms_For_Posts_Command::SKIP_POST_FOR_BACKFILL_META_KEY,
				$this->coauthors_plus->coauthor_taxonomy
			)
		);
		// phpcs:enable

		return $this->result(
			$count,
			sprintf(
				/* translators: %s: Number of postmeta rows. */
				_n(
					'%s backfill skip marker is orphaned.',
					'%s backfill skip markers are orphaned.',
					$count,
					'co-authors-plus'
				),
				number_format_i18n( $count )
			)
		);
	}

	/**
	 * Guest author profiles that have drifted from their author term.
	 *
	 * Guest author terms can be renamed without their profile, and profiles can
	 * be renamed without their term, leaving the two pointing at different
	 * slugs while get_author_term()'s fallback quietly papers over it. This
	 * reads the term holding the profile's own post, which is the assignment
	 * the site actually queries, and compares its slug to the one the profile's
	 * nicename implies. A profile carrying no term at all is left to
	 * missing-guest-author-terms.
	 */
	private function check_guest_author_drift(): array {
		if ( ! $this->guest_authors_enabled() ) {
			return $this->skipped( __( 'Guest authors are disabled.', 'co-authors-plus' ) );
		}

		$count = 0;

		foreach ( $this->guest_author_ids() as $guest_author_id ) {
			$guest_author = $this->coauthors_plus->guest_authors->get_guest_author_by( 'ID', $guest_author_id );

			if ( ! $guest_author ) {
				continue;
			}

			$terms = wp_get_object_terms( $guest_author_id, $this->coauthors_plus->coauthor_taxonomy );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( Prefix::prefix_slug( $guest_author->user_nicename ) !== $term->slug ) {
					++$count;
					break;
				}
			}
		}

		return $this->result(
			$count,
			sprintf(
				/* translators: %s: Number of guest author profiles. */
				_n(
					'%s guest author profile has drifted from its author term.',
					'%s guest author profiles have drifted from their author term.',
					$count,
					'co-authors-plus'
				),
				number_format_i18n( $count )
			)
		);
	}

	/**
	 * Every author term, read in pages so a large term index does not have to
	 * be held in memory at once.
	 *
	 * @return array<int, \WP_Term>
	 */
	private function author_terms(): array {
		$terms  = array();
		$offset = 0;

		do {
			$batch = get_terms(
				array(
					'taxonomy'   => $this->coauthors_plus->coauthor_taxonomy,
					'hide_empty' => false,
					'number'     => self::TERM_BATCH_SIZE,
					'offset'     => $offset,
					'orderby'    => 'term_id',
					'order'      => 'ASC',
				)
			);

			if ( is_wp_error( $batch ) ) {
				break;
			}

			$terms  = array_merge( $terms, $batch );
			$found  = count( $batch );
			$offset += self::TERM_BATCH_SIZE;

			\WP_CLI\Utils\wp_clear_object_cache();
		} while ( self::TERM_BATCH_SIZE === $found );

		return $terms;
	}

	/**
	 * Every guest author ID, read in pages.
	 *
	 * @return int[]
	 */
	private function guest_author_ids(): array {
		$ids   = array();
		$paged = 1;

		do {
			$batch = get_posts(
				array(
					'post_type'        => $this->coauthors_plus->guest_authors->post_type,
					// Guest authors are stored as drafts, so 'any' is what finds them.
					'post_status'      => 'any',
					'posts_per_page'   => self::GUEST_AUTHOR_BATCH_SIZE,
					'paged'            => $paged,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'fields'           => 'ids',
					'suppress_filters' => false,
				)
			);

			$found = count( $batch );
			$ids   = array_merge( $ids, $batch );
			++$paged;

			\WP_CLI\Utils\wp_clear_object_cache();
		} while ( self::GUEST_AUTHOR_BATCH_SIZE === $found );

		return array_map( 'intval', $ids );
	}

	/**
	 * Whether guest author profiles are available to check.
	 *
	 * Read lazily rather than in the constructor, so the command can be built
	 * and registered whether or not the feature is switched on.
	 */
	private function guest_authors_enabled(): bool {
		return $this->coauthors_plus->guest_authors instanceof CoAuthors_Guest_Authors
			&& $this->coauthors_plus->is_guest_authors_enabled();
	}

	/**
	 * The description a co-author's term should hold.
	 *
	 * Mirrors CoAuthors_Plus::update_author_term(), including its use of
	 * ajax_search_fields, so the comparison cannot disagree with the write.
	 *
	 * @param object $coauthor Co-author object.
	 * @return string
	 */
	private function description_for( $coauthor ): string {
		$values = array();

		foreach ( $this->coauthors_plus->ajax_search_fields as $field ) {
			$values[] = $coauthor->$field ?? '';
		}

		return implode( ' ', $values );
	}

	/**
	 * Build a check row from its finding count.
	 *
	 * @param int    $count   Number found.
	 * @param string $summary Human readable summary.
	 * @return array<string, mixed>
	 */
	private function result( int $count, string $summary ): array {
		return array(
			'status'  => $count > 0 ? 'issues' : 'ok',
			'count'   => $count,
			'summary' => $summary,
		);
	}

	/**
	 * Build a check row for a check that does not apply.
	 *
	 * @param string $summary Why it was skipped.
	 * @return array<string, mixed>
	 */
	private function skipped( string $summary ): array {
		return array(
			'status'  => 'skipped',
			'count'   => 0,
			'summary' => $summary,
		);
	}
}
