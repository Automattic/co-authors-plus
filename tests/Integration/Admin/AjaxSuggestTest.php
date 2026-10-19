<?php
/**
 * Tests for the classic editor search-as-you-type AJAX handler.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Admin;

use WPAjaxDieContinueException;

/**
 * @covers \CoAuthors_Plus::ajax_suggest
 */
class AjaxSuggestTest extends \WP_Ajax_UnitTestCase {

	/**
	 * @var \WP_User
	 */
	private $author;

	public function set_up() {
		parent::set_up();

		$this->author = self::factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => 'suggestauthor',
				'display_name' => 'Suggest Author',
				'user_email'   => 'suggest@example.com',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * Run the AJAX handler and return the decoded results.
	 *
	 * @return array
	 */
	private function get_suggestions(): array {
		// _handleAjax() rebuilds $_REQUEST from $_POST and $_GET.
		$_POST['_wpnonce'] = wp_create_nonce( 'coauthors-search' );
		$_POST['term']     = 'suggestauthor';

		try {
			$this->_handleAjax( 'coauthors_ajax_suggest' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		return json_decode( $this->_last_response, true );
	}

	public function test_results_include_email_by_default(): void {
		$results = $this->get_suggestions();

		$this->assertCount( 1, $results );
		$this->assertSame( 'suggest@example.com', $results[0]['email'] );
	}

	public function test_filter_can_remove_email(): void {
		add_filter(
			'coauthors_ajax_suggest_author_data',
			static function ( $result ) {
				unset( $result['email'] );
				return $result;
			}
		);

		$results = $this->get_suggestions();

		$this->assertArrayNotHasKey( 'email', $results[0] );
		$this->assertSame( 'Suggest Author', $results[0]['label'] );
	}

	public function test_filter_cannot_change_nicename(): void {
		add_filter(
			'coauthors_ajax_suggest_author_data',
			static function ( $result ) {
				$result['nicename'] = 'someone-else';
				return $result;
			}
		);

		$results = $this->get_suggestions();

		$this->assertSame( $this->author->user_nicename, $results[0]['nicename'] );
	}
}
