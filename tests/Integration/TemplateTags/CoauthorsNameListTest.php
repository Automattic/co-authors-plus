<?php
/**
 * Tests for the co-authors "name list" template tags.
 *
 * `coauthors()`, `coauthors_firstnames()`, `coauthors_lastnames()`,
 * `coauthors_nicknames()`, `coauthors_emails()` and `coauthors_ids()` are
 * near-identical wrappers around `coauthors__echo()`; they differ only in which
 * field of each co-author they output. They are exercised here through a single
 * data-driven test to avoid copy-paste duplication.
 *
 * @see https://github.com/Automattic/Co-Authors-Plus/issues/279
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers ::coauthors()
 * @covers ::coauthors_firstnames()
 * @covers ::coauthors_lastnames()
 * @covers ::coauthors_nicknames()
 * @covers ::coauthors_emails()
 * @covers ::coauthors_ids()
 * @covers ::coauthors__echo()
 */
class CoauthorsNameListTest extends TestCase {

	private $author1;
	private $editor1;
	private $post;

	public function set_up() {
		parent::set_up();

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
	 * Data provider for the name-list template tags.
	 *
	 * Each case carries the template-tag function name and the name of the
	 * `WP_User` property whose value the tag is expected to output for each
	 * co-author. Where a profile field is not set the tag falls back to the
	 * `user_login`, so those cases read from `user_login`.
	 *
	 * The optional `extra_field` / `extra_value` keys, when present, exercise
	 * the tag against a user who *does* have the relevant profile field set, to
	 * prove the tag reads that field rather than always falling back.
	 *
	 * @return iterable<string, array<string, mixed>>
	 */
	public function data_name_list_tags(): iterable {
		yield 'coauthors uses display_name' => array(
			'function' => 'coauthors',
			'property' => 'display_name',
		);

		yield 'coauthors_firstnames falls back to user_login' => array(
			'function'    => 'coauthors_firstnames',
			'property'    => 'user_login',
			'extra_field' => 'first_name',
			'extra_value' => 'Test',
		);

		yield 'coauthors_lastnames falls back to user_login' => array(
			'function'    => 'coauthors_lastnames',
			'property'    => 'user_login',
			'extra_field' => 'last_name',
			'extra_value' => 'Test',
		);

		yield 'coauthors_nicknames falls back to user_login' => array(
			'function'    => 'coauthors_nicknames',
			'property'    => 'user_login',
			'extra_field' => 'nickname',
			'extra_value' => 'Test',
		);

		yield 'coauthors_emails uses user_email' => array(
			'function'    => 'coauthors_emails',
			'property'    => 'user_email',
			'extra_field' => 'user_email',
			'extra_value' => 'test@example.org',
		);

		yield 'coauthors_ids uses ID' => array(
			'function' => 'coauthors_ids',
			'property' => 'ID',
		);
	}

	/**
	 * Checks the co-author name-list template tags output the expected field,
	 * for a single author and for multiple co-authors, with and without markup.
	 *
	 * @dataProvider data_name_list_tags
	 *
	 * @param string      $function    Template-tag function under test.
	 * @param string      $property    `WP_User` property the tag outputs.
	 * @param string|null $extra_field Optional profile field to set on a new user.
	 * @param string|null $extra_value Optional value for the profile field.
	 */
	public function test_name_list_tags( string $function, string $property, ?string $extra_field = null, ?string $extra_value = null ): void {
		global $post, $coauthors_plus;

		// Backing up global post.
		$post_backup = $post;

		$post = $this->post;

		$author_value = (string) $this->author1->$property;
		$editor_value = (string) $this->editor1->$property;

		// Checks for single post author.
		$output = $function( null, null, null, null, false );
		$this->assertEquals( $author_value, $output );

		$output = $function( '</span><span>', '</span><span>', '<span>', '</span>', false );
		$this->assertEquals( '<span>' . $author_value . '</span>', $output );

		// Checks for multiple post authors.
		$coauthors_plus->add_coauthors( $this->post->ID, array( $this->editor1->user_login ), true );

		$output = $function( null, null, null, null, false );
		$this->assertEquals( $author_value . ' and ' . $editor_value, $output );

		$output = $function( '</span><span>', '</span><span>', '<span>', '</span>', false );
		$this->assertEquals( '<span>' . $author_value . '</span><span>' . $editor_value . '</span>', $output );

		// Checks the tag reads the relevant profile field when it is set.
		if ( null !== $extra_field ) {
			$user_id = $this->factory()->user->create(
				array(
					$extra_field => $extra_value,
				)
			);
			$post = $this->factory()->post->create_and_get(
				array(
					'post_author' => $user_id,
				)
			);

			$output = $function( null, null, null, null, false );
			$this->assertEquals( $extra_value, $output );
		}

		// Restore global post from backup.
		$post = $post_backup;
	}
}
