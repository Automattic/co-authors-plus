<?php
/**
 * Unit tests for the co-author import service.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Services;

use Automattic\CoAuthorsPlus\Services\Coauthor_Assignment_Service;
use Automattic\CoAuthorsPlus\Services\Coauthor_Import_Service;
use Automattic\CoAuthorsPlus\Services\Guest_Author_Service;
use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use InvalidArgumentException;
use Mockery;

/**
 * @covers \Automattic\CoAuthorsPlus\Services\Coauthor_Import_Service
 */
final class CoauthorImportServiceTest extends TestCase {

	/**
	 * Mocked collaborators.
	 *
	 * @var array<string, \Mockery\MockInterface>
	 */
	private $mocks = array();

	/**
	 * Build a service with mocked collaborators.
	 *
	 * @return Coauthor_Import_Service
	 */
	private function service(): Coauthor_Import_Service {
		$this->mocks = array(
			'guests'      => Mockery::mock( Guest_Author_Service::class ),
			'assignments' => Mockery::mock( Coauthor_Assignment_Service::class ),
		);

		return new Coauthor_Import_Service( $this->mocks['guests'], $this->mocks['assignments'] );
	}

	/**
	 * One export entry.
	 *
	 * @param string $login Guest author login.
	 * @param array  $refs  Post references.
	 * @return array<string, mixed>
	 */
	private function entry( string $login, array $refs = array() ): array {
		return array(
			'profile'   => array( 'user_login' => $login ),
			'post_refs' => $refs,
		);
	}

	public function test_it_rejects_an_export_with_no_guest_author_list(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->service()->import( array( 'version' => '4.2.0' ) );
	}

	/**
	 * A null list is the shape a truncated or hand-edited file takes, and must
	 * not reach a foreach.
	 */
	public function test_it_rejects_a_null_guest_author_list(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->service()->import( array( 'guest_authors' => null ) );
	}

	public function test_it_skips_an_entry_with_no_login(): void {
		$service = $this->service();

		$summary = $service->import( array( 'guest_authors' => array( array( 'profile' => array() ) ) ) );

		$this->assertSame( 0, $summary['authors_created'] );
		$this->assertSame( 0, $summary['authors_skipped'] );
		$this->assertNotEmpty( $summary['warnings'] );
	}

	public function test_it_counts_an_existing_profile_as_skipped(): void {
		$service = $this->service();

		$this->mocks['guests']->shouldReceive( 'find_by' )
			->once()
			->with( 'user_login', 'jane-doe' )
			->andReturn( (object) array( 'user_nicename' => 'jane-doe' ) );

		$summary = $service->import( array( 'guest_authors' => array( $this->entry( 'jane-doe' ) ) ) );

		$this->assertSame( 1, $summary['authors_skipped'] );
		$this->assertSame( 0, $summary['authors_created'] );
	}

	/**
	 * The byline is keyed on user_nicename, which create() derives from the
	 * login and need not match it. Re-reading the created profile rather than
	 * reusing the login is what keeps the assignment correct.
	 */
	public function test_it_assigns_using_the_created_profiles_nicename(): void {
		$service = $this->service();

		$this->mocks['guests']->shouldReceive( 'find_by' )
			->once()
			->with( 'user_login', 'Jane.Doe' )
			->andReturn( false );
		$this->mocks['guests']->shouldReceive( 'create' )->once()->andReturn( 42 );
		$this->mocks['guests']->shouldReceive( 'find_by' )
			->once()
			->with( 'ID', '42' )
			->andReturn( (object) array( 'user_nicename' => 'jane-doe' ) );

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_posts' )->justReturn( array( 100 ) );

		$this->mocks['assignments']->shouldReceive( 'add_at_position' )
			->once()
			->with( 100, 'jane-doe', 2 )
			->andReturn( true );

		$summary = $service->import(
			array(
				'guest_authors' => array(
					$this->entry(
						'Jane.Doe',
						array(
							array(
								'post_slug' => 'hello',
								'post_type' => 'post',
								'position'  => 2,
							),
						)
					),
				),
			)
		);

		$this->assertSame( 1, $summary['authors_created'] );
		$this->assertSame( 1, $summary['posts_linked'] );
	}

