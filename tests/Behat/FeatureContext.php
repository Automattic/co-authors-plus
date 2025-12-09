<?php
/**
 * Feature tests context class with Co-Authors Plus specific steps.
 *
 * @package Automattic\CoAuthorsPlus
 */

namespace Automattic\CoAuthorsPlus\Tests\Behat;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use RuntimeException;

/**
 * Feature tests context class for wp-env based Behat testing.
 *
 * This class implements custom step definitions for testing Co-Authors Plus
 * WP-CLI commands using wp-env instead of the wp-cli/wp-cli-tests framework.
 */
final class FeatureContext implements Context {

	/**
	 * Command output (STDOUT).
	 *
	 * @var string
	 */
	private $output = '';

	/**
	 * Command error output (STDERR).
	 *
	 * @var string
	 */
	private $error_output = '';

	/**
	 * Command exit code.
	 *
	 * @var int
	 */
	private $exit_code = 0;

	/**
	 * Previous command for "run previous command again" step.
	 *
	 * @var string|null
	 */
	private $previous_command = null;

	/**
	 * Execute a WP-CLI command inside wp-env tests container.
	 *
	 * @param string $command     The WP-CLI command to execute (without 'wp' prefix).
	 * @param bool   $should_fail Whether the command is expected to fail.
	 * @return void
	 */
	private function run_wp_cli_command( $command, $should_fail = false ): void {
		// Escape command for shell execution.
		$escaped_command = str_replace( "'", "'\\''", $command );

		// Run inside wp-env tests-cli container.
		$exec_command = sprintf(
			"wp-env run tests-cli --env-cwd=wp-content/plugins/co-authors-plus bash -c 'wp %s 2>&1'",
			$escaped_command
		);

		exec( $exec_command, $output_lines, $exit_code );

		// Filter out wp-env status messages.
		$filtered_lines = array_filter(
			$output_lines,
			function ( $line ) use ( $output_lines ) {
				// Remove wp-env status lines.
				return ! ( 0 === strpos( $line, 'ℹ ' ) ||
						0 === strpos( $line, '✔ ' ) ||
						0 === strpos( $line, '✖ ' ) ||
						'' === trim( $line ) && count( $output_lines ) > 1 );
			}
		);

		$output              = implode( "\n", $filtered_lines );
		$this->output        = $output;
		$this->error_output  = '';
		$this->exit_code     = $exit_code;

		// Parse STDERR from combined output.
		// WP-CLI prefixes errors with "Error:" typically.
		if ( 0 !== $exit_code || $should_fail ) {
			// Extract error lines and their continuation lines.
			$error_lines   = array();
			$in_error_block = false;

			foreach ( $filtered_lines as $line ) {
				// Check if this line starts an error block.
				if ( 0 === strpos( $line, 'Error:' ) || 0 === strpos( $line, 'Warning:' ) ) {
					$error_lines[] = $line;
					$in_error_block = true;
				} elseif ( $in_error_block && ( 0 === strpos( $line, ' ' ) || 0 === strpos( $line, "\t" ) ) ) {
					// Continuation line (indented).
					$error_lines[] = $line;
				} else {
					// Not an error line, end the error block.
					$in_error_block = false;
				}
			}

			if ( ! empty( $error_lines ) ) {
				$this->error_output = implode( "\n", $error_lines );
				// Remove error lines from output.
				$non_error_lines = array_diff( $filtered_lines, $error_lines );
				$this->output    = implode( "\n", $non_error_lines );
			}
		}
	}

	/**
	 * Reset database state between scenarios.
	 *
	 * This ensures test isolation without recreating WordPress.
	 *
	 * @return void
	 */
	private function reset_database_state(): void {
		// Delete all posts except defaults.
		// Using post list to get IDs, then delete them.
		$this->run_wp_cli_command( 'post list --post_type=any --format=ids', false );
		$post_ids = trim( $this->output );

		if ( ! empty( $post_ids ) ) {
			$this->run_wp_cli_command( "post delete {$post_ids} --force", false );
		}

		// Delete guest author posts (custom post type).
		$this->run_wp_cli_command( 'post list --post_type=guest-author --format=ids', false );
		$guest_author_ids = trim( $this->output );

		if ( ! empty( $guest_author_ids ) ) {
			$this->run_wp_cli_command( "post delete {$guest_author_ids} --force", false );
		}

		// Delete all users except admin (ID 1).
		$this->run_wp_cli_command( 'user list --field=ID', false );
		$user_ids = array_filter(
			explode( "\n", trim( $this->output ) ),
			function ( $id ) {
				return '1' !== trim( $id ) && ! empty( trim( $id ) );
			}
		);

		if ( ! empty( $user_ids ) ) {
			foreach ( $user_ids as $user_id ) {
				$this->run_wp_cli_command( "user delete {$user_id} --yes", false );
			}
		}

		// Delete all terms in author taxonomy.
		$this->run_wp_cli_command( 'term list author --field=term_id', false );
		$term_ids = array_filter(
			explode( "\n", trim( $this->output ) ),
			function ( $id ) {
				return ! empty( trim( $id ) );
			}
		);

		if ( ! empty( $term_ids ) ) {
			foreach ( $term_ids as $term_id ) {
				$this->run_wp_cli_command( "term delete author {$term_id}", false );
			}
		}

		// Clean transients.
		$this->run_wp_cli_command( 'transient delete --all', false );

		// Flush cache.
		$this->run_wp_cli_command( 'cache flush', false );
	}

