<?php
/**
 * Tests for when the author taxonomy is registered.
 *
 * Covers issue #117: the taxonomy used to exist only from `init` priority 100,
 * so code hooked to `init` before that could not read or write co-authors.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Plus
 */
class AuthorTaxonomyRegistrationTest extends TestCase {

	private const LATE_POST_TYPE = 'cap_late_cpt';

	/**
	 * The author taxonomy object as registered at bootstrap.
	 *
	 * @var \WP_Taxonomy
	 */
	private $taxonomy_backup;

	public function set_up() {
		parent::set_up();

		$this->taxonomy_backup = get_taxonomy( 'author' );
	}

	public function tear_down() {
		global $wp_taxonomies;

		// The test suite restores hooks between tests, but not taxonomies.
		$wp_taxonomies['author'] = $this->taxonomy_backup;
		unregister_post_type( self::LATE_POST_TYPE );

		parent::tear_down();
	}

	/**
	 * The taxonomy is registered on `init` priority 0, and again at priority 100.
	 *
	 * @covers ::register_hooks
	 */
	public function test_taxonomy_is_registered_early_and_late_on_init(): void {
		$this->assertSame( 0, has_action( 'init', array( $this->_cap, 'register_author_taxonomy' ) ) );
		$this->assertSame( 100, has_action( 'init', array( $this->_cap, 'action_init_late' ) ) );
	}

	/**
	 * The early registration makes the taxonomy usable for the built-in post types.
	 *
	 * @covers ::register_author_taxonomy
	 */
	public function test_early_registration_covers_built_in_post_types(): void {
		unregister_taxonomy( 'author' );
		$this->assertFalse( taxonomy_exists( 'author' ) );

		$this->_cap->register_author_taxonomy();

		$this->assertTrue( taxonomy_exists( 'author' ) );
		$this->assertTrue( is_object_in_taxonomy( 'post', 'author' ) );
		$this->assertTrue( is_object_in_taxonomy( 'page', 'author' ) );

		$author  = $this->create_author( 'early_author' );
		$post_id = $this->create_post( $author )->ID;
		$this->_cap->add_coauthors( $post_id, array( $author->user_login ) );

		$this->assertSame( array( $author->ID ), wp_list_pluck( get_coauthors( $post_id ), 'ID' ) );
	}

	/**
	 * A post type registered after the early registration is attached by
	 * action_init_late().
	 *
	 * @covers ::action_init_late
	 */
	public function test_late_registration_attaches_post_types_registered_after_early_call(): void {
		$cap = new \CoAuthors_Plus();

		unregister_taxonomy( 'author' );
		$cap->register_author_taxonomy();

		register_post_type(
			self::LATE_POST_TYPE,
			array(
				'supports' => array( 'title', 'author' ),
			)
		);

		$this->assertFalse( is_object_in_taxonomy( self::LATE_POST_TYPE, 'author' ) );

		// wp_loaded has already fired here, so drop the memo that a real call at
		// `init` priority 0 would never have stored.
		$cap->supported_post_types = array();

		$cap->action_init_late();

		$this->assertTrue( is_object_in_taxonomy( self::LATE_POST_TYPE, 'author' ) );
		$this->assertTrue( is_object_in_taxonomy( 'post', 'author' ) );

		// Byline order depends on these args, so they must survive the second registration.
		$taxonomy = get_taxonomy( 'author' );
		$this->assertTrue( $taxonomy->sort );
		$this->assertSame( array( 'orderby' => 'term_order' ), $taxonomy->args );
	}

	/**
	 * When `coauthors_plus_should_query_post_author` turns true between the early
	 * and the late call, the cache flush hook from the early call is removed, so
	 * only the count callback is left, as before.
	 *
	 * @covers ::register_author_taxonomy
	 */
	public function test_late_registration_follows_should_query_post_author_filter(): void {
		$cap      = new \CoAuthors_Plus();
		$callback = array( $cap, 'action_edited_term_taxonomy_flush_cache' );

		add_filter( 'coauthors_plus_should_query_post_author', '__return_false' );
		$cap->register_author_taxonomy();
		$this->assertSame( 10, has_action( 'edited_term_taxonomy', $callback ) );

		remove_filter( 'coauthors_plus_should_query_post_author', '__return_false' );
		$cap->register_author_taxonomy();
		$this->assertFalse( has_action( 'edited_term_taxonomy', $callback ) );
		$this->assertSame( array( $cap, '_update_users_posts_count' ), get_taxonomy( 'author' )->update_count_callback );
	}
}
