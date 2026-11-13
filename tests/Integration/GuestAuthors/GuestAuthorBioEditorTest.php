<?php
/**
 * Tests for the opt-in visual editor on the guest author bio.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * The About box renders a plain textarea unless a site switches a field's
 * input type to `editor`, either with the `coauthors_name_field_type_{$field}`
 * filter or the field's `input` key. The editor posts under the same name, so
 * saving needs no changes.
 *
 * @covers CoAuthors_Guest_Authors::metabox_manage_guest_author_bio
 */
class GuestAuthorBioEditorTest extends TestCase {

	public function set_up() {
		parent::set_up();

		global $post;

		$post = get_post( $this->create_guest_author( 'gabioeditor' ) );
		update_post_meta( $post->ID, 'cap-description', 'Writes about <a href="https://example.com">things</a>.' );
	}

	public function tear_down() {
		global $post;
		$post = null;
		parent::tear_down();
	}

	public function test_bio_is_a_plain_textarea_by_default(): void {
		$html = $this->render();

		$this->assertStringContainsString( '<textarea style="width:300px;margin-bottom:6px;" id="cap-description" name="cap-description">', $html );
		$this->assertStringNotContainsString( 'wp-editor-wrap', $html );
	}

	public function test_filter_switches_bio_to_editor(): void {
		add_filter( 'coauthors_name_field_type_cap-description', static fn() => 'editor' );

		$this->assert_renders_editor( $this->render() );
	}

	public function test_field_input_key_switches_bio_to_editor(): void {
		add_filter(
			'coauthors_guest_author_fields',
			static function ( array $fields ): array {
				foreach ( $fields as &$field ) {
					if ( 'description' === $field['key'] ) {
						$field['input'] = 'editor';
					}
				}
				return $fields;
			}
		);

		$this->assert_renders_editor( $this->render() );
	}

	private function render(): string {
		global $coauthors_plus;

		ob_start();
		$coauthors_plus->guest_authors->metabox_manage_guest_author_bio();
		return (string) ob_get_clean();
	}

	private function assert_renders_editor( string $html ): void {
		$this->assertStringContainsString( 'id="wp-cap-description-wrap"', $html, 'The bio should render inside an editor wrapper.' );
		$this->assertMatchesRegularExpression(
			'/<textarea class="wp-editor-area"[^>]*name="cap-description" id="cap-description"/',
			$html,
			'The editor textarea should post under the field name and keep the id its label points at.'
		);
		$this->assertStringContainsString( '<label for="cap-description">', $html );
		// Encoded only when TinyMCE loads, which needs a browser user agent.
		$this->assertMatchesRegularExpression( '/id="cap-description">Writes about (<a|&lt;a)/', $html, 'The stored bio should be loaded into the editor.' );
	}
}
