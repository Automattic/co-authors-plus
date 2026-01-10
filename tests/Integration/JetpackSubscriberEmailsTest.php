<?php
/**
 * Tests for Jetpack Subscriber Emails integration.
 *
 * @package CoAuthors
 */

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use Automattic\CoAuthorsPlus\Integrations\Jetpack_Subscriber_Emails;

/**
 * Tests for the Jetpack Subscriber Emails integration.
 *
 * @covers \Automattic\CoAuthorsPlus\Integrations\Jetpack_Subscriber_Emails
 */
class JetpackSubscriberEmailsTest extends TestCase {

	/**
	 * The integration instance.
	 *
	 * @var Jetpack_Subscriber_Emails
	 */
	private $integration;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->integration = new Jetpack_Subscriber_Emails();
	}

	/**
	 * Test that published post flags are modified for posts with co-authors.
	 *
	 * @covers \Automattic\CoAuthorsPlus\Integrations\Jetpack_Subscriber_Emails::filter_published_post_flags
	 */
	public function test_filter_published_post_flags_with_coauthor(): void {
		global $coauthors_plus;

		$author = $this->create_author( 'test-author' );
		$post   = $this->create_post( $author );

		// Assign the author as a co-author.
		$coauthors_plus->add_coauthors( $post->ID, array( $author->user_login ) );

		$flags = array(
			'post_type' => 'post',
			'author'    => array(
				'id'           => 999, // Different from actual author.
				'display_name' => 'Wrong Name',
				'email'        => 'wrong@example.com',
			),
		);

		$result = $this->integration->filter_published_post_flags( $flags, $post );

		$this->assertSame( $author->ID, $result['author']['id'] );
		$this->assertSame( $author->display_name, $result['author']['display_name'] );
		$this->assertSame( $author->user_email, $result['author']['email'] );
		$this->assertArrayHasKey( 'coauthors', $result );
		$this->assertCount( 1, $result['coauthors'] );
	}

	/**
	 * Test that published post flags include guest author information.
	 *
	 * @covers \Automattic\CoAuthorsPlus\Integrations\Jetpack_Subscriber_Emails::filter_published_post_flags
	 */
	public function test_filter_published_post_flags_with_guest_author(): void {
		global $coauthors_plus;

		$author          = $this->create_author( 'test-author' );
		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Guest Writer',
				'user_login'   => 'guest-writer',
				'user_email'   => 'guest@example.com',
			)
		);
		$guest_author    = $coauthors_plus->guest_authors->get_guest_author_by( 'id', $guest_author_id );

		$post = $this->create_post( $author );

		// Replace author with guest author.
		$coauthors_plus->add_coauthors( $post->ID, array( $guest_author->user_login ), false );

		$flags = array(
			'post_type' => 'post',
			'author'    => array(
				'id'           => $author->ID,
				'display_name' => $author->display_name,
				'email'        => $author->user_email,
			),
		);

		$result = $this->integration->filter_published_post_flags( $flags, $post );

		$this->assertSame( 'Guest Writer', $result['author']['display_name'] );
		$this->assertSame( 'guest@example.com', $result['author']['email'] );
		$this->assertSame( 'guest-author', $result['author']['type'] );
	}

	/**
	 * Test that published post flags include multiple co-authors.
	 *
	 * @covers \Automattic\CoAuthorsPlus\Integrations\Jetpack_Subscriber_Emails::filter_published_post_flags
	 */
	public function test_filter_published_post_flags_with_multiple_coauthors(): void {
		global $coauthors_plus;

		$author1 = $this->create_author( 'author-one' );
		$author2 = $this->create_author( 'author-two' );
		$post    = $this->create_post( $author1 );

		// Add both as co-authors.
		$coauthors_plus->add_coauthors( $post->ID, array( $author1->user_login, $author2->user_login ) );

		$flags = array(
			'post_type' => 'post',
			'author'    => array(),
		);

		$result = $this->integration->filter_published_post_flags( $flags, $post );

		// Primary author should be the first one.
		$this->assertSame( $author1->display_name, $result['author']['display_name'] );

		// All co-authors should be included.
		$this->assertArrayHasKey( 'coauthors', $result );
		$this->assertCount( 2, $result['coauthors'] );
		$this->assertSame( $author1->display_name, $result['coauthors'][0]['display_name'] );
		$this->assertSame( $author2->display_name, $result['coauthors'][1]['display_name'] );
	}

	/**
	 * Test that flags are returned unchanged for unsupported post types.
	 *
	 * @covers \Automattic\CoAuthorsPlus\Integrations\Jetpack_Subscriber_Emails::filter_published_post_flags
	 */
	public function test_filter_published_post_flags_unsupported_post_type(): void {
		register_post_type( 'unsupported_type', array( 'public' => true ) );

		$author  = $this->create_author( 'test-author' );
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
				'post_type'   => 'unsupported_type',
			)
		);
		$post    = get_post( $post_id );

		$flags = array(
			'post_type' => 'unsupported_type',
			'author'    => array(
				'id'           => 999,
				'display_name' => 'Original',
			),
		);

		$result = $this->integration->filter_published_post_flags( $flags, $post );

		// Flags should be unchanged.
		$this->assertSame( 999, $result['author']['id'] );
		$this->assertSame( 'Original', $result['author']['display_name'] );
		$this->assertArrayNotHasKey( 'coauthors', $result );

		unregister_post_type( 'unsupported_type' );
	}

	/**
	 * Test that flags use post_author when no co-author terms exist.
	 *
	 * When no co-author terms exist, get_coauthors() falls back to post_author,
	 * so the integration should still provide the correct author data.
	 *
	 * @covers \Automattic\CoAuthorsPlus\Integrations\Jetpack_Subscriber_Emails::filter_published_post_flags
	 */
	public function test_filter_published_post_flags_no_coauthor_terms(): void {
		$author = $this->create_author( 'test-author' );
		$post   = $this->create_post( $author );

		// Remove all co-author terms - get_coauthors() will fall back to post_author.
		wp_delete_object_term_relationships( $post->ID, 'author' );

		$flags = array(
			'post_type' => 'post',
			'author'    => array(
				'id'           => 999,
				'display_name' => 'Original',
			),
		);

		$result = $this->integration->filter_published_post_flags( $flags, $post );

		// Even without terms, get_coauthors falls back to post_author.
		$this->assertSame( $author->ID, $result['author']['id'] );
		$this->assertSame( $author->display_name, $result['author']['display_name'] );
	}
}
