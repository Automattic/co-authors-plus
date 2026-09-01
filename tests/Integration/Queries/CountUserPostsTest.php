<?php
/**
 * Tests for the count_user_posts filter.
 *
 * @package CoAuthors
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Queries;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Tests for filter_count_user_posts().
 *
 * @covers CoAuthors_Plus::filter_count_user_posts
 */
class CountUserPostsTest extends TestCase {

	/**
	 * Test that count_user_posts works with default post type.
	 */
	public function test_count_user_posts_default_post_type(): void {
		$author = $this->create_author();

		// Create posts for the author.
		$this->factory()->post->create_many(
			3,
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$count = count_user_posts( $author->ID );

		$this->assertSame( 3, $count );
	}

	/**
	 * Test that count_user_posts works with custom post types.
	 *
	 * @covers CoAuthors_Plus::filter_count_user_posts
	 * @covers CoAuthors_Plus::get_post_count_for_author_term
	 */
	public function test_count_user_posts_custom_post_type(): void {
		global $coauthors_plus;

		// Register a custom post type with author support.
		register_post_type(
			'custom_cpt',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor', 'author' ),
			)
		);

		// Add the custom post type to supported types.
		add_filter(
			'coauthors_supported_post_types',
			function ( $post_types ) {
				$post_types[] = 'custom_cpt';
				return $post_types;
			}
		);

		$author = $this->create_author();

		// Create posts of different types and assign coauthors.
		$post_ids = $this->factory()->post->create_many(
			2,
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		foreach ( $post_ids as $post_id ) {
			$coauthors_plus->add_coauthors( $post_id, array( $author->user_login ) );
		}

		$cpt_ids = $this->factory()->post->create_many(
			3,
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
				'post_type'   => 'custom_cpt',
			)
		);

		foreach ( $cpt_ids as $post_id ) {
			$coauthors_plus->add_coauthors( $post_id, array( $author->user_login ) );
		}

		// Count only 'post' type.
		$post_count = count_user_posts( $author->ID, 'post' );
		$this->assertSame( 2, $post_count );

		// Count only custom post type.
		$cpt_count = count_user_posts( $author->ID, 'custom_cpt' );
		$this->assertSame( 3, $cpt_count );

		// Count both types.
		$both_count = count_user_posts( $author->ID, array( 'post', 'custom_cpt' ) );
		$this->assertSame( 5, $both_count );

		// Clean up.
		unregister_post_type( 'custom_cpt' );
	}

