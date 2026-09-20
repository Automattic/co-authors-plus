<?php
/**
 * Unit tests for the missing author terms service.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Services;

use Automattic\CoAuthorsPlus\Services\Missing_Author_Terms_Service;
use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @covers \Automattic\CoAuthorsPlus\Services\Missing_Author_Terms_Service
 */
final class MissingAuthorTermsServiceTest extends TestCase {

	/**
	 * The last SQL string handed to $wpdb->prepare().
	 *
	 * @var string
	 */
	private $prepared_sql = '';

	/**
	 * The last argument list handed to $wpdb->prepare().
	 *
	 * @var array
	 */
	private $prepared_args = array();

	/**
	 * Drop the mocked global between tests.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tear_down();
	}

	/**
	 * Build a service over a mocked $wpdb.
	 *
	 * @param mixed $return What the mocked query method should return.
	 * @return Missing_Author_Terms_Service
	 */
	private function service( $return = array() ): Missing_Author_Terms_Service {
		$self = $this;

		$wpdb              = Mockery::mock( 'wpdb' );
		$wpdb->posts       = 'wp_posts';
		$wpdb->postmeta    = 'wp_postmeta';
		$wpdb->term_taxonomy     = 'wp_term_taxonomy';
		$wpdb->term_relationships = 'wp_term_relationships';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) use ( $self ) {
				$self->record_prepared( $sql, $args );

				return $sql;
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturn( $return );
		$wpdb->shouldReceive( 'get_results' )->andReturn( $return );

		$GLOBALS['wpdb'] = $wpdb;

		$coauthors_plus                    = Mockery::mock( 'CoAuthors_Plus' );
		$coauthors_plus->coauthor_taxonomy = 'author';

		return new Missing_Author_Terms_Service( $coauthors_plus );
	}

	/**
	 * Record the query the service prepared, for assertions.
	 *
	 * @param string $sql  The SQL string.
	 * @param array  $args The arguments to it.
	 * @return void
	 */
	public function record_prepared( string $sql, array $args ): void {
		$this->prepared_sql  = $sql;
		$this->prepared_args = $args;
	}

	/**
	 * The service must ask about the plugin's own taxonomy, so a caller cannot
	 * count the wrong one by passing the wrong argument.
	 */
	public function test_the_query_filters_on_the_plugin_taxonomy(): void {
		$service = $this->service( 3 );

		$service->count( array( 'post' ) );

		$this->assertStringContainsString( 'tt.taxonomy = %s', $this->prepared_sql );
		$this->assertContains( 'author', $this->flatten( $this->prepared_args ) );
	}

	/**
	 * Posts the backfill has already given up on must stay out of the count, or
	 * Site Health reports a problem that running the command cannot fix.
	 */
	public function test_the_query_excludes_posts_marked_as_skipped(): void {
		$service = $this->service( 0 );

		$service->count( array( 'post' ) );

		$this->assertStringContainsString( 'meta_key = %s', $this->prepared_sql );
		$this->assertContains(
			Missing_Author_Terms_Service::SKIP_POST_FOR_BACKFILL_META_KEY,
			$this->flatten( $this->prepared_args )
		);
	}

	/**
	 * The detection rests on both NOT IN subqueries. Losing either one silently
	 * widens the result set to every post of the type.
	 */
	public function test_the_query_keeps_both_exclusion_subqueries(): void {
		$service = $this->service( 0 );

		$service->count( array( 'post' ) );

		$this->assertSame(
			2,
			substr_count( $this->prepared_sql, 'NOT IN' ),
			'The query must exclude both posts with terms and posts marked as skipped.'
		);
	}

	/**
	 * Every value that reaches the SQL must travel as a placeholder, so nothing
	 * a caller passes can be read as SQL.
	 */
	public function test_the_values_are_passed_as_placeholders(): void {
		$service = $this->service( 0 );

		$service->count( array( 'post', 'page' ), array( 'publish', 'draft' ) );

		$this->assertStringContainsString( 'post_type IN ( %s,%s )', $this->prepared_sql );
		$this->assertStringContainsString( 'post_status IN ( %s,%s )', $this->prepared_sql );
		$this->assertStringNotContainsString( "'post'", $this->prepared_sql );
	}

	public function test_count_returns_an_integer(): void {
		$service = $this->service( '27' );

		$this->assertSame( 27, $service->count( array( 'post' ) ) );
	}

	/**
	 * A failed query returns null. Folding that to 0 would tell Site Health the
	 * site is clean when nothing was actually counted.
	 */
	public function test_count_throws_when_the_query_fails(): void {
		$service = $this->service( null );

		$this->expectException( \Exception::class );

		$service->count( array( 'post' ) );
	}

	/**
	 * The count query must aggregate rather than fetch every matching row.
	 */
	public function test_count_selects_a_count(): void {
		$service = $this->service( 0 );

		$service->count( array( 'post' ) );

		$this->assertStringStartsWith( 'SELECT COUNT(*) FROM', $this->prepared_sql );
	}

