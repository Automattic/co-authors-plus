<?php
/**
 * Tests for the published-post count of a co-author.
 *
 * @package CoAuthors
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration;

/**
 * Tests that count_user_posts() reflects a user's co-authored posts and honours
 * the coauthors_count_published_post_types filter.
 *
 * @covers \CoAuthors_Plus::filter_count_user_posts
 */
class CoauthorPostPublishCountTest extends TestCase {

	/**
	 * @var int
	 */
	private $author1;

	/**
	 * @var int
	 */
	private $editor1;

	/**
	 * @var int|\WP_Error
	 */
	private $author1_post1;

	/**
	 * @var int|\WP_Error
	 */
	private $author1_post2;

	/**
	 * @var int|\WP_Error
	 */
	private $author1_page1;

	/**
	 * @var int|\WP_Error
	 */
	private $author1_page2;

	/**
	 * Filters added during the test, removed in tear_down to avoid leaking
	 * into other tests.
	 *
	 * @var callable[]
	 */
	private $registered_filters = array();

	public function set_up() {
		parent::set_up();

		$this->author1 = $this->factory()->user->create(
			array(
				'role'       => 'author',
				'user_login' => 'author1',
			)
		);
		$this->editor1 = $this->factory()->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'editor2',
			)
		);

		// Authoring posts/pages through wp_insert_post fires save_post, so
		// CoAuthors Plus creates the author1 co-author term for each.
		$this->author1_post1 = wp_insert_post(
			array(
				'post_author'  => $this->author1,
				'post_status'  => 'publish',
				'post_content' => rand_str(),
				'post_title'   => rand_str(),
				'post_type'    => 'post',
			)
		);

		$this->author1_post2 = wp_insert_post(
			array(
				'post_author'  => $this->author1,
				'post_status'  => 'publish',
				'post_content' => rand_str(),
				'post_title'   => rand_str(),
				'post_type'    => 'post',
			)
		);

		$this->author1_page1 = wp_insert_post(
			array(
				'post_author'  => $this->author1,
				'post_status'  => 'publish',
				'post_content' => rand_str(),
				'post_title'   => rand_str(),
				'post_type'    => 'page',
			)
		);

		$this->author1_page2 = wp_insert_post(
			array(
				'post_author'  => $this->author1,
				'post_status'  => 'publish',
				'post_content' => rand_str(),
				'post_title'   => rand_str(),
				'post_type'    => 'page',
			)
		);
	}

	public function tear_down(): void {
		// Remove every coauthors_count_published_post_types filter registered
		// during the test so it cannot leak into other tests.
		foreach ( $this->registered_filters as $filter ) {
			remove_filter( 'coauthors_count_published_post_types', $filter );
		}
		$this->registered_filters = array();

		parent::tear_down();
	}

	/**
	 * Register a coauthors_count_published_post_types filter and track it for
	 * removal in tear_down.
	 *
	 * @param callable $filter The filter callback.
	 */
	private function add_count_filter( callable $filter ): void {
		add_filter( 'coauthors_count_published_post_types', $filter );
		$this->registered_filters[] = $filter;
	}

	/**
	 * Post published count should default to 'post', but be filterable.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/170
	 */
	public function test_post_publish_count_for_coauthor(): void {
		global $coauthors_plus;

		$editor1 = get_user_by( 'id', $this->editor1 );

		/**
		 * Two published posts.
		 */
		$coauthors_plus->add_coauthors( $this->author1_post1, array( $editor1->user_login ) );
		$coauthors_plus->add_coauthors( $this->author1_post2, array( $editor1->user_login ) );
		$this->assertEquals( 2, count_user_posts( $editor1->ID ) );

		/**
		 * One published page too, but no filter.
		 */
		$coauthors_plus->add_coauthors( $this->author1_page1, array( $editor1->user_login ) );
		$this->assertEquals( 2, count_user_posts( $editor1->ID ) );

		// Publish count to include posts and pages.
		$posts_and_pages = static function () {
			return array( 'post', 'page' );
		};
		$this->add_count_filter( $posts_and_pages );

		/**
		 * Two published posts and pages.
		 */
		$coauthors_plus->add_coauthors( $this->author1_page2, array( $editor1->user_login ) );
		$this->assertEquals( 4, count_user_posts( $editor1->ID ) );

		// Publish count is just pages.
		remove_filter( 'coauthors_count_published_post_types', $posts_and_pages );
		$pages_only = static function () {
			return array( 'page' );
		};
		$this->add_count_filter( $pages_only );

		/**
		 * Just one published page now for the editor.
		 */
		$author1 = get_user_by( 'id', $this->author1 );
		$coauthors_plus->add_coauthors( $this->author1_page2, array( $author1->user_login ) );
		$this->assertEquals( 1, count_user_posts( $editor1->ID ) );
	}
}
