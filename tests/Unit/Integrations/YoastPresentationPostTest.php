<?php
/**
 * WordPress-free unit tests for the Yoast integration's presentation-based callbacks.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Integrations;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use CoAuthors\Integrations\Yoast;

/*
 * filter_author_meta() and filter_slack_data() both call get_coauthors(), so load
 * the real template-tags.php (plain functions, no WordPress required at load time).
 * The guards under test return before it is ever reached.
 */
require_once dirname( __DIR__, 3 ) . '/template-tags.php';

/**
 * Unit coverage for the Yoast callbacks that read a post out of a presentation.
 *
 * Yoast SEO is not a test dependency, and neither callback type hints its
 * parameters, so the presentation is built here as a plain object carrying the
 * `context->post` chain the callbacks read.
 *
 * @covers \CoAuthors\Integrations\Yoast::filter_author_meta
 * @covers \CoAuthors\Integrations\Yoast::filter_slack_data
 */
final class YoastPresentationPostTest extends TestCase {

	/**
	 * Build a stand-in for a Yoast presentation.
	 *
	 * @param mixed $post The post to place on the presentation context.
	 * @return object
	 */
	private function presentation_with_post( $post ): object {
		$presentation                = new \stdClass();
		$presentation->context       = new \stdClass();
		$presentation->context->post = $post;

		return $presentation;
	}

	/**
	 * Let get_coauthors() run to completion without WordPress.
	 *
	 * With no co-author terms and guest authors forced, it skips both the term
	 * lookup results and the post_author fallback (so no $wpdb is needed) and goes
	 * straight to its `get_coauthors` filter, which the calling test asserts on.
	 */
	private function stub_get_coauthors_dependencies(): void {
		Functions\when( 'cap_get_coauthor_terms_for_post' )->justReturn( array() );

		$GLOBALS['coauthors_plus'] = (object) array( 'force_guest_authors' => true );
	}

	/**
	 * Clean up the global seeded by stub_get_coauthors_dependencies().
	 */
	protected function tear_down(): void {
		unset( $GLOBALS['coauthors_plus'] );

		parent::tear_down();
	}

	/**
	 * Regression coverage for issue #1370.
	 *
	 * Yoast's Slack\Enhanced_Data_Presenter::get() applies `wpseo_enhanced_slack_data`
	 * unconditionally — its own `object_sub_type === 'post'` check gates only the
	 * "Written by" output it builds, not the filter. The callback therefore runs on
	 * author and term archives, where the context carries no post, and used to raise
	 * "Attempt to read property 'id' on null" before returning.
	 */
	public function test_filter_slack_data_returns_data_unchanged_when_context_has_no_post(): void {
		$data = array( 'Reading time' => '3 minutes' );

		$this->assertSame(
			$data,
			Yoast::filter_slack_data( $data, $this->presentation_with_post( null ) ),
			'filter_slack_data() must return the data unchanged when the presentation has no post.'
		);
	}

	/**
	 * A presentation with no context at all must be handled just as safely.
	 */
	public function test_filter_slack_data_returns_data_unchanged_when_presentation_has_no_context(): void {
		$presentation          = new \stdClass();
		$presentation->context = null;

		$data = array( 'Reading time' => '3 minutes' );

		$this->assertSame(
			$data,
			Yoast::filter_slack_data( $data, $presentation ),
			'filter_slack_data() must return the data unchanged when the presentation has no context.'
		);
	}

	/**
	 * @see test_filter_slack_data_returns_data_unchanged_when_context_has_no_post
	 */
	public function test_filter_author_meta_returns_name_unchanged_when_context_has_no_post(): void {
		$this->assertSame(
			'Jane Doe',
			Yoast::filter_author_meta( 'Jane Doe', $this->presentation_with_post( null ) ),
			'filter_author_meta() must return the author name unchanged when the presentation has no post.'
		);
	}

	/**
	 * A post object with no usable ID is treated the same as no post.
	 */
	public function test_filter_slack_data_returns_data_unchanged_when_post_has_no_id(): void {
		$post     = new \stdClass();
		$post->ID = 0;

		$data = array( 'Reading time' => '3 minutes' );

		$this->assertSame(
			$data,
			Yoast::filter_slack_data( $data, $this->presentation_with_post( $post ) ),
			'filter_slack_data() must return the data unchanged when the context post has no ID.'
		);
	}

	/**
	 * @see test_filter_slack_data_returns_data_unchanged_when_post_has_no_id
	 */
	public function test_filter_author_meta_returns_name_unchanged_when_post_has_no_id(): void {
		$post     = new \stdClass();
		$post->ID = 0;

		$this->assertSame(
			'Jane Doe',
			Yoast::filter_author_meta( 'Jane Doe', $this->presentation_with_post( $post ) ),
			'filter_author_meta() must return the author name unchanged when the context post has no ID.'
		);
	}

	/**
	 * Regression coverage for the second half of issue #1370.
	 *
	 * WP_Post declares `ID`, not `id`. Reading a lowercase `id` fell through
	 * WP_Post::__get() to a get_post_meta( $ID, 'id', true ) lookup that returns an
	 * empty string, after which get_coauthors() silently fell back to the global
	 * $post — right by accident on a singular request, wrong everywhere else.
	 *
	 * The lowercase property here stands in for that meta lookup: if the callback
	 * ever reads it again, the assertion on the resolved post ID fails.
	 */
	public function test_filter_slack_data_resolves_the_post_id_from_the_uppercase_property(): void {
		$this->stub_get_coauthors_dependencies();

		$post     = new \stdClass();
		$post->ID = 42;
		$post->id = 99;

		Filters\expectApplied( 'get_coauthors' )
			->once()
			->with( array(), 42 );

		Yoast::filter_slack_data( array(), $this->presentation_with_post( $post ) );
	}

	/**
	 * @see test_filter_slack_data_resolves_the_post_id_from_the_uppercase_property
	 */
	public function test_filter_author_meta_resolves_the_post_id_from_the_uppercase_property(): void {
		$this->stub_get_coauthors_dependencies();

		$post     = new \stdClass();
		$post->ID = 42;
		$post->id = 99;

		Filters\expectApplied( 'get_coauthors' )
			->once()
			->with( array(), 42 );

		Yoast::filter_author_meta( 'Jane Doe', $this->presentation_with_post( $post ) );
	}
}
