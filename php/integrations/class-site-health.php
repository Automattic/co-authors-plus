<?php
/**
 * Site Health check for posts that have no author term.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Integrations;

use Automattic\CoAuthorsPlus\Services\Missing_Author_Terms_Service;
use CoAuthors_Plus;
use Exception;
use WP_REST_Server;

/**
 * Reports posts with no author term, and the command that repairs them.
 *
 * While Co-Authors Plus is inactive WordPress carries on saving posts with a
 * post_author and no author term. When the plugin comes back those posts still
 * resolve to an author, just the wrong one, and the author counts are short, so
 * the damage is invisible. The backfill command has been there for a while; the
 * gap was that nothing told anyone to run it.
 *
 * Registered as an asynchronous test, so the count runs in a request of its own
 * and never delays the Site Health page. The count behind it is cached by the
 * service, because the query is a NOT IN over four tables and does not get
 * cheaper on a large site.
 */
class Site_Health {

	/**
	 * Key the test is registered under, and the name shown in the accordion.
	 *
	 * @var string
	 */
	public const TEST_SLUG = 'co-authors-plus_missing_author_terms';

	/**
	 * REST namespace for the test's endpoint.
	 *
	 * @var string
	 */
	public const REST_NAMESPACE = 'coauthors/v1';

	/**
	 * REST route for the test's endpoint.
	 *
	 * @var string
	 */
	public const REST_PATH = 'site-health/missing-author-terms';

	/**
	 * Plugin instance.
	 *
	 * @var CoAuthors_Plus
	 */
	private $coauthors_plus;

	/**
	 * Finds the posts that are missing terms.
	 *
	 * @var Missing_Author_Terms_Service
	 */
	private $missing_terms;

	/**
	 * Constructor.
	 *
	 * @param CoAuthors_Plus $coauthors_plus Plugin instance.
	 */
	public function __construct( CoAuthors_Plus $coauthors_plus ) {
		$this->coauthors_plus = $coauthors_plus;
		$this->missing_terms  = new Missing_Author_Terms_Service( $coauthors_plus );
	}

	/**
	 * Register the Site Health test and the endpoint it is run through.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'site_status_tests', array( $this, 'register_tests' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Add the test to Site Health's list of asynchronous tests.
	 *
	 * The test runs over REST so it cannot stall the page, and carries a direct
	 * callback so the cron path can run it too. Core registers its own tests
	 * exactly this way.
	 *
	 * @param array $tests Registered tests, keyed by direct and async.
	 *
	 * @return array
	 */
	public function register_tests( array $tests ): array {
		$tests['async'][ self::TEST_SLUG ] = array(
			'label'             => __( 'Missing author terms', 'co-authors-plus' ),
			'test'              => rest_url( self::REST_NAMESPACE . '/' . self::REST_PATH ),
			'has_rest'          => true,
			'async_direct_test' => array( $this, 'get_missing_author_terms_test' ),
		);

		return $tests;
	}

