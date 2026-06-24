<?php
/**
 * Unit tests for coauthors_links_single().
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 3 ) . '/template-tags.php';

/**
 * @covers ::coauthors_links_single
 */
final class CoauthorsLinksSingleTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();

		// The formatter only calls WordPress escaping and i18n helpers; stub them
		// to return their input so the assertions check the markup the plugin builds.
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( '__' )->returnArg();
	}

	/**
	 * @dataProvider provide_authors
	 */
	public function test_renders_single_coauthor_link( object $author, string $expected ): void {
		$this->assertSame( $expected, \coauthors_links_single( $author ) );
	}

	public static function provide_authors(): iterable {
		yield 'guest author links to their website' => array(
			(object) array(
				'display_name' => 'Jane Byline',
				'type'         => 'guest-author',
				'website'      => 'https://example.com',
			),
			'<a href="https://example.com" title="Visit Jane Byline&#8217;s website" rel="author external">Jane Byline</a>',
		);

		yield 'guest author without a website renders the plain name' => array(
			(object) array(
				'display_name' => 'Jane Byline',
				'type'         => 'guest-author',
				'website'      => '',
			),
			'Jane Byline',
		);

		yield 'wp user links to their user_url' => array(
			(object) array(
				'display_name' => 'Sam Writer',
				'type'         => 'wp-user',
				'user_url'     => 'https://sam.example',
			),
			'<a href="https://sam.example" title="Visit Sam Writer&#8217;s website" rel="author external">Sam Writer</a>',
		);

		yield 'wp user without a url renders the plain name' => array(
			(object) array(
				'display_name' => 'Sam Writer',
				'type'         => 'wp-user',
				'user_url'     => '',
			),
			'Sam Writer',
		);
	}
}
