<?php
/**
 * Tests for the guest-author "Link user" dropdown.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration;

/**
 * Tests for the "Link user" dropdown rendered by
 * {@see CoAuthors_Guest_Authors::metabox_manage_guest_author_slug()}.
 *
 * On sites with very large user bases, the dropdown can become unusable when
 * it tries to enumerate every WP user, including Subscribers. The metabox
 * passes a `capability` arg to `wp_dropdown_users()` so only users who can
 * write posts are listed, mirroring the existing `coauthors_edit_author_cap`
 * filter used by the AJAX co-author search and validation paths.
 */
class GuestAuthorLinkedAccountDropdownTest extends TestCase {

	/**
	 * @var \stdClass
	 */
	private $guest_author;

	public function set_up() {
		parent::set_up();

		global $coauthors_plus, $post;

		$guest_author_id = $this->create_guest_author( 'gadropdown' );
		$post            = get_post( $guest_author_id );
		$this->guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'ID', $guest_author_id );
	}

	public function tear_down() {
		global $post;
		$post = null;
		parent::tear_down();
	}

	/**
	 * The dropdown should request only users with the edit_posts capability
	 * by default, so that Subscribers and roles without authoring rights are
	 * not enumerated.
	 *
	 * @covers CoAuthors_Guest_Authors::metabox_manage_guest_author_slug
	 */
	public function test_dropdown_args_include_edit_posts_capability_by_default(): void {
		global $coauthors_plus;

		$captured_args = $this->capture_dropdown_args(
			static function () use ( $coauthors_plus ): void {
				$coauthors_plus->guest_authors->metabox_manage_guest_author_slug();
			}
		);

		$this->assertIsArray( $captured_args, 'The linked-account args filter should run when the metabox renders.' );
		$this->assertArrayHasKey(
			'capability',
			$captured_args,
			'The dropdown args should include a capability constraint so Subscribers are excluded.'
		);
		$this->assertSame(
			array( 'edit_posts' ),
			$captured_args['capability'],
			'The default capability constraint should be edit_posts.'
		);
	}

	/**
	 * Sites that legitimately want a different role (e.g. linking Subscribers)
	 * already filter coauthors_edit_author_cap for the AJAX co-author search.
	 * The dropdown should honour the same filter so the site-level override
	 * keeps both surfaces consistent.
	 *
	 * @covers CoAuthors_Guest_Authors::metabox_manage_guest_author_slug
	 */
	public function test_dropdown_args_capability_honours_coauthors_edit_author_cap_filter(): void {
		global $coauthors_plus;

		add_filter( 'coauthors_edit_author_cap', static fn() => 'read' );

		$captured_args = $this->capture_dropdown_args(
			static function () use ( $coauthors_plus ): void {
				$coauthors_plus->guest_authors->metabox_manage_guest_author_slug();
			}
		);

		$this->assertSame(
			array( 'read' ),
			$captured_args['capability'],
			'The dropdown should pass the capability returned by coauthors_edit_author_cap to wp_dropdown_users.'
		);
	}

	/**
	 * Behavioural check: Subscribers must not appear as options in the rendered
	 * dropdown HTML, while users with edit_posts (e.g. Authors) must.
	 *
	 * This is the user-visible outcome of the capability constraint and guards
	 * against future changes that pass the right args but bypass the dropdown.
	 *
	 * @covers CoAuthors_Guest_Authors::metabox_manage_guest_author_slug
	 */
	public function test_dropdown_html_excludes_subscribers_and_includes_authoring_users(): void {
		global $coauthors_plus;

		$subscriber = $this->create_subscriber( 'sub_should_be_hidden' );
		$author     = $this->create_author( 'author_should_be_listed' );

		ob_start();
		$coauthors_plus->guest_authors->metabox_manage_guest_author_slug();
		$html = ob_get_clean();

		$this->assertStringNotContainsString(
			'>' . $subscriber->user_login . '</option>',
			$html,
			'The dropdown must not list Subscriber accounts.'
		);
		$this->assertStringContainsString(
			'>' . $author->user_login . '</option>',
			$html,
			'The dropdown should list users who can write posts.'
		);
	}

	/**
	 * Run a callable while capturing the args passed through the
	 * `coauthors_guest_author_linked_account_args` filter. Returns the captured
	 * args, or null if the filter never ran.
	 */
	private function capture_dropdown_args( callable $callable ): ?array {
		$captured = null;

		$capture = static function ( $args ) use ( &$captured ) {
			$captured = $args;
			return $args;
		};
		add_filter( 'coauthors_guest_author_linked_account_args', $capture );

		ob_start();
		try {
			$callable();
		} finally {
			ob_end_clean();
			remove_filter( 'coauthors_guest_author_linked_account_args', $capture );
		}

		return $captured;
	}
}