	/**
	 * A page range must reach the query as a bound, not be dropped, and the
	 * driver has to refuse the inverted range rather than run a query that
	 * quietly matches nothing.
	 */
	public function test_an_inverted_id_range_is_rejected(): void {
		$service = $this->service( 0 );

		$this->expectException( \Exception::class );

		$service->count( array( 'post' ), array( 'publish' ), array(), 100, 50 );
	}

	public function test_posts_appends_a_limit_only_when_batched(): void {
		$service = $this->service( array() );

		$service->posts( array( 'post' ), array( 'publish' ), false, 250 );
		$this->assertStringNotContainsString( 'LIMIT', $this->prepared_sql );

		$service->posts( array( 'post' ), array( 'publish' ), true, 25 );
		$this->assertStringContainsString( 'LIMIT 25', $this->prepared_sql );
	}

	/**
	 * The breakdown is what lets the message name the post types that need
	 * repairing, so a type with nothing to fix must still appear as zero rather
	 * than be missing.
	 */
	public function test_counts_by_post_type_returns_an_entry_per_type(): void {
		$service = $this->service( 0 );

		$counts = $service->counts_by_post_type( array( 'post', 'page' ) );

		$this->assertSame( array( 'post', 'page' ), array_keys( $counts ) );
	}

	public function test_cached_counts_caches_the_result(): void {
		$service = $this->service( 0 );

		$stored = null;
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $expiration ) use ( &$stored ) {
				$stored = $value;

				return true;
			}
		);

		$cached = $service->get_cached_counts( array( 'post', 'page' ) );

		$this->assertNotNull( $stored, 'A cache miss must fill the cache.' );
		$this->assertSame( $cached, $stored );
		$this->assertSame( 0, $cached['total'] );
		$this->assertSame( array( 'post', 'page' ), array_keys( $cached['counts'] ) );
	}

	/**
	 * A post type registered after the cache was filled would otherwise be
	 * reported clean for the rest of the hour without ever being counted.
	 */
	public function test_cached_counts_ignores_an_entry_taken_over_fewer_post_types(): void {
		$service = $this->service( 0 );

		$written = 0;
		Functions\when( 'get_transient' )->justReturn(
			array(
				'counts'    => array( 'post' => 0 ),
				'total'     => 0,
				'timestamp' => time(),
			)
		);
		Functions\when( 'set_transient' )->alias(
			static function () use ( &$written ) {
				++$written;

				return true;
			}
		);

		$cached = $service->get_cached_counts( array( 'post', 'page' ) );

		$this->assertSame( 1, $written, 'The stale entry must be replaced with a fresh count.' );
		$this->assertSame( array( 'post', 'page' ), array_keys( $cached['counts'] ) );
	}

	/**
	 * A warm entry covering exactly the requested types is reused, because the
	 * query it stands in for is the expensive part of this class.
	 */
	public function test_cached_counts_reuses_a_complete_entry(): void {
		$service = $this->service( 0 );

		$entry = array(
			'counts'    => array(
				'post' => 4,
				'page' => 0,
			),
			'total'     => 4,
			'timestamp' => time(),
		);

		Functions\when( 'get_transient' )->justReturn( $entry );
		Functions\expect( 'set_transient' )->never();

		$this->assertSame( $entry, $service->get_cached_counts( array( 'post', 'page' ) ) );
	}

	/**
	 * An entry taken over more post types than were asked for must not be reused.
	 * Its total counts posts the caller never asked about, so the caller would
	 * report a repair for a post type it does not support.
	 */
	public function test_cached_counts_ignores_an_entry_covering_more_post_types(): void {
		$service = $this->service( 0 );

		$written = 0;
		Functions\when( 'get_transient' )->justReturn(
			array(
				'counts'    => array(
					'post' => 0,
					'page' => 9,
				),
				'total'     => 9,
				'timestamp' => time(),
			)
		);
		Functions\when( 'set_transient' )->alias(
			static function () use ( &$written ) {
				++$written;

				return true;
			}
		);

		$cached = $service->get_cached_counts( array( 'post' ) );

		$this->assertSame( 1, $written, 'An entry covering extra post types must be recounted.' );
		$this->assertSame( array( 'post' ), array_keys( $cached['counts'] ) );
		$this->assertSame( 0, $cached['total'] );
	}

	public function test_clear_count_cache_deletes_the_transient(): void {
		$service = $this->service();

		Functions\expect( 'delete_transient' )
			->once()
			->with( Missing_Author_Terms_Service::COUNT_TRANSIENT );

		$service->clear_count_cache();
	}

	/**
	 * Flatten the argument list so an assertion can look for one value without
	 * depending on the order the placeholders are built in.
	 *
	 * @param array $args Nested argument list.
	 * @return array
	 */
	private function flatten( array $args ): array {
		$flat = array();

		array_walk_recursive(
			$args,
			static function ( $value ) use ( &$flat ) {
				$flat[] = $value;
			}
		);

		return $flat;
	}
}