	public function test_it_records_a_post_it_cannot_find(): void {
		$service = $this->service();

		$this->mocks['guests']->shouldReceive( 'find_by' )->andReturn( (object) array( 'user_nicename' => 'jane-doe' ) );
		Functions\when( 'get_posts' )->justReturn( array() );

		$summary = $service->import(
			array(
				'guest_authors' => array(
					$this->entry( 'jane-doe', array( array( 'post_slug' => 'gone' ) ) ),
				),
			)
		);

		$this->assertSame( 1, $summary['posts_not_found'] );
		$this->assertSame( 0, $summary['posts_linked'] );
	}

	/**
	 * Idempotency: a post already carrying the author reports no change, which
	 * is what makes a second run safe.
	 */
	public function test_it_does_not_count_a_post_that_was_already_linked(): void {
		$service = $this->service();

		$this->mocks['guests']->shouldReceive( 'find_by' )->andReturn( (object) array( 'user_nicename' => 'jane-doe' ) );
		Functions\when( 'get_posts' )->justReturn( array( 100 ) );
		$this->mocks['assignments']->shouldReceive( 'add_at_position' )->once()->andReturn( false );

		$summary = $service->import(
			array(
				'guest_authors' => array(
					$this->entry( 'jane-doe', array( array( 'post_slug' => 'hello' ) ) ),
				),
			)
		);

		$this->assertSame( 0, $summary['posts_linked'] );
	}

	/**
	 * A dry run must reach neither writer, however much it reports.
	 */
	public function test_a_dry_run_writes_nothing(): void {
		$service = $this->service();

		$this->mocks['guests']->shouldReceive( 'find_by' )->once()->andReturn( false );
		$this->mocks['guests']->shouldNotReceive( 'create' );
		$this->mocks['assignments']->shouldNotReceive( 'add_at_position' );
		$this->mocks['assignments']->shouldNotReceive( 'assign' );

		Functions\when( 'get_posts' )->justReturn( array( 100 ) );

		$summary = $service->import(
			array(
				'guest_authors' => array(
					$this->entry( 'jane-doe', array( array( 'post_slug' => 'hello' ) ) ),
				),
			),
			true
		);

		$this->assertSame( 1, $summary['authors_created'] );
		$this->assertSame( 1, $summary['posts_linked'] );
	}

	public function test_skip_create_does_not_create_a_missing_profile(): void {
		$service = $this->service();

		$this->mocks['guests']->shouldReceive( 'find_by' )->once()->andReturn( false );
		$this->mocks['guests']->shouldNotReceive( 'create' );
		$this->mocks['assignments']->shouldNotReceive( 'add_at_position' );

		Functions\when( 'get_posts' )->justReturn( array( 100 ) );

		$summary = $service->import(
			array(
				'guest_authors' => array(
					$this->entry( 'jane-doe', array( array( 'post_slug' => 'hello' ) ) ),
				),
			),
			false,
			true
		);

		$this->assertSame( 1, $summary['authors_skipped'] );
		$this->assertSame( 0, $summary['authors_created'] );
		$this->assertNotEmpty( $summary['warnings'] );
	}

	public function test_it_warns_and_continues_when_a_profile_cannot_be_created(): void {
		$service = $this->service();

		$error = Mockery::mock( 'WP_Error' );
		$error->shouldReceive( 'get_error_message' )->andReturn( 'login already exists' );

		$this->mocks['guests']->shouldReceive( 'find_by' )->once()->andReturn( false );
		$this->mocks['guests']->shouldReceive( 'create' )->once()->andReturn( $error );
		$this->mocks['assignments']->shouldNotReceive( 'add_at_position' );

		Functions\when( 'is_wp_error' )->justReturn( true );

		$summary = $service->import(
			array(
				'guest_authors' => array(
					$this->entry( 'jane-doe', array( array( 'post_slug' => 'hello' ) ) ),
				),
			)
		);

		$this->assertSame( 0, $summary['authors_created'] );
		$this->assertStringContainsString( 'login already exists', $summary['warnings'][0] );
	}
}
