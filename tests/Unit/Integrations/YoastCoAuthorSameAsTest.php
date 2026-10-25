<?php
/**
 * Unit tests for the sameAs URLs the Yoast integration builds for guest authors.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Integrations;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use CoAuthors\Integrations\Yoast\CoAuthor;

/*
 * CoAuthor extends Yoast's Author schema piece, and Yoast SEO is not a test
 * dependency. The method under test uses nothing from the parent, so an empty
 * stand-in is enough for the class to load. It must be a user-defined class:
 * class_alias() only accepts internal classes such as stdClass from PHP 8.3.
 */
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Stand-in class for Yoast plus the test class.
if ( ! class_exists( 'Yoast\WP\SEO\Generators\Schema\Author' ) ) {
	class YoastAuthorStub {}
	class_alias( YoastAuthorStub::class, 'Yoast\WP\SEO\Generators\Schema\Author' );
}

/**
 * @covers \CoAuthors\Integrations\Yoast\CoAuthor::add_guest_author_same_as_urls
 */
final class YoastCoAuthorSameAsTest extends TestCase {

	/**
	 * Call the protected method with the given guest author fields.
	 *
	 * @param array<string, string> $fields Guest author properties.
	 * @return array Person schema data.
	 */
	private function same_as( array $fields ): array {
		$coauthor = new class() extends CoAuthor {
			public function same_as( array $data, object $guest_author ): array {
				return $this->add_guest_author_same_as_urls( $data, $guest_author );
			}
		};

		return $coauthor->same_as( array(), (object) $fields );
	}

	/**
	 * Issue #317: the social profiles Yoast reads for users are read for guest
	 * authors too, after the website.
	 */
	public function test_adds_social_profiles_after_the_website(): void {
		$data = $this->same_as(
			array(
				'website'   => 'https://example.com',
				'facebook'  => 'https://facebook.com/jane',
				'twitter'   => 'jane',
				'wikipedia' => 'https://en.wikipedia.org/wiki/Jane',
				'linkedin'  => '',
			)
		);

		$this->assertSame(
			array(
				'https://example.com',
				'https://facebook.com/jane',
				'https://x.com/jane',
				'https://en.wikipedia.org/wiki/Jane',
			),
			$data['sameAs']
		);
	}

	/**
	 * @dataProvider provide_twitter_values
	 *
	 * @param string $value    Stored twitter field.
	 * @param string $expected Expected sameAs URL.
	 */
	public function test_twitter_value_becomes_a_url( string $value, string $expected ): void {
		$this->assertSame( array( $expected ), $this->same_as( array( 'twitter' => $value ) )['sameAs'] );
	}

	public static function provide_twitter_values(): iterable {
		yield 'username'         => array( 'jane', 'https://x.com/jane' );
		yield 'username with @'  => array( '@jane', 'https://x.com/jane' );
		yield 'pasted x.com URL' => array( 'https://x.com/jane', 'https://x.com/jane' );
		yield 'old twitter URL'  => array( 'http://twitter.com/jane', 'http://twitter.com/jane' );
	}

	public function test_no_same_as_when_nothing_is_set(): void {
		$this->assertArrayNotHasKey( 'sameAs', $this->same_as( array( 'website' => '' ) ) );
	}

	public function test_duplicate_urls_are_listed_once(): void {
		$data = $this->same_as(
			array(
				'website'  => 'https://facebook.com/jane',
				'facebook' => 'https://facebook.com/jane',
			)
		);

		$this->assertSame( array( 'https://facebook.com/jane' ), $data['sameAs'] );
	}
}
