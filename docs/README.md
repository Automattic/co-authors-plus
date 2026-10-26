# Co-Authors Plus documentation

What Co-Authors Plus does, and how to install it, is covered in the [README](../README.md). These pages go further.

## Using Co-Authors Plus

- [Guest authors](./guest-authors.md): creating and editing bylines that aren't tied to a WordPress user account, profile fields, migrating users to guest authors, and multisite.
- [Roles and capabilities](./roles-and-capabilities.md): who can assign co-authors and manage guest authors, and how to change that.

## Displaying co-authors in your theme

- [Template tags](./template-tags.md): the PHP functions for outputting co-authors in classic themes.
- [Blocks](./blocks.md): the Co-Authors blocks for block themes and the block editor.
- [Querying posts by author or co-author](./querying-posts.md).

## Extending Co-Authors Plus

- [Customizing Co-Authors Plus](./customizing.md): recipes for the most common filters.
- [Filters and actions](./filters.md): every hook the plugin provides.
- [REST API](./rest-api.md): the `coauthors/v1` endpoints and the `coauthors` field on core post responses.

## Site administration

- [WP-CLI commands](./wp-cli.md): every `wp co-authors-plus` command.
- [Backfilling author terms for existing posts](./backfilling-author-terms.md): what to run after installing on a site that already has posts.
- [WP-CLI behavior notes](./cli-behaviour-notes.md): known quirks in the current commands.

## Upgrading

- [Upgrading to 4.0](./upgrading-to-4.0.md).
- [Installing with Composer](./upgrading-to-4.0.md#installing-with-composer): which package to require, since the Packagist one has no built files.
- [Recovering from the 4.0.0 REST backfill regression](./recovering-from-4.0.0.md): for sites that ran 4.0.0.

## Contributing

- [Contributing guide](../CONTRIBUTING.md): local environment, tests, coding standards, and pull requests.

## Concepts

- **User:** a WordPress user account.
- **Guest author:** a Co-Authors Plus author profile that isn't tied to a WordPress user account. Stored as a `guest-author` post.
- **Co-author:** a user or guest author assigned to a post as one of its bylines.
- **Author term:** a term in the plugin's `author` taxonomy that links a post to one of its co-authors. Each author has one term, and a post's co-authors are the terms assigned to it.
- **Linked guest author:** a guest author linked to a WordPress user account, so the byline uses the guest author profile while the user keeps their login.