	/**
	 * Test that count_user_posts respects public_only parameter.
	 *
	 * @covers CoAuthors_Plus::filter_count_user_posts
	 * @covers CoAuthors_Plus::get_post_count_for_author_term
	 */
	public function test_count_user_posts_public_only(): void {
		$author = $this->create_author();

		// Create published posts.
		$this->factory()->post->create_many(
			2,
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		// Create private posts.
		$this->factory()->post->create_many(
			3,
			array(
				'post_author' => $author->ID,
				'post_status' => 'private',
				'post_type'   => 'post',
			)
		);

		// Count all (public and private).
		$all_count = count_user_posts( $author->ID, 'post', false );
		$this->assertSame( 5, $all_count );

		// Count only public.
		$public_count = count_user_posts( $author->ID, 'post', true );
		$this->assertSame( 2, $public_count );
	}

	/**
	 * Test that the post count is served from the object cache until it is cleared.
	 *
	 * @covers CoAuthors_Plus::filter_count_user_posts
	 * @covers CoAuthors_Plus::get_post_count_for_author_term
	 */
	public function test_count_user_posts_is_cached(): void {
		$author = $this->create_author();

		$this->factory()->post->create_many(
			2,
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$this->assertSame( 2, count_user_posts( $author->ID ) );

		// A new post does not change the count while the cached value is live.
		$this->factory()->post->create(
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$this->assertSame( 2, count_user_posts( $author->ID ) );

		wp_cache_flush();

		$this->assertSame( 3, count_user_posts( $author->ID ) );
	}

	/**
	 * Test count for guest authors with custom post types.
	 *
	 * @covers CoAuthors_Plus::filter_count_user_posts
	 * @covers CoAuthors_Plus::get_post_count_for_author_term
	 */
	public function test_count_guest_author_posts_custom_post_type(): void {
		global $coauthors_plus;

		// Register a custom post type.
		register_post_type(
			'custom_cpt',
			array(
				'public' => true,
			)
		);

		// Create an author and guest author.
		$author          = $this->create_author();
		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Test Guest',
				'user_login'   => 'test-guest',
			)
		);

		$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'id', $guest_author_id );

		// Create posts and assign the guest author.
		$post1 = $this->factory()->post->create(
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$post2 = $this->factory()->post->create(
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
				'post_type'   => 'custom_cpt',
			)
		);

		// Assign guest author to both posts.
		$coauthors_plus->add_coauthors( $post1, array( $guest_author->user_login ), true );
		$coauthors_plus->add_coauthors( $post2, array( $guest_author->user_login ), true );

		// Get the guest author term.
		$term = $coauthors_plus->get_author_term( $guest_author );
		$this->assertInstanceOf( \WP_Term::class, $term );

		// Use the private method to count posts.
		$reflection = new \ReflectionClass( $coauthors_plus );
		$method     = $reflection->getMethod( 'get_post_count_for_author_term' );
		$method->setAccessible( true );

		// Count only 'post' type.
		$post_count = $method->invoke( $coauthors_plus, $term, 'post', false );
		$this->assertSame( 1, $post_count );

		// Count only custom post type.
		$cpt_count = $method->invoke( $coauthors_plus, $term, 'custom_cpt', false );
		$this->assertSame( 1, $cpt_count );

		// Count both types.
		$both_count = $method->invoke( $coauthors_plus, $term, array( 'post', 'custom_cpt' ), false );
		$this->assertSame( 2, $both_count );

		// Clean up.
		unregister_post_type( 'custom_cpt' );
	}

	/**
	 * When a guest author profile is linked to a WP user using that user's own
	 * nicename (the common "replace this WP user's byline with a guest profile"
	 * mapping), the guest profile resolves to the exact same coauthor term as
	 * the WP user. count_user_posts() must not add the term count on top of
	 * the raw post_author count in that case, or every post gets counted twice.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/928
	 *
	 * @covers CoAuthors_Plus::filter_count_user_posts
	 */
	public function test_count_user_posts_linked_guest_author_sharing_users_own_slug_is_not_doubled(): void {
		global $coauthors_plus;

		$author = $this->create_author( 'shared-slug-author' );

		// Posts authored directly by the WP user.
		$this->factory()->post->create_many(
			3,
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
			)
		);

		$guest_author_id = $coauthors_plus->guest_authors->create_guest_author_from_user_id( $author->ID );
		$guest_author    = $coauthors_plus->guest_authors->get_guest_author_by( 'ID', $guest_author_id );

		// Precondition for this scenario: the linked guest profile shares the
		// WP user's own nicename/term, rather than having a distinct identity.
		$this->assertSame( $author->user_nicename, $guest_author->user_nicename );

