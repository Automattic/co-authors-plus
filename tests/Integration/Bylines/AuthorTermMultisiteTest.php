<?php
/**
 * Multisite tests for creating an author term when a user is added to a blog.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * On multisite, user_register fires before the user is a member of any blog,
 * so the capability check in create_author_term_on_user_registration() returns
 * false and no term is created. The real add_user_to_blog() flow (with its
 * switch_to_blog()) is what gives the user a role and creates the term.
 *
 * @covers \CoAuthors_Plus::create_author_term_on_user_registration
 * @group ms-required
 */
class AuthorTermMultisiteTest extends TestCase {

	/**
	 * Adding a user to a blog with the author role should create an author term
	 * for that user on that blog.
	 */
	public function test_author_term_created_when_user_added_to_blog(): void {
		$user = $this->factory()->user->create(
			array(
				'user_login' => 'msblogaddedreg',
			)
		);

		// A network user with no role on the current site has no author term.
		$this->assertEmpty( $this->_cap->get_author_term( get_user_by( 'id', $user ) ) );

		// The real multisite flow: adds the user to the current blog with a
		// role, fires add_user_to_blog, and switches blogs while doing so.
		add_user_to_blog( get_current_blog_id(), $user, 'author' );

		$term = $this->_cap->get_author_term( get_user_by( 'id', $user ) );

		$this->assertNotEmpty( $term, 'Adding a user to a blog with edit_posts capability should create an author term.' );
		$this->assertEquals( 'cap-msblogaddedreg', $term->slug );

		// Clean up the network user so the user does not leak into other tests.
		wpmu_delete_user( $user );
	}
}
