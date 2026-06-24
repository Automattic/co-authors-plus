<?php
/**
 * Base integration test case for Co-Authors Plus.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use PHPUnit\Framework\InvalidArgumentException;
use WP_Term;
use WP_User;

/**
 * Base unit test class for Co-Authors Plus
 */
class TestCase extends \Yoast\WPTestUtils\WPIntegration\TestCase {

	/**
	 * @var CoAuthors_Plus
	 */
	protected $_cap;

	public function set_up() {
		parent::set_up();

		global $coauthors_plus;
		$this->_cap = $coauthors_plus;
	}

	protected function create_subscriber( $user_login = 'subscriber' ) {
		return $this->factory()->user->create_and_get(
			array(
				'role'       => 'subscriber',
				'user_login' => $user_login,
			)
		);
	}

	protected function create_contributor( $user_login = 'contributor' ) {
		return $this->factory()->user->create_and_get(
			array(
				'role'       => 'contributor',
				'user_login' => $user_login,
			)
		);
	}

	protected function create_author( $user_login = 'author' ) {
		return $this->factory()->user->create_and_get(
			array(
				'role'       => 'author',
				'user_login' => $user_login,
			)
		);
	}

	protected function create_editor( $user_login = 'editor' ) {
		return $this->factory()->user->create_and_get(
			array(
				'role'       => 'editor',
				'user_login' => $user_login,
			)
		);
	}

	protected function create_guest_author( $user_login = 'guest_author' ) {
		global $coauthors_plus;
		return $coauthors_plus->guest_authors->create(
			array(
				'display_name' => $user_login,
				'user_login'   => $user_login,
			)
		);
	}

	protected function create_post( ?\WP_User $author = null ) {
		if ( null === $author ) {
			$author = $this->create_author();
		}
		return $this->factory()->post->create_and_get(
			array(
				'post_author'  => $author->ID,
				'post_status'  => 'publish',
				'post_content' => rand_str(),
				'post_title'   => rand_str(),
				'post_type'    => 'post',
			)
		);
	}

	/**
	 * Convenience function which makes sure an author object is not a WP_User. This is because we don't have
	 * an actual "Guest Author" object.
	 *
	 * @param object $author The author object.
	 *
	 * @return void
	 */
	public function assertIsGuestAuthorNotWpUser( object $author ): void {
		// Perhaps we can further assert that the required properties exist on the object or is that overkill?

		$this->assertThat(
			$author,
			$this->logicalNot(
				$this->isInstanceOf( WP_User::class )
			)
		);
	}

	/**
	 * This function handles asserting that a post has the authors specified, and the correct number of authors.
	 *
	 * @param int   $post_id The Post ID.
	 * @param array $authors The authors to check that are assigned to a post.
	 *
	 * @return void
	 * @throws InvalidArgumentException Throws exception if $authors is not a valid Guest Author or WP_User object or a string.
	 */
	public function assertPostHasCoAuthors( int $post_id, array $authors ) {
		$authors = array_map(
			function ( $author ) {
				if ( is_object( $author ) ) {
					return 'cap-' . $author->user_login;
				} elseif ( is_string( $author ) ) {
					return $author; // Assuming that caller is giving author slug.
				} else {
					throw InvalidArgumentException::create( 2, 'Authors should be string, Guest Author Object, or WP_User' );
				}
			},
			$authors
		);

		$post_author_terms = wp_get_post_terms( $post_id, $this->_cap->coauthor_taxonomy );

		$this->assertIsArray( $post_author_terms );
		$this->assertSameSize(
			$authors,
			$post_author_terms
		);

		foreach ( $post_author_terms as $term ) {
			$this->assertInstanceOf( WP_Term::class, $term );
			$this->assertContains( $term->slug, $authors );
		}
	}
}
