<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

/**
 * Regression coverage for issue #1049.
 *
 * Previously supported_post_types() was recomputed on every call —
 * get_post_types() plus the coauthors_supported_post_types filter — hundreds of
 * times per request.
 * It now returns the value stored on $this->supported_post_types after the
 * first call. This test pins that memoisation: once computed, a later change to
 * the filter does not alter the result within the same request.
 *
 * @covers \CoAuthors_Plus::supported_post_types
 */
class SupportedPostTypesCacheTest extends TestCase {

	public function test_supported_post_types_is_memoised_after_first_call(): void {
		// Clear any value cached during bootstrap so the first call below computes fresh.
		$this->_cap->supported_post_types = array();

		$first = $this->_cap->supported_post_types();
		$this->assertNotEmpty( $first, 'The first computation should return the supported post types.' );

		// A filter added after the first call must be ignored while the result is cached.
		$add_type = static function ( $types ) {
			$types[] = 'cap_post_type_added_late';
			return $types;
		};
		add_filter( 'coauthors_supported_post_types', $add_type );

		try {
			$second = $this->_cap->supported_post_types();
		} finally {
			remove_filter( 'coauthors_supported_post_types', $add_type );
			$this->_cap->supported_post_types = array();
		}

		$this->assertSame( $first, $second, 'supported_post_types() should return the memoised result on subsequent calls.' );
		$this->assertNotContains( 'cap_post_type_added_late', $second, 'A filter change after the first call must not leak into the cached result.' );
	}
}
