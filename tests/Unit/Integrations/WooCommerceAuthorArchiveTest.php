<?php
/**
 * Unit tests for the WooCommerce customer author-archive redirect.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Integrations;

use Automattic\CoAuthorsPlus\Integrations\WooCommerce;
use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/wp-stubs.php';
require_once dirname( __DIR__, 3 ) . '/php/integrations/class-woocommerce.php';

/**
 * Covers the decision to unhook WooCommerce's customer author redirect.
 *
 * @covers \Automattic\CoAuthorsPlus\Integrations\WooCommerce
 */
final class WooCommerceAuthorArchiveTest extends TestCase {

	/**
	 * Drop the author globals between tests.
	 */
	protected function tear_down(): void {
		unset( $GLOBALS['author'], $GLOBALS['coauthors_plus'] );
		parent::tear_down();
	}

	/**
	 * The redirect check is registered ahead of WooCommerce's callback.
	 */
	public function test_register_hooks_runs_before_woocommerce_redirect(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'template_redirect', \Mockery::type( 'array' ), 9 );

		( new WooCommerce() )->register_hooks();
	}

	/**
	 * Requests that are not author archives do not touch WooCommerce.
	 */
	public function test_non_author_request_leaves_redirect_in_place(): void {
		Functions\when( 'is_author' )->justReturn( false );
		Functions\expect( 'remove_action' )->never();
		Functions\expect( 'get_user_by' )->never();

		( new WooCommerce() )->allow_customer_author_archive();
	}

	/**
	 * A user who is not a WooCommerce customer is not redirected by WooCommerce.
	 */
	public function test_user_without_customer_capability_keeps_redirect(): void {
		$user              = $this->author_user();
		$GLOBALS['author'] = $user->ID;

		Functions\when( 'is_author' )->justReturn( true );
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\expect( 'remove_action' )->never();

		( new WooCommerce() )->allow_customer_author_archive();
	}

	/**
	 * A customer with no published posts still goes to the shop.
	 */
	public function test_customer_without_posts_keeps_shop_redirect(): void {
		$this->bind_customer( $this->author_user() );
		$this->bind_plugin( 0, null );
		Functions\expect( 'remove_action' )->never();

		( new WooCommerce() )->allow_customer_author_archive();
	}

	/**
	 * A customer who can edit posts is not someone WooCommerce redirects.
	 */
	public function test_customer_who_can_edit_posts_is_left_alone(): void {
		$this->bind_customer( $this->author_user(), true );
		Functions\expect( 'remove_action' )->never();

		( new WooCommerce() )->allow_customer_author_archive();
	}

	/**
	 * Published co-author posts keep the author archive open.
	 */
	public function test_customer_with_published_posts_keeps_author_archive(): void {
		$this->bind_customer( $this->author_user() );
		$this->bind_plugin( 2, null );
		$this->expect_redirect_removed();

		( new WooCommerce() )->allow_customer_author_archive();
	}

	/**
	 * Posts on a linked guest author count when the user term itself is empty.
	 */
	public function test_linked_guest_with_published_posts_keeps_author_archive(): void {
		$guest       = new \stdClass();
		$guest->type = 'guest-author';

		$this->bind_customer( $this->author_user() );
		$this->bind_plugin( 0, $guest, 3 );
		$this->expect_redirect_removed();

		( new WooCommerce() )->allow_customer_author_archive();
	}

	/**
	 * The filter can keep the shop redirect even when the customer has posts.
	 */
	public function test_filter_can_keep_shop_redirect(): void {
		$this->bind_customer( $this->author_user(), false, false );
		$this->bind_plugin( 4, null );
		Functions\expect( 'remove_action' )->never();

		( new WooCommerce() )->allow_customer_author_archive();
	}

	/**
	 * The filter can open the archive for a customer with no posts.
	 */
	public function test_filter_can_open_archive_without_posts(): void {
		$this->bind_customer( $this->author_user(), false, true );
		$this->bind_plugin( 0, null );
		$this->expect_redirect_removed();

		( new WooCommerce() )->allow_customer_author_archive();
	}

	/**
	 * Build a customer user.
	 *
	 * Declared properties avoid PHP 8.2 dynamic property deprecations on the empty WP_User stub.
	 *
	 * @return \WP_User
	 */
	private function author_user(): \WP_User {
		$user = new class() extends \WP_User {
			/**
			 * User ID.
			 *
			 * @var int
			 */
			public $ID = 0;

			/**
			 * User login.
			 *
			 * @var string
			 */
			public $user_login = '';
		};

		$user->ID         = 15;
		$user->user_login = 'jane';

		return $user;
	}

	/**
	 * Stub the author query as a customer, optionally one who can edit posts.
	 *
	 * @param \WP_User  $user          Archive user.
	 * @param bool      $edit_posts    Whether the user can edit posts.
	 * @param bool|null $filter_return Forced filter result. Null uses the computed value.
	 */
	private function bind_customer( \WP_User $user, bool $edit_posts = false, $filter_return = null ): void {
		$GLOBALS['author'] = $user->ID;

		Functions\when( 'is_author' )->justReturn( true );
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'user_can' )->alias(
			static function ( $account, $cap ) use ( $edit_posts ) {
				unset( $account );
				if ( 'edit_posts' === $cap ) {
					return $edit_posts;
				}

				return 'customer' === $cap;
			}
		);

		if ( null === $filter_return ) {
			Functions\when( 'apply_filters' )->returnArg( 2 );
		} else {
			Functions\when( 'apply_filters' )->justReturn( $filter_return );
		}
	}

	/**
	 * Stub the plugin global with author-term counts.
	 *
	 * @param int         $user_count  Published count on the user term.
	 * @param object|null $guest       Linked guest author, or null.
	 * @param int         $guest_count Published count on the guest term.
	 */
	private function bind_plugin( int $user_count, $guest, int $guest_count = 0 ): void {
		$user_term        = new \stdClass();
		$user_term->count = $user_count;

		$guest_term        = new \stdClass();
		$guest_term->count = $guest_count;

		$guest_authors = new class( $guest ) {
			/**
			 * Linked guest author, if any.
			 *
			 * @var object|null
			 */
			private $guest;

			/**
			 * @param object|null $guest Linked guest author.
			 */
			public function __construct( $guest ) {
				$this->guest = $guest;
			}

			/**
			 * @param string $key   Lookup key.
			 * @param string $value Lookup value.
			 * @return object|false
			 */
			public function get_guest_author_by( $key, $value ) {
				unset( $key, $value );
				return $this->guest ? $this->guest : false;
			}
		};

		$GLOBALS['coauthors_plus'] = new class( $user_term, $guest_term, $guest_authors ) {
			/**
			 * Guest author storage.
			 *
			 * @var object
			 */
			public $guest_authors;

			/**
			 * User author term.
			 *
			 * @var object
			 */
			private $user_term;

			/**
			 * Guest author term.
			 *
			 * @var object
			 */
			private $guest_term;

			/**
			 * @param object $user_term      User author term.
			 * @param object $guest_term     Guest author term.
			 * @param object $guest_authors  Guest author lookup.
			 */
			public function __construct( $user_term, $guest_term, $guest_authors ) {
				$this->user_term      = $user_term;
				$this->guest_term     = $guest_term;
				$this->guest_authors  = $guest_authors;
			}

			/**
			 * @return bool
			 */
			public function is_guest_authors_enabled(): bool {
				return true;
			}

			/**
			 * @param object $coauthor User or guest author.
			 * @return object
			 */
			public function get_author_term( $coauthor ) {
				if ( isset( $coauthor->type ) && 'guest-author' === $coauthor->type ) {
					return $this->guest_term;
				}

				return $this->user_term;
			}
		};
	}

	/**
	 * Expect WooCommerce's redirect callback to be removed at priority 10.
	 */
	private function expect_redirect_removed(): void {
		Functions\expect( 'remove_action' )
			->once()
			->with( 'template_redirect', 'wc_disable_author_archives_for_customers', 10 );
	}
}
