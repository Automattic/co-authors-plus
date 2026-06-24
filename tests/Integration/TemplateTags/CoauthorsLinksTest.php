<?php
/**
 * Tests for the coauthors_links() template tag.
 *
 * @see https://github.com/Automattic/Co-Authors-Plus/issues/279
 * @see https://github.com/Automattic/Co-Authors-Plus/issues/1066
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers ::coauthors_links()
 * @covers ::coauthors__echo()
 */
class CoauthorsLinksTest extends TestCase {

	use \Yoast\PHPUnitPolyfills\Polyfills\AssertStringContains;

	private $author1;
	private $editor1;
	private $post;

	public function set_up() {
		parent::set_up();

		/**
		 * When 'coauthors_auto_apply_template_tags' is set to true,
		 * we need a CoAuthors_Template_Filters object to check the 'the_author' filter.
		 */
		global $coauthors_plus_template_filters;
		$coauthors_plus_template_filters = new \CoAuthors_Template_Filters();
		$coauthors_plus_template_filters->register_hooks();

		$this->author1 = $this->factory()->user->create_and_get(
			array(
				'role'       => 'author',
				'user_login' => 'author1',
			)
		);
		$this->editor1 = $this->factory()->user->create_and_get(
			array(
				'role'       => 'editor',
				'user_login' => 'editor1',
			)
		);
		$this->post = $this->factory()->post->create_and_get(
			array(
				'post_author'  => $this->author1->ID,
				'post_status'  => 'publish',
				'post_content' => rand_str(),
				'post_title'   => rand_str(),
				'post_type'    => 'post',
			)
		);
	}

	/**
	 * Tear down test state.
	 *
	 * `register_hooks()` registers `the_author` and `the_author_posts_link`
	 * filters globally. We must unhook those and unset the global instance to
	 * prevent state leaking into later tests in the suite.
	 */
	public function tear_down() {
		global $coauthors_plus_template_filters;

		if ( $coauthors_plus_template_filters instanceof \CoAuthors_Template_Filters ) {
			remove_filter( 'the_author', array( $coauthors_plus_template_filters, 'filter_the_author' ) );
			remove_filter( 'the_author_posts_link', array( $coauthors_plus_template_filters, 'filter_the_author_posts_link' ) );
			remove_filter( 'the_author', array( $coauthors_plus_template_filters, 'filter_the_author_rss' ), 15 );
		}

		$coauthors_plus_template_filters = null;

		parent::tear_down();
	}

	/**
	 * Tests for co-authors display names, with links to their posts.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/279
	 */
	public function test_coauthors_links(): void {

		global $coauthors_plus, $coauthors_plus_template_filters;

		// Backing up global post.
		$post_backup = $GLOBALS['post'];

		$GLOBALS['post'] = $this->post;

		// Checks for single post author.
		$single_cpl = coauthors_links( null, null, null, null, false );

		$this->assertEquals( $this->author1->display_name, $single_cpl, 'Author name not found.' );

		// Checks for multiple post author.
		$coauthors_plus->add_coauthors( $this->post->ID, array( $this->editor1->user_login ), true );

		$multiple_cpl = coauthors_links( null, null, null, null, false );

		$this->assertStringContainsString( $this->author1->display_name, $multiple_cpl, 'Main author name not found.' );
		$this->assertEquals( 1, substr_count( $multiple_cpl, $this->author1->display_name ) );
		$this->assertStringContainsString( ' and ', $multiple_cpl, 'Coauthors name separator is not matched.' );
		$this->assertStringContainsString( $this->editor1->display_name, $multiple_cpl, 'Coauthor name not found.' );
		$this->assertEquals( 1, substr_count( $multiple_cpl, $this->editor1->display_name ) );

		$multiple_cpl = coauthors_links( null, ' or ', null, null, false );

		$this->assertStringContainsString( ' or ', $multiple_cpl, 'Coauthors name separator is not matched.' );

		$this->assertEquals(
			10,
			has_filter(
				'the_author',
				array(
					$coauthors_plus_template_filters,
					'filter_the_author',
				)
			)
		);

		// Restore backed up post to global.
		$GLOBALS['post'] = $post_backup;
	}

	/**
	 * Tests that template tags don't cause PHP warnings when post has no author.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/1066
	 */
	public function test_coauthors_links_when_post_has_no_author(): void {
		global $post;

		// Backing up global post.
		$post_backup = $post;

		// Create a post with no author (post_author = 0).
		$post_without_author = $this->factory()->post->create_and_get(
			array(
				'post_author'  => 0,
				'post_status'  => 'publish',
				'post_content' => rand_str(),
				'post_title'   => 'Post without author',
				'post_type'    => 'post',
			)
		);

		$post = $post_without_author;

		// This should not cause a PHP warning about accessing a property on null;
		// the function should handle the case where there's no author gracefully.
		$result = coauthors_links( null, null, null, null, false );

		// When there's no author, the function returns an empty string.
		$this->assertSame( '', $result, 'Result should be an empty string when the post has no author.' );

		// Restore global post from backup.
		$post = $post_backup;
	}
}
