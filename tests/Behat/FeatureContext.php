<?php
/**
 * Feature tests context class with Co-Authors Plus specific steps.
 *
 * @package Automattic\CoAuthorsPlus
 */

namespace Automattic\CoAuthorsPlus\Tests\Behat;

use Automattic\BehatWpEnv\WpEnvFeatureContext;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use RuntimeException;

/**
 * Feature tests context class for Co-Authors Plus.
 *
 * Extends the shared WpEnvFeatureContext to provide plugin-specific step
 * definitions and cleanup logic.
 *
 * Two performance overrides live here: run_wp_cli_command() and
 * reset_database_state(). Between them they cut suite runtime by roughly 5x.
 * See the docblock on each for the reasoning. Both are candidates for
 * upstreaming into automattic/behat-wp-env-context, which would benefit every
 * plugin using it; they live here for now so this repository gets the win
 * without waiting on a package release.
 */
final class FeatureContext extends WpEnvFeatureContext {

	/**
	 * Absolute path inside the container to the plugin directory.
	 *
	 * @var string
	 */
	private const CONTAINER_PLUGIN_PATH = '/var/www/html/wp-content/plugins/';

	/**
	 * Resolved tests-cli container name, or empty string when unavailable.
	 *
	 * Null means "not yet looked up". Empty string means "looked up and not
	 * found", so we do not repeat a failing lookup for every step.
	 *
	 * @var string|null
	 */
	private static $cli_container = null;

	/**
	 * Whether the plugin has been activated during this run.
	 *
	 * @var bool
	 */
	private static $plugin_activated = false;

	/**
	 * Get the plugin slug for Co-Authors Plus.
	 *
	 * @return string Plugin slug.
	 */
	protected function get_plugin_slug(): string {
		return 'co-authors-plus';
	}

