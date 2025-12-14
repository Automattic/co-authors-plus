<?php
/**
 * Feature tests context class with Co-Authors Plus specific steps.
 *
 * @package Automattic\CoAuthorsPlus
 */

namespace Automattic\CoAuthorsPlus\Tests\Behat;

use Automattic\BehatWpEnv\WpEnvFeatureContext;
use RuntimeException;

/**
 * Feature tests context class for Co-Authors Plus.
 *
 * Extends the shared WpEnvFeatureContext to provide plugin-specific
 * step definitions and cleanup logic.
 */
final class FeatureContext extends WpEnvFeatureContext {

	/**
	 * Get the plugin slug for Co-Authors Plus.
	 *
	 * @return string Plugin slug.
	 */
	protected function get_plugin_slug(): string {
		return 'co-authors-plus';
	}

	/**
	 * Clean up plugin-specific data between scenarios.
	 *
	 * Removes all guest-author posts created during tests.
	 *
	 * @return void
	 */
	protected function plugin_specific_cleanup(): void {
		// Delete guest authors.
		$this->run_wp_cli_command( 'post list --post_type=guest-author --format=ids', false );
		$ids = trim( $this->output );
		if ( ! empty( $ids ) ) {
			$this->run_wp_cli_command( "post delete {$ids} --force", false );
		}
	}

	/**
	 * Set up a clean WordPress installation with Co-Authors Plus activated.
	 *
	 * @Given a WP installation with the Co-Authors Plus plugin
	 * @return void
	 */
	public function given_a_wp_installation_with_plugin(): void {
		$this->reset_database_state();
		$this->run_wp_cli_command( 'plugin activate co-authors-plus', false );

		if ( 0 !== $this->exit_code ) {
			throw new RuntimeException( 'Failed to activate plugin: ' . $this->output );
		}
	}

	/**
	 * Create a published post with a specific slug.
	 *
	 * @Given there is a published post with a slug of :post_name
	 * @param string $post_name Post slug.
	 * @return void
	 */
	public function there_is_a_published_post_with_slug( string $post_name ): void {
		$this->run_wp_cli_command(
			"post create --post_name={$post_name} --post_status=publish --porcelain",
			false
		);
	}
}
