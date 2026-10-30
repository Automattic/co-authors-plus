# Contributing to Co-Authors Plus

Thanks for helping. This guide covers the tooling, tests and conventions behind the plugin. What the plugin does, and how to use it, is in the [README](./README.md) and the [documentation](./docs/README.md).

Please report security vulnerabilities privately, as described in the [security policy](./.github/SECURITY.md), not in a public issue or pull request.

## Getting set up

### Prerequisites

- [Node.js](https://nodejs.org/) and npm. `@wordpress/scripts` requires Node.js 22.22.2 or later in the 22 line, 24.15.0 or later in the 24 line, or 26 and later. CI uses Node.js 24.
- [Composer](https://getcomposer.org/) and PHP 7.4 or later on your machine. The PHP unit tests, Behat, and PHPCS run on the host, not in a container.
- [Docker](https://www.docker.com/) for the local WordPress environments.
- [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env) installed globally (`npm install -g @wordpress/env`). It isn't a project dependency, and the Composer scripts call `wp-env` directly.

### Installing dependencies

```sh
git clone https://github.com/Automattic/co-authors-plus.git
cd co-authors-plus
composer install
npm install
npm run build
```

`npm run build` compiles the block editor sidebar and the blocks from `src/` into `build/`, which isn't committed. Without it the editor panel and blocks don't load, and the asset-enqueuing integration test is skipped. Use `npm run start` to rebuild on change while you work.

Keep the directory name `co-authors-plus`. wp-env mounts the plugin under its directory name, and the integration test and Behat scripts expect `wp-content/plugins/co-authors-plus`.

## Local development

Run `wp-env start` to spin up a local WordPress environment. On start it
activates Query Monitor and seeds demo data (`bin/seed-demo-data.php`): a spread
of users, standalone and linked guest authors, and posts covering single,
multiple, mixed, fallback, and orphaned-author bylines. All demo users share the
password `password`.

Re-seed at any time (the script is idempotent) with:

```sh
composer dev:seed
```

`.wp-env.json` defines two environments:

- **Development** at <http://localhost:8888> (log in as `admin` / `password`). PHP 8.2 and the latest WordPress release. Use it for manual testing; the E2E tests also run against it.
- **Tests** at <http://localhost:8889>. PHP 8.4 and WordPress trunk. The PHPUnit integration suite and Behat run here.

Because the tests environment tracks WordPress trunk, the first `wp-env start` clones the WordPress repository and can take a while.

If the default ports clash with something else, set `port` and `testsPort` in a `.wp-env.override.json` file next to `.wp-env.json` (it's gitignored). Use the same file to change anything else for your machine only.

`wp-env stop` shuts both environments down. `wp-env destroy` removes them, for a clean slate.

## Running the tests

There are five suites. Add or update tests for any behavior change; for a bug fix, prefer a test that fails without the fix.

| Suite | Tool | Location | Command | Needs wp-env |
| ----- | ---- | -------- | ------- | ------------ |
| PHP unit | PHPUnit with Brain Monkey | `tests/Unit/` | `composer test:unit` | No |
| PHP integration | PHPUnit | `tests/Integration/` | `composer test:integration` | Yes |
| WP-CLI acceptance | Behat | `features/`, `tests/Behat/` | `composer behat` | Yes |
| JavaScript unit | Vitest | `src/**/*.test.js` | `npm run test:unit` | No |
| End-to-end | Playwright | `tests/e2e/` | `npm run test:e2e` | Yes |

### PHP unit tests

The unit suite runs without WordPress, so it's fast and needs no environment. Extend `tests/Unit/TestCase.php`.

```sh
composer test:unit
```

### PHP integration tests

The integration suite boots WordPress against a real database in the wp-env tests environment. Extend `tests/Integration/TestCase.php`.

```sh
composer test:integration      # single site
composer test:integration-ms   # multisite
```

Tests that only make sense on multisite are tagged `@group ms-required` and are excluded from the single-site run. Tests tagged `@group ms-excluded` are excluded from the multisite run.

Run the two one after the other, not at the same time: they share the tests database, and each run reinstalls it.

To run a subset, call PHPUnit in the container directly and add `--filter`:

```sh
wp-env run tests-cli --env-cwd=wp-content/plugins/co-authors-plus \
	./vendor/bin/phpunit --testsuite integration --exclude=ms-required \
	--no-coverage --filter=CoAuthorsControllerTest
```

Keep `--testsuite integration`. `phpunit.xml.dist` defines both suites, and the bootstrap only loads the WordPress test library for the integration suite.

### Behat tests for WP-CLI commands

Each `wp co-authors-plus` command has a feature file in `features/`. The steps run WP-CLI in the wp-env tests environment through [automattic/behat-wp-env-context](https://packagist.org/packages/automattic/behat-wp-env-context), and the context class is `tests/Behat/FeatureContext.php`.

```sh
composer prepare-behat-tests   # runs wp-env start
composer behat                 # runs every feature
composer behat -- features/swap-coauthors.feature
composer behat-rerun           # reruns only the scenarios that failed
```

The full suite takes several minutes. When you change a command, run its feature file locally and let CI run the rest.

### JavaScript unit tests

Component and utility tests for the editor code in `src/` run on [Vitest](https://vitest.dev/) with jsdom and Testing Library (see `vitest.config.mjs`). Test files are named `*.test.js` and live under `src/`.

```sh
npm run test:unit
npm run test:unit:watch
```

### End-to-end tests

The Playwright specs in `tests/e2e/` drive the block editor and the classic admin screens. They run against the development environment, not the tests environment, so set `WP_BASE_URL` (the default is port 8889):

```sh
npm run build
npx playwright install chromium
WP_BASE_URL=http://localhost:8888 npm run test:e2e
```

The editor loads `build/`, so rebuild after changing `src/`. Single-file fixture plugins for the specs live in `tests/e2e/plugins/`, which `.wp-env.json` maps to `wp-content/plugins/cap-e2e-plugins/`. Run `wp-env start` again after changing `.wp-env.json` so the mapping is picked up.

## Coding standards

PHP follows the rulesets in `.phpcs.xml.dist`: WordPress VIP Go, WordPress Extra and WordPress Docs, plus PHPCompatibilityWP for PHP 7.4 and later. Test code is exempt from some documentation and naming sniffs.

```sh
composer cs                         # report violations
composer cs -- path/to/file.php     # check specific files
composer cs-fix                     # fix what can be fixed automatically
composer lint                       # check for PHP parse errors
```

Run PHPCS on the files you changed before you push.

JavaScript and CSS use [`@wordpress/scripts`](https://www.npmjs.com/package/@wordpress/scripts):

```sh
npm run lint:js
npm run lint:css
npm run lint:md:docs
```

ESLint covers everything except the legacy scripts in `js/`, including the E2E specs in `tests/e2e/`.

Every user-facing string uses the `co-authors-plus` text domain.

## Continuous integration

GitHub Actions runs these workflows on pull requests. Most only run when the pull request touches the files they check.

| Workflow | What it runs |
| -------- | ------------ |
| `cs-lint.yml` | `composer validate`, PHP lint, XML lint of the PHPUnit and PHPCS configs, and PHPCS, on PHP 7.4. |
| `unit.yml` | PHP unit tests on PHP 7.4 and the latest PHP. Runs on every pull request. |
| `integration.yml` | Integration tests, single site and multisite, on WordPress 6.4 with PHP 7.4, and on WordPress trunk with the latest PHP. |
| `behat.yml` | Behat on WordPress trunk with PHP 8.4. |
| `js-test.yml` | JavaScript unit tests. |
| `lint.yml` | ESLint and Stylelint. |
| `build.yml` | `npm run build`. |
| `e2e.yml` | Playwright against the development environment. |

Two results are informational: the PHPCS step for runtime code in `cs-lint.yml` (PHPCS on `tests/` must pass), and the WordPress trunk job in `integration.yml`, which may fail because of changes in WordPress itself. Everything else should pass before a pull request is merged.

The unit suite includes `tests/Unit/Foundation/DistributionManifestTest.php`, which checks that every file at the repository root is either listed as shipped in the test or excluded in `.distignore`, and that `.gitattributes` mirrors `.distignore`. If you add a root-level file that shouldn't ship, add it to both files.

## Repository structure

| Path | What lives here |
| ---- | --------------- |
| `co-authors-plus.php` | Plugin header, constants, file includes, and start-up. |
| `template-tags.php` | Template tag functions such as `get_coauthors()` and `coauthors_posts_links()`. These are public API used by themes. |
| `php/class-coauthors-plus.php` | `CoAuthors_Plus`: the `author` taxonomy, the meta box, saving co-authors, author search, and query filters. |
| `php/class-coauthors-guest-authors.php` | `CoAuthors_Guest_Authors`: the `guest-author` post type, its admin screens, and profile fields. |
| `php/class-coauthors-template-filters.php`, `php/class-coauthors-iterator.php` | Front-end filters on core author template functions, and the iterator behind the template tags. |
| `php/class-coauthors-endpoint.php`, `php/api/endpoints/` | The `coauthors/v1` REST routes. See [REST API](./docs/rest-api.md). |
| `php/blocks/` | Block registration and server-side rendering. The block source is in `src/blocks/`. |
| `php/cli/` | The `wp co-authors-plus` commands, one class per command, registered in `register-commands.php` and loaded only under WP-CLI. |
| `php/services/` | Logic shared between the commands and other entry points. |
| `php/integrations/` | AMP, Yoast SEO, WordPress Importer, and Jetpack integrations. |
| `src/` | Block editor JavaScript: the Authors panel, the blocks, and their data store. Compiled into `build/`. |
| `js/`, `css/` | Assets for the classic editor meta box and the guest author screens. These aren't built. |
| `bin/seed-demo-data.php` | The demo data seeder run on `wp-env start`. |
| `tests/`, `features/` | The test suites described above. |
| `docs/` | User and developer documentation. |
| `languages/` | The translation template. |

The plugin has no runtime autoloader: the Composer classmap is only used by the tests. A new file in `php/` needs a `require_once` where it's used.

For the data model (users, guest authors, and author terms), see [Concepts](./docs/README.md#concepts). Hooks are listed in [Filters and actions](./docs/filters.md).

## Pull Requests

Please make pull requests against the `develop` branch. `main` holds the latest release. If you don't have write access, fork the repository and open the pull request from your fork.

Ideally include tests.

Every commit must be signed. The `develop` and `main` branches only accept
commits with a verified signature, so a pull request containing an unsigned
commit can't be merged until you re-sign the commits and force-push the branch.
See GitHub's guide to [signing commits](https://docs.github.com/en/authentication/managing-commit-signature-verification/signing-commits).

The pull request template asks for a description, deploy notes, and steps to test. Explain why the change is needed as well as what it does, and say what's out of scope. Keep each pull request to one change, and keep formatting-only changes in their own commits.

Commit messages in this repository use a conventional prefix such as `fix:`, `feat:`, `test:`, `docs:`, `refactor:`, or `build:`, with an optional scope (`fix(blocks):`). Write a short subject that says what changed, and use the body to explain why.

Review is requested automatically from the code owners in `.github/CODEOWNERS`.

## Terminology: co-author vs coauthor

If talking about co-authors in a documentation, DocBlock, comment, or user-facing string, please include the hyphen.

Variables, constants, class, trait, interface, function, method, hook, and other programmatic names can all use no-hyphen.

## Releases

This section is for maintainers.

The version number lives in the `co-authors-plus.php` header and the `COAUTHORS_PLUS_VERSION` constant, `package.json`, and the `Stable tag` in `README.md`. A release also updates `CHANGELOG.md` (in [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) format), the changelog and upgrade notice in `README.md`, and the translation template, `languages/co-authors-plus.pot` (run `npm run build` first so the strings in the built JavaScript are included).

Releases merge to `main`. Tagging alone doesn't deploy anything: publishing a GitHub release triggers `.github/workflows/deploy.yml`, which builds the assets, deploys to the WordPress.org plugin repository, and attaches the plugin ZIP to the release.
