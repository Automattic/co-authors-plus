<?php
/**
 * Unit tests for the co-author export service.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Services;

use Automattic\CoAuthorsPlus\Services\Coauthor_Assignment_Service;
use Automattic\CoAuthorsPlus\Services\Coauthor_Export_Service;
use Automattic\CoAuthorsPlus\Services\Guest_Author_Service;
use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @covers \Automattic\CoAuthorsPlus\Services\Coauthor_Export_Service
 */
final class CoauthorExportServiceTest extends TestCase {

	/**
	 * Mocked collaborators, keyed for readability.
	 *
	 * @var array<string, \Mockery\MockInterface>
	 */
	private $mocks = array();

	/**
	 * Build a service with mocked collaborators.
	 *
	 * @return Coauthor_Export_Service
	 */
	private function service(): Coauthor_Export_Service {
		$coauthors_plus                    = Mockery::mock( 'CoAuthors_Plus' );
		$coauthors_plus->coauthor_taxonomy = 'author';
		$coauthors_plus->guest_authors     = (object) array( 'post_type' => 'guest-author' );

		$this->mocks = array(
			'plugin'      => $coauthors_plus,
			'guests'      => Mockery::mock( Guest_Author_Service::class ),
			'assignments' => Mockery::mock( Coauthor_Assignment_Service::class ),
		);

		return new Coauthor_Export_Service(
			$coauthors_plus,
			$this->mocks['guests'],
			$this->mocks['assignments']
		);
	}

	public function test_it_returns_null_for_a_guest_author_it_cannot_load(): void {
		$service = $this->service();

		$this->mocks['guests']->shouldReceive( 'find_by' )->once()->with( 'ID', '9' )->andReturn( false );

		$this->assertNull( $service->entry_for( 9, array( 'post' ) ) );
	}

	public function test_it_records_no_assignments_when_the_author_has_no_term(): void {
		$service = $this->service();

		$guest_author = (object) array( 'user_nicename' => 'jane-doe' );

		$this->mocks['guests']->shouldReceive( 'find_by' )->andReturn( $guest_author );
		$this->mocks['guests']->shouldReceive( 'profile' )->andReturn( array( 'user_login' => 'jane-doe' ) );
		$this->mocks['plugin']->shouldReceive( 'get_author_term' )->once()->with( $guest_author )->andReturn( false );

		$entry = $service->entry_for( 9, array( 'post' ) );

		$this->assertSame( array(), $entry['post_refs'] );
		$this->assertSame( array( 'user_login' => 'jane-doe' ), $entry['profile'] );
	}

	/**
	 * Assignments are keyed by slug and post type, never by ID, because IDs do
	 * not survive a WordPress export and import cycle.
	 */
	public function test_it_records_assignments_by_slug_and_post_type_with_position(): void {
		$service = $this->service();

		$guest_author = (object) array( 'user_nicename' => 'jane-doe' );

		$this->mocks['guests']->shouldReceive( 'find_by' )->andReturn( $guest_author );
		$this->mocks['guests']->shouldReceive( 'profile' )->andReturn( array() );
		$this->mocks['plugin']->shouldReceive( 'get_author_term' )->andReturn( (object) array( 'term_id' => 12 ) );

		Functions\when( 'get_posts' )->justReturn(
			array(
				(object) array(
					'ID'        => 100,
					'post_name' => 'hello-world',
					'post_type' => 'post',
				),
			)
		);

		$this->mocks['assignments']->shouldReceive( 'nicenames_for_post' )
			->once()
			->with( 100 )
			->andReturn( array( 'someone-else', 'jane-doe' ) );

		$entry = $service->entry_for( 9, array( 'post' ) );

		$this->assertSame(
			array(
				array(
					'post_slug' => 'hello-world',
					'post_type' => 'post',
					'position'  => 1,
				),
			),
			$entry['post_refs']
		);
	}

	/**
	 * A post carrying the term whose byline does not list the author is a
	 * broken assignment rather than a crash, so it records position 0 instead
	 * of array_search()'s false leaking into the output.
	 */
	public function test_it_falls_back_to_the_first_position_when_the_byline_omits_the_author(): void {
		$service = $this->service();

		$guest_author = (object) array( 'user_nicename' => 'jane-doe' );

		$this->mocks['guests']->shouldReceive( 'find_by' )->andReturn( $guest_author );
		$this->mocks['guests']->shouldReceive( 'profile' )->andReturn( array() );
		$this->mocks['plugin']->shouldReceive( 'get_author_term' )->andReturn( (object) array( 'term_id' => 12 ) );

		Functions\when( 'get_posts' )->justReturn(
			array(
				(object) array(
					'ID'        => 100,
					'post_name' => 'hello-world',
					'post_type' => 'post',
				),
			)
		);

		$this->mocks['assignments']->shouldReceive( 'nicenames_for_post' )->andReturn( array( 'someone-else' ) );

		$entry = $service->entry_for( 9, array( 'post' ) );

		$this->assertSame( 0, $entry['post_refs'][0]['position'] );
	}

	public function test_it_collects_guest_author_ids_as_integers(): void {
		$service = $this->service();

		Functions\when( 'get_posts' )->justReturn( array( '3', '7' ) );

		$this->assertSame( array( 3, 7 ), $service->guest_author_ids() );
	}
}
