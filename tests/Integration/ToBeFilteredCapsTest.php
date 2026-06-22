<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

/**
 * Regression coverage for issue #1113.
 *
 * The `coauthors_supported_post_types` filter lets a site add post types to the
 * supported list. If one of those names has no registered post type object
 * (e.g. a custom type that is conditionally registered, or a typo),
 * `get_post_type_object()` returns null. Before the guard, get_to_be_filtered_caps()
 * read `->cap->edit_post` off that null, raising "Attempt to read property cap on
 * null" and pushing null entries into the caps list. It must skip such types.
 *
 * @covers \CoAuthors_Plus::get_to_be_filtered_caps
 */
class ToBeFilteredCapsTest extends TestCase {

	public function test_get_to_be_filtered_caps_skips_unregistered_post_type(): void {
		// Reset the per-request cache so the filter below is actually exercised.
		$this->_cap->to_be_filtered_caps = array();

		$add_missing_post_type = static function ( $post_types ) {
			$post_types[] = 'cap_unregistered_cpt';
			return $post_types;
		};
		add_filter( 'coauthors_supported_post_types', $add_missing_post_type );

		try {
			$caps = $this->_cap->get_to_be_filtered_caps();
		} finally {
			remove_filter( 'coauthors_supported_post_types', $add_missing_post_type );
			$this->_cap->to_be_filtered_caps = array();
		}

		$this->assertIsArray( $caps );
		$this->assertContains( 'edit_post', $caps, 'The base post capabilities should still be present.' );
		$this->assertNotContains( null, $caps, 'An unregistered post type must not contribute null capabilities.' );
		$this->assertNotContains( '', $caps, 'An unregistered post type must not contribute empty capabilities.' );
	}

	public function test_get_to_be_filtered_caps_includes_caps_for_registered_post_type(): void {
		$this->_cap->to_be_filtered_caps = array();

		$caps = $this->_cap->get_to_be_filtered_caps();
		$this->_cap->to_be_filtered_caps = array();

		$post = get_post_type_object( 'post' );
		$this->assertContains( $post->cap->edit_post, $caps, 'Registered post types should still contribute their edit_post cap.' );
	}
}
