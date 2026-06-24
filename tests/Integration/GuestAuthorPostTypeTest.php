<?php
/**
 * Tests for the registration of the guest-author custom post type.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration;

/**
 * @coversDefaultClass \CoAuthors_Guest_Authors
 */
class GuestAuthorPostTypeTest extends TestCase {

	/**
	 * Checks that the guest-author post type is registered as private but
	 * still exposes an admin UI.
	 *
	 * Public guest-author CPTs surface in other plugins (e.g. Yoast SEO) that
	 * scan for public post types, even though guest-authors are not meant to
	 * be queried on the front end. The admin UI must stay available so guest
	 * author profiles can still be edited.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/105
	 */
	public function test_guest_author_post_type_is_private_but_has_admin_ui(): void {

		global $coauthors_plus;

		$post_type_object = get_post_type_object( $coauthors_plus->guest_authors->post_type );

		$this->assertInstanceOf( \WP_Post_Type::class, $post_type_object );
		$this->assertFalse( $post_type_object->public, 'Guest author CPT must not be registered as public.' );
		$this->assertFalse( $post_type_object->publicly_queryable, 'Guest author CPT must not be publicly queryable.' );
		$this->assertTrue( $post_type_object->show_ui, 'Guest author CPT must still expose an admin UI.' );
	}
}
