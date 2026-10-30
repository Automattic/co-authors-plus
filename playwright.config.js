/**
 * Playwright configuration for Co-Authors Plus end-to-end tests.
 *
 * Extends the @wordpress/scripts default config (which wires up the WordPress
 * e2e-test-utils-playwright login/global-setup) and points it at tests/e2e.
 *
 * wp-env is started externally — locally and in CI — so the default webServer
 * (which would run `npm run wp-env start`) is disabled.
 */
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = {
	...baseConfig,
	testDir: './tests/e2e',
	webServer: undefined,
};