	/**
	 * Set up clean state before each scenario.
	 *
	 * @BeforeScenario
	 * @param BeforeScenarioScope $scope Scenario scope.
	 * @return void
	 */
	public function before_scenario( BeforeScenarioScope $scope ): void {
		// Clean state will be set up by Given steps.
		// This hook is here for future extensibility.
	}

	/**
	 * Clean up after each scenario.
	 *
	 * @AfterScenario
	 * @param AfterScenarioScope $scope Scenario scope.
	 * @return void
	 */
	public function after_scenario( AfterScenarioScope $scope ): void {
		// Clean up database state after scenario.
		$this->reset_database_state();
	}

	/**
	 * Set up a basic WP installation.
	 *
	 * @Given a WP install
	 * @Given a WP installation
	 * @return void
	 */
	public function given_a_wp_installation(): void {
		// wp-env is already running with WordPress installed.
		// Just ensure we have a clean database state.
		$this->reset_database_state();
	}

	/**
	 * Set up a WP installation with Co-Authors Plus plugin activated.
	 *
	 * @Given a WP install(ation) with the Co-Authors Plus plugin
	 * @return void
	 */
	public function given_a_wp_installation_with_the_cap_plugin(): void {
		// Plugin is already loaded via .wp-env.json.
		// Reset database and ensure plugin is activated.
		$this->reset_database_state();
		$this->run_wp_cli_command( 'plugin activate co-authors-plus', false );

		if ( 0 !== $this->exit_code ) {
			throw new RuntimeException(
				'Failed to activate Co-Authors Plus plugin: ' . $this->output
			);
		}
	}

	/**
	 * Create a published post with a specific slug.
	 *
	 * @Given there is a published post with a slug of :post_name
	 * @param string $post_name Post slug to use.
	 * @return void
	 */
	public function there_is_a_published_post( $post_name ): void {
		$command = sprintf(
			"post create --post_title='%s' --post_name='%s' --post_status='publish'",
			$post_name,
			$post_name
		);
		$this->run_wp_cli_command( $command, false );

		if ( 0 !== $this->exit_code ) {
			throw new RuntimeException(
				'Failed to create post: ' . $this->output
			);
		}
	}

	/**
	 * Run a WP-CLI command that is expected to succeed.
	 *
	 * @When I run :command
	 * @When /^I run `([^`]+)`$/
	 * @param string $command The command to run.
	 * @return void
	 */
	public function i_run( $command ): void {
		// Remove backticks if present.
		$command = trim( $command, '`' );

		// Remove 'wp ' prefix if present (we add it in run_wp_cli_command).
		if ( 0 === strpos( $command, 'wp ' ) ) {
			$command = substr( $command, 3 );
		}

		$this->previous_command = $command;
		$this->run_wp_cli_command( $command, false );
	}

	/**
	 * Run a WP-CLI command that may fail.
	 *
	 * @When I try :command
	 * @When /^I try `([^`]+)`$/
	 * @param string $command The command to try.
	 * @return void
	 */
	public function i_try( $command ): void {
		// Remove backticks if present.
		$command = trim( $command, '`' );

		// Remove 'wp ' prefix if present.
		if ( 0 === strpos( $command, 'wp ' ) ) {
			$command = substr( $command, 3 );
		}

		$this->previous_command = $command;
		$this->run_wp_cli_command( $command, true );
	}

	/**
	 * Run the previous command again.
	 *
	 * @When /^I (run|try) the previous command again$/
	 * @param string $action Either 'run' or 'try'.
	 * @return void
	 */
	public function i_run_the_previous_command_again( $action ): void {
		if ( empty( $this->previous_command ) ) {
			throw new RuntimeException( 'No previous command to run' );
		}

		if ( 'run' === $action ) {
			$this->i_run( $this->previous_command );
		} else {
			$this->i_try( $this->previous_command );
		}
	}

	/**
	 * Assert that STDOUT exactly matches expected output.
	 *
	 * @Then STDOUT should be:
	 * @param PyStringNode $expected Expected output.
	 * @return void
	 */
	public function stdout_should_be( PyStringNode $expected ): void {
		$actual        = trim( $this->output );
		$expected_text = trim( $expected->getRaw() );

		if ( $actual !== $expected_text ) {
			throw new RuntimeException(
				sprintf(
					"STDOUT does not match.\nExpected:\n%s\n\nActual:\n%s",
					$expected_text,
					$actual
				)
			);
		}
	}

	/**
	 * Assert that STDOUT contains expected text.
	 *
	 * @Then STDOUT should contain:
	 * @param PyStringNode $expected Expected text to find.
	 * @return void
	 */
	public function stdout_should_contain( PyStringNode $expected ): void {
		$actual        = trim( $this->output );
		$expected_text = trim( $expected->getRaw() );

		if ( false === strpos( $actual, $expected_text ) ) {
			throw new RuntimeException(
				sprintf(
					"STDOUT does not contain expected text.\nExpected to find:\n%s\n\nActual output:\n%s",
					$expected_text,
					$actual
				)
			);
		}
	}

	/**
	 * Assert that STDERR exactly matches expected output.
	 *
	 * @Then STDERR should be:
	 * @param PyStringNode $expected Expected error output.
	 * @return void
	 */
	public function stderr_should_be( PyStringNode $expected ): void {
		$actual        = trim( $this->error_output );
		$expected_text = trim( $expected->getRaw() );

		if ( $actual !== $expected_text ) {
			throw new RuntimeException(
				sprintf(
					"STDERR does not match.\nExpected:\n%s\n\nActual:\n%s",
					$expected_text,
					$actual
				)
			);
		}
	}
}
