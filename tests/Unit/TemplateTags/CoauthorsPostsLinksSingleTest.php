<?php
/**
 * Unit tests for coauthors_posts_links_single().
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 3 ) . '/template-tags.php';

/**
 * @covers ::coauthors_posts_links_single
 */
final class CoauthorsPostsLinksSingleTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'get_author_posts_url' )->alias(
			static fn( $id, $nicename ) => "http://example.test/author/{$nicename}/"
		);
		Functions\when( '__' )->returnArg();
		// apply_filters( $tag, $value, ... ) returns the filtered value unchanged.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	public function test_builds_anchor_for_a_valid_author(): void {
		$author = (object) array(
			'ID'            => 5,
			'user_nicename' => 'jane',
			'display_name'  => 'Jane Byline',
		);

		$this->assertSame(
			'<a href="http://example.test/author/jane/" title="Posts by Jane Byline" class="author url fn" rel="author">Jane Byline</a>',
			\coauthors_posts_links_single( $author )
		);
	}

	public function test_guest_author_uses_author_link_filter(): void {
		$author = (object) array(
			'ID'            => 10,
			'user_nicename' => 'guest-jane',
			'display_name'  => 'Jane Byline',
			'type'          => 'guest-author',
		);

		// The plugin's author_link filter builds the guest author's own archive URL.
		Functions\when( 'apply_filters' )->alias(
			static fn( $tag, $value, ...$args ) => 'author_link' === $tag
				? 'http://example.test/guests/guest-jane/'
				: $value
		);

		$this->assertStringContainsString(
			'href="http://example.test/guests/guest-jane/"',
			\coauthors_posts_links_single( $author )
		);
	}

	public function test_returns_null_and_warns_for_an_incomplete_author(): void {
		// The guard must fire _doing_it_wrong() and bail rather than emit broken markup.
		Functions\expect( '_doing_it_wrong' )->once();

		$this->assertNull( \coauthors_posts_links_single( (object) array( 'ID' => 5 ) ) );
	}
}
