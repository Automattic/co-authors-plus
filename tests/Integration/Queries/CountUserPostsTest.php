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
		$guest_author    = $coauthors_plus->get_coauthor_by( 'id', $guest_author_id );

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
}