		// More posts authored via the guest byline.
		$post_ids = $this->factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );
		foreach ( $post_ids as $post_id ) {
			$coauthors_plus->add_coauthors( $post_id, array( $guest_author->user_login ), true );
		}

		$this->assertSame( 5, count_user_posts( $author->ID ) );
	}

	/**
	 * A linked guest author can have their own slug, and so their own term,
	 * because create_guest_author_from_user_id() builds it from the display
	 * name. Posts bylined to the guest author still have the user as their
	 * post_author, so adding the raw count to the term count doubles them.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/928
	 *
	 * @covers CoAuthors_Plus::filter_count_user_posts
	 */
	public function test_count_user_posts_linked_guest_author_with_own_slug_is_not_doubled(): void {
		global $coauthors_plus;

		$author       = $this->create_author_with_display_name( 'ownslug', 'Own Slug Author' );
		$guest_author = $this->create_linked_guest_author( $author );

		// Precondition for this scenario: the pair has two different terms.
		$this->assertNotSame( $author->user_nicename, $guest_author->user_nicename );

		// Posts authored directly by the WP user.
		$this->factory()->post->create_many(
			3,
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
			)
		);

		// More posts authored via the guest byline.
		$post_ids = $this->factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );
		foreach ( $post_ids as $post_id ) {
			$coauthors_plus->add_coauthors( $post_id, array( $guest_author->user_login ) );
		}

		$this->assertSame( 5, count_user_posts( $author->ID ) );
	}

	/**
	 * A post carrying both of a linked pair's terms is still only one post.
	 *
	 * @covers CoAuthors_Plus::filter_count_user_posts
	 */
	public function test_count_user_posts_counts_a_post_on_both_linked_terms_once(): void {
		global $coauthors_plus;

		$author = $this->create_author_with_display_name( 'bothterms', 'Both Terms Author' );

		// A post from before the link has the user's own term.
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
			)
		);

		// add_coauthors() treats the pair as one, so add the second term directly.
		$guest_author = $this->create_linked_guest_author( $author );
		wp_add_object_terms( $post_id, $coauthors_plus->get_author_term( $guest_author )->term_id, $coauthors_plus->coauthor_taxonomy );

		// Precondition for this scenario: the post has both of the pair's terms.
		$this->assertCount( 2, wp_get_post_terms( $post_id, $coauthors_plus->coauthor_taxonomy ) );

		$this->assertSame( 1, count_user_posts( $author->ID ) );
	}

	/**
	 * A user can be a secondary co-author on someone else's post from before
	 * they had a guest author. The post_author count doesn't include those
	 * posts and neither does the guest author's own term.
	 *
	 * @covers CoAuthors_Plus::filter_count_user_posts
	 */
	public function test_count_user_posts_includes_linked_users_own_term_when_not_post_author(): void {
		global $coauthors_plus;

		$author = $this->create_author_with_display_name( 'secondary', 'Secondary Author' );
		$other  = $this->create_author( 'other-author' );

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $other->ID,
				'post_status' => 'publish',
			)
		);
		$coauthors_plus->add_coauthors( $post_id, array( $other->user_login, $author->user_login ) );

		$guest_author = $this->create_linked_guest_author( $author );

		// Precondition for this scenario: the pair has two different terms.
		$this->assertNotSame( $author->user_nicename, $guest_author->user_nicename );

		$this->assertSame( 1, count_user_posts( $author->ID ) );
	}

	/**
	 * The link holds a login and the term uses a nicename, which can differ.
	 *
	 * @covers CoAuthors_Plus::filter_count_user_posts
	 */
	public function test_count_user_posts_linked_user_whose_login_differs_from_nicename(): void {
		global $coauthors_plus;

		$author = $this->factory()->user->create_and_get(
			array(
				'role'          => 'author',
				'user_login'    => 'jo.bloggs',
				'user_nicename' => 'jo-bloggs',
				'display_name'  => 'Josephine Bloggs',
			)
		);

		$guest_author = $this->create_linked_guest_author( $author );

		$this->factory()->post->create(
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
			)
		);

		$post_id = $this->factory()->post->create( array( 'post_status' => 'publish' ) );
		$coauthors_plus->add_coauthors( $post_id, array( $guest_author->user_login ) );

		$this->assertSame( 2, count_user_posts( $author->ID ) );
	}

	/**
	 * Creates an author whose display name differs from their login.
	 *
	 * @param string $user_login   Login for the user.
	 * @param string $display_name Display name for the user.
	 * @return \WP_User The created user.
	 */
	private function create_author_with_display_name( string $user_login, string $display_name ): \WP_User {
		return $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => $user_login,
				'display_name' => $display_name,
			)
		);
	}

	/**
	 * Creates a guest author linked to the given user.
	 *
	 * @param \WP_User $user User to link the guest author to.
	 * @return object The guest author.
	 */
	private function create_linked_guest_author( \WP_User $user ) {
		global $coauthors_plus;

		$guest_author_id = $coauthors_plus->guest_authors->create_guest_author_from_user_id( $user->ID );

		return $coauthors_plus->guest_authors->get_guest_author_by( 'ID', $guest_author_id );
	}
}
