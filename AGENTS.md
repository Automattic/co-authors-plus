# Co-Authors Plus

Multiple byline management for WordPress, supporting both WordPress users and guest authors.

## Project Knowledge

| Property | Value |
|----------|-------|
| **Main file** | `co-authors-plus.php` |
| **Text domain** | `co-authors-plus` |
| **Class prefix** | `CoAuthors_` (legacy), `CoAuthors\` / `Automattic\CoAuthorsPlus\` (namespaced) |
| **Function prefix** | `cap_` (helpers), `coauthors*` (template tags) |
| **Source directory** | `php/` (PHP classes), `src/` (JS/blocks) |
| **Version** | 4.0.1 |
| **Requires PHP** | 7.4+ |
| **Requires WP** | 6.4+ |

### Directory Structure

```
co-authors-plus/
├── co-authors-plus.php                # Main plugin file (includes, globals, bootstrap)
├── template-tags.php                  # Template tag functions (coauthors(), get_coauthors(), etc.)
├── upgrade.php                        # Database upgrade functions
├── php/
│   ├── class-coauthors-plus.php       # Main plugin class (taxonomy, meta boxes, AJAX)
│   ├── class-coauthors-guest-authors.php  # Guest author CPT management
│   ├── class-coauthors-template-filters.php # Frontend template filters
│   ├── class-coauthors-wp-list-table.php   # Admin list table
│   ├── class-coauthors-iterator.php   # Iterator for looping through coauthors
│   ├── class-wp-cli.php              # WP-CLI commands
│   ├── api/endpoints/                 # REST API controller (CoAuthors\API\Endpoints)
│   ├── blocks/                        # Gutenberg blocks (CoAuthors\Blocks)
│   └── integrations/                  # AMP, Yoast, WordPress Importer, Jetpack
├── src/                               # JavaScript/React source (blocks, store)
├── build/                             # Compiled JavaScript output
├── tests/
│   └── Integration/                   # Integration tests (requires wp-env)
├── features/                          # Behat acceptance tests
├── .github/workflows/                 # CI: cs-lint, integration, lint, build, behat, deploy
└── .phpcs.xml.dist                    # PHPCS configuration
```

### Key Classes and Files

- `php/class-coauthors-plus.php` — Main `CoAuthors_Plus` class: `author` taxonomy, meta boxes, post author filtering, AJAX search, caching
- `php/class-coauthors-guest-authors.php` — `CoAuthors_Guest_Authors`: guest author CPT (`guest-author`), admin UI, avatar handling, privacy export
- `template-tags.php` — Template functions: `coauthors()`, `get_coauthors()`, `is_coauthor_for_post()`, `coauthors_posts_links()`, etc.
- `php/class-coauthors-iterator.php` — Enables looping through coauthors; modifies global `$authordata`
- `php/class-coauthors-template-filters.php` — Frontend filters for `the_author`, `the_author_posts_link`, RSS feeds
- `php/api/endpoints/class-coauthors-controller.php` — REST API controller (`coauthors/v1`)
- `php/blocks/` — Five Gutenberg blocks for displaying coauthor information
- `php/class-wp-cli.php` — WP-CLI commands: `wp co-authors-plus create-guest-authors`, `create-terms-for-posts`
- `php/integrations/` — Integrations with AMP, Yoast SEO, WordPress Importer, Jetpack

### Dependencies

- **Dev**: `automattic/vipwpcs`, `yoast/wp-test-utils`, `phpunit/phpunit`, `behat/behat`, `automattic/behat-wp-env-context`

## Commands

```bash
composer cs                # Check code standards (PHPCS)
composer cs-fix            # Auto-fix code standard violations
composer lint              # PHP syntax lint
composer test:integration  # Run integration tests (requires wp-env)
composer test:integration-ms  # Run multisite integration tests
composer coverage          # Run tests with HTML coverage report
composer behat             # Run Behat acceptance tests
npm run build              # Build JavaScript/block assets
npm run lint:js            # Lint JavaScript (ESLint)
npm run lint:css           # Lint CSS (Stylelint)
```

**Note:** This plugin has integration tests and Behat acceptance tests — no separate unit test suite.

## Conventions

Follow the standards documented in `~/code/plugin-standards/` for full details. Key points:

- **Commits**: Use the `/commit` skill. Favour explaining "why" over "what".
- **PRs**: Use the `/pr` skill. Squash and merge by default.
- **Branch naming**: `feature/description`, `fix/description` from `develop`.
- **Testing**: Integration tests for WordPress-dependent behaviour. Use the existing `TestCase` base class in `tests/Integration/`. Behat for acceptance testing.
- **Code style**: WordPress coding standards via PHPCS. Tabs for indentation.
- **i18n**: All user-facing strings must use the `co-authors-plus` text domain.

## Architectural Decisions

- **Taxonomy-based storage**: Coauthor relationships are stored via the `author` taxonomy, with term slugs in the format `cap-{user_nicename}`. This leverages WordPress's taxonomy infrastructure for queries and caching. Do not switch to post meta or custom tables.
- **Guest author CPT**: Guest authors are a custom post type (`guest-author`) allowing bylines without WordPress user accounts. This is a core feature — do not remove or replace it.
- **Post author field sync**: The plugin syncs `wp_posts.post_author` via the `coauthors_set_post_author_field` filter to maintain backward compatibility with queries expecting a single author. Do not break this sync.
- **SQL query filters**: Author queries are modified via `posts_where`, `posts_join`, and `posts_groupby` filters. Changes to these filters can break archive pages and author queries site-wide.
- **Block system**: Five Gutenberg blocks use `co-authors-plus/author` block context. The blocks are in `php/blocks/` with React source in `src/blocks/`.
- **WordPress.org deployment**: Has a deploy workflow for WordPress.org SVN. Do not manually modify SVN assets.

## Common Pitfalls

- Do not edit WordPress core files or bundled dependencies in `vendor/`.
- Run `composer cs` before committing. CI will reject code standard violations.
- Integration tests require `npx wp-env start` running first.
- **Template tags are public API**: Functions in `template-tags.php` (`coauthors()`, `get_coauthors()`, `coauthors_posts_links()`, etc.) are used by themes in production. Do not rename, remove, or change their signatures without a deprecation cycle.
- **Coauthor term ordering is significant**: The plugin maintains term order for coauthors. Bulk edits or direct term manipulation can reset ordering.
- **Guest author deletion**: When a guest author is deleted, posts need reassignment. The plugin handles this via `delete_user_action`, but test carefully.
- **`post_author` sync**: Misalignment between the `author` taxonomy terms and `wp_posts.post_author` can cause issues. Always use plugin functions to modify coauthors, not direct database updates.
- **Custom `wp_notify_postauthor()`**: The plugin overrides the core function to notify all coauthors of comments. This can conflict with other plugins that also override this function.
- **Author archive pages**: Guest authors use a different URL structure. The `fix_author_page` hook handles this — do not remove it or archive pages will break.
- **Two version locations**: Version is defined in `co-authors-plus.php` (header + constant) and `package.json`. Both must be kept in sync.