	/**
	 * Register the REST route the test is fetched from.
	 *
	 * @return void
	 */
	public function register_rest_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_PATH,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_missing_author_terms_test' ),
				// The same capability core requires for its own Site Health
				// endpoints, so this reaches the people who can act on it and
				// nobody else.
				'permission_callback' => static function (): bool {
					return current_user_can( 'view_site_health_checks' );
				},
			)
		);
	}

	/**
	 * Count the posts with no author term and describe the result.
	 *
	 * @return array The Site Health test result.
	 */
	public function get_missing_author_terms_test(): array {
		$post_types = $this->coauthors_plus->supported_post_types();

		try {
			$cached = $this->missing_terms->get_cached_counts( $post_types );
		} catch ( Exception $e ) {
			// Core does not catch an exception thrown by a test callback, so
			// letting this through would take down the Site Health page (on the
			// cron path) or the request behind it. Say the count could not be
			// taken and let the next page load try again.
			return $this->get_unavailable_result();
		}

		if ( 0 === $cached['total'] ) {
			return $this->get_good_result( $cached['timestamp'] );
		}

		return $this->get_recommended_result( $cached['counts'], $cached['total'], $cached['timestamp'] );
	}

	/**
	 * The result for a site whose terms could not be counted.
	 *
	 * @return array The Site Health test result.
	 */
	private function get_unavailable_result(): array {
		return array(
			'badge'       => $this->get_badge(),
			'test'        => self::TEST_SLUG,
			'status'      => 'recommended',
			'label'       => __( 'Could not check for posts missing an author term', 'co-authors-plus' ),
			'description' => wp_kses_post(
				sprintf(
					'<p>%s</p>',
					__( 'The query that counts posts without an author term failed, so this check could not run. It will try again the next time Site Health loads. If it keeps failing, the database may need attention.', 'co-authors-plus' )
				)
			),
		);
	}

	/**
	 * The result for a site whose posts all have author terms.
	 *
	 * @param int $timestamp When the count was taken.
	 *
	 * @return array The Site Health test result.
	 */
	private function get_good_result( int $timestamp ): array {
		$description = sprintf(
			'<p>%s</p>',
			__( 'Every published post on this site has an author term, so bylines and author counts are correct.', 'co-authors-plus' )
		);

		$description .= $this->get_counted_at_paragraph( $timestamp );

		return array(
			'badge'       => $this->get_badge(),
			'test'        => self::TEST_SLUG,
			'status'      => 'good',
			'label'       => __( 'Every published post has an author term', 'co-authors-plus' ),
			'description' => wp_kses_post( $description ),
		);
	}

	/**
	 * The result for a site with posts that have no author term.
	 *
	 * @param array<string, int> $counts    Counts keyed by post type.
	 * @param int                $total     Count across every post type.
	 * @param int                $timestamp When the count was taken.
	 *
	 * @return array The Site Health test result.
	 */
	private function get_recommended_result( array $counts, int $total, int $timestamp ): array {
		// Only the types that actually have posts to repair, so the command does
		// as little work as the site needs.
		$affected_post_types = array_keys( array_filter( $counts ) );
		$command             = $this->get_repair_command( $affected_post_types );

		$description = sprintf(
			'<p>%s</p>',
			__( 'These posts have no author term. Co-Authors Plus falls back to the post author, so their bylines and the author counts are wrong. It usually means the plugin was inactive for a while: WordPress kept saving posts, but nothing assigned the term.', 'co-authors-plus' )
		);

		$description .= sprintf(
			'<p><strong>%s</strong></p>',
			sprintf(
				/* translators: %s: Number of posts. */
				_n(
					'%s post needs an author term.',
					'%s posts need an author term.',
					$total,
					'co-authors-plus'
				),
				number_format_i18n( $total )
			)
		);

		$description .= sprintf(
			'<p>%s</p>',
			__( 'Run this WP-CLI command to repair them:', 'co-authors-plus' )
		);

		$description .= sprintf( '<p><code>%s</code></p>', esc_html( $command ) );

		// Without the flag the command only touches posts, so say why it is there
		// when another post type is involved.
		if ( array( 'post' ) !== $affected_post_types ) {
			$description .= sprintf(
				'<p>%s</p>',
				__( 'The command only covers posts by default, so the command above passes --post-types to include the other post types that need repairing.', 'co-authors-plus' )
			);
		}

		$description .= $this->get_counted_at_paragraph( $timestamp );

		return array(
			'badge'       => $this->get_badge(),
			'test'        => self::TEST_SLUG,
			'status'      => 'recommended',
			'label'       => sprintf(
				/* translators: %s: Number of posts. */
				_n(
					'%s post is missing an author term',
					'%s posts are missing author terms',
					$total,
					'co-authors-plus'
				),
				number_format_i18n( $total )
			),
			'description' => wp_kses_post( $description ),
		);
	}

	/**
	 * The badge shown beside the test.
	 *
	 * @return array
	 */
	private function get_badge(): array {
		return array(
			'label' => __( 'Authors', 'co-authors-plus' ),
			'color' => 'blue',
		);
	}

	/**
	 * A sentence saying when the count was taken.
	 *
	 * The count is cached for an hour, so saying how old it is matters: a figure
	 * taken this morning reads very differently from one taken just now.
	 *
	 * @param int $timestamp When the count was taken.
	 *
	 * @return string
	 */
	private function get_counted_at_paragraph( int $timestamp ): string {
		return sprintf(
			'<p>%s</p>',
			sprintf(
				/* translators: %s: Human-readable time difference, e.g. "5 mins". */
				__( 'Counted %s ago.', 'co-authors-plus' ),
				esc_html( human_time_diff( $timestamp ) )
			)
		);
	}

	/**
	 * Build the backfill command for the post types that need it.
	 *
	 * @param string[] $affected_post_types Post types with posts to repair.
	 *
	 * @return string
	 */
	private function get_repair_command( array $affected_post_types ): string {
		$command = 'wp co-authors-plus create-author-terms-for-posts';

		// The command already defaults to post, so the flag is only worth its
		// length when some other post type is involved.
		if ( array( 'post' ) !== $affected_post_types ) {
			$command .= ' --post-types=' . implode( ',', $affected_post_types );
		}

		return $command;
	}
}
