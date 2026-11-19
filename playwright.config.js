/**
 * Playwright configuration for Co-Authors Plus end-to-end tests.
 *
 * Extends the @wordpress/scripts default config (which wires up the WordPress
 * e2e-test-utils-playwright login/global-setup) and points it at tests/e2e.
 *
 * wp-env is started externally — locally and in CI — so the default webServer
 * (which would run `npm run wp-env start`) is disabled.
 *
 * The specs delete every post and user they can, so they run against the
 * disposable tests site (the default WP_BASE_URL, port 8889), never the
 * development site on port 8888.
 */
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = {
	...baseConfig,
	testDir: './tests/e2e',
	webServer: undefined,
};
