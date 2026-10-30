<?php
/**
 * Unit tests for the co-author assignment service.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Services;

use Automattic\CoAuthorsPlus\Services\Coauthor_Assignment_Service;
use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @covers \Automattic\CoAuthorsPlus\Services\Coauthor_Assignment_Service
 */
final class CoauthorAssignmentServiceTest extends TestCase {

	/**
	 * Build a service with a mocked plugin instance.
	 *
	 * @return array{0: Coauthor_Assignment_Service, 1: \Mockery\MockInterface}
	 */
	private function service(): array {
		$coauthors_plus                     = Mockery::mock( 'CoAuthors_Plus' );
		$coauthors_plus->coauthor_taxonomy  = 'author';

		return array( new Coauthor_Assignment_Service( $coauthors_plus ), $coauthors_plus );
	}

	/**
	 * Turn slugs into the term objects wp_get_object_terms() would return.
	 *
	 * @param string[] $slugs Term slugs.
	 * @return object[]
	 */
	private function terms( array $slugs ): array {
		return array_map(
			static function ( string $slug ) {
				return (object) array( 'slug' => $slug );
			},
			$slugs
		);
	}

	public function test_it_strips_the_cap_prefix_to_recover_nicenames(): void {
		list( $service ) = $this->service();

		Functions\expect( 'wp_get_object_terms' )
			->once()
			->with( 7, 'author', array( 'orderby' => 'term_order' ) )
			->andReturn( $this->terms( array( 'cap-alice', 'cap-bob' ) ) );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->assertSame( array( 'alice', 'bob' ), $service->nicenames_for_post( 7 ) );
	}

	/**
	 * A nicename may itself begin with "cap-", giving a doubly prefixed slug.
	 * Only the prefix the plugin added should come off.
	 */
	public function test_it_strips_only_one_cap_prefix(): void {
		list( $service ) = $this->service();

		Functions\when( 'wp_get_object_terms' )->justReturn( $this->terms( array( 'cap-cap-ri' ) ) );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->assertSame( array( 'cap-ri' ), $service->nicenames_for_post( 7 ) );
	}

	public function test_it_returns_no_nicenames_when_the_taxonomy_query_fails(): void {
		list( $service ) = $this->service();

		Functions\when( 'wp_get_object_terms' )->justReturn( 'error' );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$this->assertSame( array(), $service->nicenames_for_post( 7 ) );
	}

	/**
	 * Replacing rather than appending is what makes the ordered list
	 * authoritative, so append must be false.
	 */
	public function test_it_replaces_the_whole_byline_when_assigning(): void {
		list( $service, $coauthors_plus ) = $this->service();

		$coauthors_plus->shouldReceive( 'add_coauthors' )
			->once()
			->with( 7, array( 'alice', 'bob' ), false )
			->andReturn( true );

		$this->assertTrue( $service->assign( 7, array( 'alice', 'bob' ) ) );
	}

	public function test_it_inserts_at_the_requested_position(): void {
		list( $service, $coauthors_plus ) = $this->service();

		Functions\when( 'wp_get_object_terms' )->justReturn( $this->terms( array( 'cap-alice', 'cap-bob' ) ) );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$coauthors_plus->shouldReceive( 'add_coauthors' )
			->once()
			->with( 7, array( 'alice', 'carol', 'bob' ), false )
			->andReturn( true );

		$this->assertTrue( $service->add_at_position( 7, 'carol', 1 ) );
	}

	public function test_it_appends_when_the_position_is_past_the_end(): void {
		list( $service, $coauthors_plus ) = $this->service();

		Functions\when( 'wp_get_object_terms' )->justReturn( $this->terms( array( 'cap-alice' ) ) );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$coauthors_plus->shouldReceive( 'add_coauthors' )
			->once()
			->with( 7, array( 'alice', 'carol' ), false )
			->andReturn( true );

		$this->assertTrue( $service->add_at_position( 7, 'carol', 99 ) );
	}

	/**
	 * A false return from add_coauthors() does not mean nothing changed. It
	 * returns false when it replaces a byline that resolves to no WordPress
	 * user, which is every byline of guest authors without linked accounts,
	 * despite having written the terms. Reporting that as "unchanged" made an
	 * import claim it had linked nothing while the terms were there.
	 */
	public function test_it_reports_a_change_even_when_add_coauthors_returns_false(): void {
		list( $service, $coauthors_plus ) = $this->service();

		// Empty before the write, carrying the term after it: the false from
		// add_coauthors() hid a successful write.
		Functions\expect( 'wp_get_object_terms' )
			->twice()
			->andReturn( array(), $this->terms( array( 'cap-carol' ) ) );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$coauthors_plus->shouldReceive( 'add_coauthors' )
			->once()
			->with( 7, array( 'carol' ), false )
			->andReturn( false );

		$this->assertTrue( $service->add_at_position( 7, 'carol', 0 ) );
	}

	/**
	 * When add_coauthors() returns false AND the re-read shows the co-author
	 * never landed on the post, the write genuinely failed, and saying so is
	 * what stops an import counting the post as linked.
	 */
	public function test_it_reports_failure_when_the_write_does_not_land(): void {
		list( $service, $coauthors_plus ) = $this->service();

		Functions\when( 'wp_get_object_terms' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$coauthors_plus->shouldReceive( 'add_coauthors' )
			->once()
			->with( 7, array( 'carol' ), false )
			->andReturn( false );

		$this->assertFalse( $service->add_at_position( 7, 'carol', 0 ) );
	}

	/**
	 * Idempotency: this is what lets an import run twice without duplicating a
	 * byline, so it must not reach add_coauthors() at all.
	 */
	public function test_it_does_not_rewrite_a_post_that_already_has_the_coauthor(): void {
		list( $service, $coauthors_plus ) = $this->service();

		Functions\when( 'wp_get_object_terms' )->justReturn( $this->terms( array( 'cap-alice', 'cap-carol' ) ) );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$coauthors_plus->shouldNotReceive( 'add_coauthors' );

		$this->assertFalse( $service->add_at_position( 7, 'carol', 0 ) );
	}
}