	/**
	 * Locate the wp-env tests-cli container serving THIS checkout.
	 *
	 * Container names are derived by wp-env from a hash of the environment
	 * path, and a developer may have several environments running at once (one
	 * per git worktree). Picking the first container matching "tests-cli" would
	 * therefore run this suite's destructive resets against somebody else's
	 * database, so the container is identified by matching a bind-mount source
	 * against this checkout's path instead of by name.
	 *
	 * @return string Container name, or empty string when it cannot be found.
	 */
	private function get_cli_container(): string {
		if ( null !== self::$cli_container ) {
			return self::$cli_container;
		}

		self::$cli_container = '';

		$repo_root = realpath( dirname( __DIR__, 2 ) );

		if ( false === $repo_root ) {
			return self::$cli_container;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Test harness; must shell out to Docker.
		exec( 'docker ps --filter name=tests-cli --format "{{.Names}}" 2>/dev/null', $names, $exit_code );

		if ( 0 !== $exit_code ) {
			return self::$cli_container;
		}

		foreach ( array_filter( array_map( 'trim', $names ) ) as $name ) {
			$mounts = array();
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Test harness; must shell out to Docker.
			exec(
				sprintf(
					'docker inspect %s --format "{{range .Mounts}}{{.Source}}\n{{end}}" 2>/dev/null',
					escapeshellarg( $name )
				),
				$mounts
			);

			foreach ( array_map( 'trim', $mounts ) as $source ) {
				if ( '' !== $source && $source === $repo_root ) {
					self::$cli_container = $name;
					return self::$cli_container;
				}
			}
		}

		return self::$cli_container;
	}

	/**
	 * Execute a WP-CLI command inside the wp-env tests container.
	 *
	 * Overrides the parent purely for speed. The parent shells out to
	 * `wp-env run`, which creates and tears down a fresh container for every
	 * single WP-CLI invocation (measured at ~1.07s each). Running `docker exec`
	 * against the already-running tests-cli container is ~0.45s, and since a
	 * scenario issues a dozen or more invocations, that difference dominates
	 * total suite runtime.
	 *
	 * Falls back to the parent implementation whenever the container cannot be
	 * identified, so the suite still runs anywhere Docker introspection is
	 * unavailable.
	 *
	 * Output handling deliberately mirrors the parent: STDERR is folded into
	 * STDOUT by the shell, then lines beginning "Error:"/"Warning:" (plus their
	 * indented continuations) are split back out into $error_output.
	 *
	 * @param string $command     The WP-CLI command to execute (without 'wp').
	 * @param bool   $should_fail Whether the command is expected to fail.
	 * @return void
	 */
	protected function run_wp_cli_command( string $command, bool $should_fail = false ): void {
		$container = $this->get_cli_container();

		if ( '' === $container ) {
			parent::run_wp_cli_command( $command, $should_fail );
			return;
		}

		$command = $this->replace_variables( $command );

		$exec_command = sprintf(
			'docker exec -w %s %s sh -c %s',
			escapeshellarg( self::CONTAINER_PLUGIN_PATH . $this->get_plugin_slug() ),
			escapeshellarg( $container ),
			escapeshellarg( 'wp ' . $command . ' 2>&1' )
		);

		$output_lines = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Test harness; must shell out to Docker.
		exec( $exec_command, $output_lines, $exit_code );

		// Drop blank padding lines, matching the parent's filtering.
		$filtered_lines = array_filter(
			$output_lines,
			static function ( $line ) use ( $output_lines ) {
				return ! ( '' === trim( $line ) && count( $output_lines ) > 1 );
			}
		);

		$this->output       = implode( "\n", $filtered_lines );
		$this->error_output = '';
		$this->exit_code    = $exit_code;

		if ( 0 === $exit_code && ! $should_fail ) {
			return;
		}

		$error_lines    = array();
		$in_error_block = false;

		foreach ( $filtered_lines as $line ) {
			if ( 0 === strpos( $line, 'Error:' ) || 0 === strpos( $line, 'Warning:' ) ) {
				$error_lines[]  = $line;
				$in_error_block = true;
			} elseif ( $in_error_block && ( 0 === strpos( $line, ' ' ) || 0 === strpos( $line, "\t" ) ) ) {
				$error_lines[] = $line;
			} else {
				$in_error_block = false;
			}
		}

		if ( ! empty( $error_lines ) ) {
			$this->error_output = implode( "\n", $error_lines );
			$this->output       = implode( "\n", array_diff( $filtered_lines, $error_lines ) );
		}
	}

	/**
	 * Reset database state between scenarios.
	 *
	 * Overrides the parent purely for speed. The parent issues six WP-CLI
	 * calls plus one per non-admin user, and plugin_specific_cleanup() adds two
	 * more; because the reset runs both in the "Given a WP installation" step
	 * and again in @AfterScenario, a single scenario paid for roughly sixteen
	 * WP-CLI invocations before asserting anything. This performs the identical
	 * work in one.
	 *
	 * The sequence below deliberately matches the parent's ordering, because
	 * the order is observable: deleting a user fires Co-Authors Plus's
	 * delete_user hook, which removes that user's author term, so users must be
	 * deleted after their posts. Guest authors are removed last, mirroring
	 * plugin_specific_cleanup(), as the guest-author post type is excluded from
	 * a post_type=any query.
	 *
	 * @return void
	 */
	protected function reset_database_state(): void {
		$php = <<<'RESET'
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;
foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}
foreach ( get_users( array( 'fields' => 'ID' ) ) as $uid ) {
	if ( 1 !== (int) $uid ) {
		wp_delete_user( (int) $uid );
	}
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%'" );
wp_cache_flush();
foreach ( get_posts( array( 'post_type' => 'guest-author', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}
RESET;

		// Base64 keeps the payload free of single quotes, so wrapping it in them
		// is enough to hand WP-CLI the whole program as a single argument
		// through both this class's docker exec call and the parent's bash -c.
		$this->run_wp_cli_command(
			sprintf( 'eval \'eval( base64_decode( "%s" ) );\'', base64_encode( $php ) ),
			false
		);

		// A silently failing reset would leak state into the next scenario and
		// produce a confusing failure far from its cause, so fail loudly here.
		if ( 0 !== $this->exit_code ) {
			throw new RuntimeException( 'Failed to reset database state: ' . $this->output );
		}

		$this->variables = array();
	}

	/**
	 * Clean up plugin-specific data between scenarios.
	 *
	 * Retained so that the parent's reset_database_state() remains correct if it
	 * is ever called directly; the fast reset above folds this work into its
	 * single query.
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
	 * Skip the parent's post-scenario reset.
	 *
	 * The parent resets after every scenario AND again inside the "Given a WP
	 * installation" step, so each scenario paid for the same cleanup twice.
	 * Every scenario in this suite opens with that Given step, either directly
	 * or through a Background, so the trailing reset is redundant.
	 *
	 * This makes the Given step the single point at which state is cleaned.
	 * Any new scenario MUST therefore begin with it, which is also the house
	 * convention for readability.
	 *
	 * @param AfterScenarioScope $scope Scenario scope.
	 * @return void
	 */
	public function after_scenario( AfterScenarioScope $scope ): void {
		// Intentionally empty; see docblock.
	}

	/**
	 * Set up a clean WordPress installation with Co-Authors Plus activated.
	 *
	 * @Given a WP installation with the Co-Authors Plus plugin
	 * @return void
	 */
	public function given_a_wp_installation_with_plugin(): void {
		$this->reset_database_state();

		// Activation survives the reset, which only clears posts, users,
		// transients and cache, so this is done once per run rather than once
		// per scenario. No scenario deactivates the plugin.
		if ( self::$plugin_activated ) {
			return;
		}

		$this->run_wp_cli_command( 'plugin activate co-authors-plus', false );

		if ( 0 !== $this->exit_code ) {
			throw new RuntimeException( 'Failed to activate plugin: ' . $this->output );
		}

		self::$plugin_activated = true;
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
