# WP-CLI commands

Co-Authors Plus registers its commands under `wp co-authors-plus`. Run `wp help co-authors-plus` for the list, or `wp help co-authors-plus <command>` for one command's options.

The commands cover four jobs:

- Inspecting bylines and finding posts with no author terms.
- Backfilling and maintaining author terms.
- Changing bylines in bulk.
- Creating guest authors, and moving them between sites.

Most commands write to the database, several of them across many posts. Only `swap-coauthors` and `import-coauthors` have a dry run. Take a database backup before running any of them on a production site, and try them on a staging copy first.

## When commands are available

The commands are registered on `init`, so they exist only on a site where the plugin is active.

The commands that read or write guest author profiles are not registered when guest authors are turned off with the `coauthors_guest_authors_enabled` filter. Those are `create-author`, `create-guest-authors`, `create-guest-authors-from-csv`, `create-guest-authors-from-wxr`, `export-coauthors` and `import-coauthors`. Every other command works on author terms and bylines, and is always registered. The registration lives in `php/cli/register-commands.php`.

## Global options

These are WP-CLI's own options and work with every command:

- `--url=<url>` runs the command against one site of a multisite network.
- `--user=<login>` runs the command as that user. Commands that leave the post status to `WP_Query` then also see the private posts that user can read.
- `--quiet` suppresses informational output. Warnings and errors are still printed.

See the [WP-CLI global parameters](https://make.wordpress.org/cli/handbook/references/config/#global-parameters) for the full list.

## Inspecting bylines

### `list-authors`

Lists the co-authors of one post.

```sh
wp co-authors-plus list-authors <post_id> [--field=<field>] [--fields=<fields>] [--format=<format>]
```

- `<post_id>`: the post to read. Required.
- `--field`: print one field for each co-author.
- `--fields`: comma-separated fields to show. The available fields are `ID`, `display_name`, `user_nicename` and `user_email`.
- `--format`: `table` (the default), `csv`, `json`, `count` or `yaml`.

The list comes from `get_coauthors()`, so a post with no author terms still lists its `post_author` user. Don't use this command to check whether a post has author terms; use `list-posts-without-terms` for that.

### `list-posts-without-terms`

Lists the posts of one post type that have no author terms. It changes nothing.

```sh
wp co-authors-plus list-posts-without-terms [--post_type=<post-type>]
```

- `--post_type`: the post type to check. Defaults to `post`.

It checks every post status except trashed posts and auto-drafts. Each matching post is printed as one CSV line, with no header row: the post ID, title, permalink and post date. When nothing matches, it prints nothing.

Run it before and after a backfill. See [Backfilling author terms](./backfilling-author-terms.md#check-what-needs-backfilling) for how its results compare with the backfill's.

## Backfilling and maintaining author terms

### `create-author-terms-for-posts`

Gives each post that has no author terms the term for its `post_author` user. This is the command to run after installing Co-Authors Plus on a site that already has posts.

```sh
wp co-authors-plus create-author-terms-for-posts \
    [--post-types=<post-types>] [--post-statuses=<post-statuses>] \
    [--records-per-batch=<number>] [--unbatched] \
    [--specific-post-ids=<ids>] [--above-post-id=<id>] [--below-post-id=<id>]
```

By default it covers published posts of the `post` post type, 250 at a time. Posts it cannot handle are marked with `_cap_skip_backfill` post meta so later runs skip them. There is no dry run.

See [Backfilling author terms](./backfilling-author-terms.md) for every option, its default, examples, and exactly what the command does.

### `create-terms-for-posts`

An older backfill that does the same job less efficiently. Prefer `create-author-terms-for-posts`.

```sh
wp co-authors-plus create-terms-for-posts [--post-statuses=<post-statuses>]
```

- `--post-statuses`: comma-separated post statuses to cover. Defaults to `publish`.

It walks every post of every post type Co-Authors Plus is enabled for, 100 at a time, from the lowest ID up, and checks each one. That includes posts that already have terms, which is why it is slow on a large site. It pauses for one second every 500 posts.

A post with any author term is left alone, even if that term is not for its `post_author`. A post whose `post_author` user no longer exists is skipped with a warning, and is not marked, so every run reports it again.

### `delete-postmeta-that-skip-author-term-backfill`

Removes the `_cap_skip_backfill` post meta that `create-author-terms-for-posts` adds to posts it could not handle. Run it once you have fixed the cause, for example by restoring a deleted user, so that the next backfill tries those posts again.

```sh
wp co-authors-plus delete-postmeta-that-skip-author-term-backfill [--specific-post-ids=<ids>]
```

- `--specific-post-ids`: comma-separated post IDs to clear. Defaults to every post that has the meta, of any post type and status.

It reports each post it clears. A listed post that has no marker gets a warning, and a non-numeric ID is ignored with a warning; neither stops the run. When there is nothing to clear, it prints nothing.

### `update-author-terms`

Refreshes the existing author terms, and creates any that are missing. It does not attach terms to posts; for that, use `create-author-terms-for-posts`.

```sh
wp co-authors-plus update-author-terms
```

It has no options. It works in three passes:

1. For every author term, it rewrites the term description from the matching user or guest author, and recalculates the term's post count.
2. For every user on the site, it creates an author term if there isn't one. That includes users of every role, and users who have never written a post.
3. When guest authors are enabled, it does the same for every guest author.

Run it if author term counts look wrong, or after bulk changes to users or guest authors. A term with no matching user or guest author is still reported as refreshed, although nothing changed. See the [behavior notes](./cli-behaviour-notes.md#update-author-terms).

### `migrate-author-terms`

Adds the `cap-` prefix to author term slugs created before version 3.0, which had no prefix.

```sh
wp co-authors-plus migrate-author-terms
```

It has no options. Where a prefixed term already exists alongside the old one, the two are merged. Terms that already have the prefix are left alone, so it is safe to run more than once. Sites that started on version 3.0 or later don't need it.

### `remove-terms-from-revisions`

Removes author terms from post revisions. Older versions of the plugin added them, and revisions have no use for them.

```sh
wp co-authors-plus remove-terms-from-revisions
```

It has no options. It detaches the terms but doesn't delete them, and leaves the revisions and their parent posts as they are. The plugin no longer adds terms to revisions, so on most sites this finds nothing to do.

## Changing bylines in bulk

### `assign-coauthors`

Sets each post's co-author from a value stored in post meta. This suits imports from systems that record the intended author in a meta field.

```sh
wp co-authors-plus assign-coauthors \
    [--meta_key=<meta-key>] [--post_type=<post-type>] \
    [--post-statuses=<post-statuses>] [--append_coauthors]
```

- `--meta_key`: the post meta key holding a co-author's login. Defaults to `_original_import_author`.
- `--post_type`: the post type to cover. Defaults to `post`.
- `--post-statuses`: comma-separated post statuses to cover. Defaults to `publish`.
- `--append_coauthors`: add the co-author to the existing byline instead of replacing it.

Only posts that have the meta key are considered. The value is looked up as a user or guest author login, and then as a slug if that fails. A post that already has that co-author's term is left alone, so a second run does nothing new. The summary reports posts that already had the co-author, values that match no co-author, and empty values.

If the co-author is a guest author without a linked WordPress account, the byline changes but `post_author` keeps its old value. The summary counts those posts.

### `assign-user-to-coauthor`

Gives a co-author the byline on the posts whose `post_author` is a given WordPress user.

```sh
wp co-authors-plus assign-user-to-coauthor \
    (--user_login=<user-login> | --user_id=<user-id>) \
    --coauthor=<co-author> [--append_coauthors]
```

- `--user_login` or `--user_id`: the user whose posts to change. Give exactly one. Use `--user_id` when the user has been deleted, since `post_author` still holds their old ID.
- `--coauthor`: the login of the co-author to give the byline to. Required.
- `--append_coauthors`: add the co-author to posts that already have co-authors.

It covers every post type Co-Authors Plus is enabled for, in any post status. Without `--append_coauthors`, a post that already has any author term is skipped, not replaced. Posts saved while the plugin was active normally have a term for their author already, so in that mode the command mostly reaches posts with no terms at all, such as those of a deleted user. With `--append_coauthors`, the co-author is added alongside the existing ones. To replace one co-author with another on posts that already have terms, use `swap-coauthors`.

It pauses for two seconds every 100 posts it changes.

### `swap-coauthors`

Replaces one co-author with another on every post that has the first co-author's term. The other co-authors on each post stay.

```sh
wp co-authors-plus swap-coauthors --from=<user-login> --to=<user-login> [--post_type=<post-type>] [--dry-run]
```

- `--from`: the login of the co-author to remove. Required.
- `--to`: the login of the co-author to add. Required, and must be a different co-author.
- `--post_type`: the post type to cover. Defaults to `post`.
- `--dry-run`: list the posts that would change, without changing them. `--dry` still works as a deprecated alias.

It works 100 posts at a time. It leaves the `--from` co-author's term in place, with no posts. Unlike `rename-coauthor`, it works when the `--to` co-author already has a term of their own.

Things to know:

- It finds posts by term. A post whose only link to the `--from` user is `post_author` is not changed. The command counts and reports such published posts, and `create-author-terms-for-posts` gives them a term first.
- The post status is left to `WP_Query`, so it covers published posts only, plus private posts that a `--user` can read. Drafts are not changed.
- `post_author` becomes the first remaining co-author with a WordPress account, which isn't always the `--to` co-author. If none has an account, `post_author` keeps its old value and the summary counts the post.

See the [behavior notes](./cli-behaviour-notes.md#swap-coauthors) for details.

### `rename-coauthor`

Changes the login an author term represents, by renaming the term itself.

```sh
wp co-authors-plus rename-coauthor --from=<user-login> --to=<user-login>
```

- `--from`: the co-author's current login. Required.
- `--to`: the new login. Required, and must not belong to an existing co-author.

The term's name becomes the new login and its slug becomes `cap-<new login>`. If the co-author is a guest author, its profile's `post_name` and `cap-user_login` meta are updated to match, so the term and the profile stay in step.

If the co-author is a WordPress user, only the term changes. The user keeps their login, and the term no longer matches them. Use this command for guest authors, and change a user's login by other means.

### `reassign-terms`

Moves author terms from one login to another, one at a time or in bulk from a mapping file. It's for cleaning up after an import created terms from logins that have since changed.

```sh
wp co-authors-plus reassign-terms [--author-mapping=<file>] [--old-term=<slug>] [--new-term=<slug>]
```

- `--author-mapping`: a PHP file that defines `$cli_user_map`, an array of old login to new login.
- `--old-term` and `--new-term`: a single reassignment. Use both together. When given, they replace the mapping file's contents. `--old_term` and `--new_term` still work as deprecated aliases.

A mapping file looks like this:

```php
<?php
$cli_user_map = array(
	'old-login'   => 'new-login',
	'other-login' => 42, // A numeric value is read as a user ID.
);
```

The file is loaded with `require_once`, so it runs as PHP. Only use a file you trust.

For each entry, the old login must resolve to a co-author with a term. If the new login already has a term, the old term's posts move to it and the old term is deleted. Otherwise the old term is renamed. The command never changes guest author profiles; use `rename-coauthor` for that.

An entry whose old term can't be found is reported and skipped, and the command still exits with 0. That includes an orphaned term with no user or guest author behind it. See the [behavior notes](./cli-behaviour-notes.md#reassign-terms).

## Creating guest authors

These commands need guest authors to be enabled. See [Guest authors](./guest-authors.md) for what the profile fields mean and how linked accounts work.

### `create-author`

Creates one guest author.

```sh
wp co-authors-plus create-author \
    --display_name=<display-name> --user_login=<user-login> \
    [--first_name=<first-name>] [--last_name=<last-name>] \
    [--user_email=<user-email>] [--website=<website>] \
    [--description=<description>]
```

WP-CLI treats every option as optional, but the profile isn't created without `--display_name` and `--user_login`. The values are sanitized as the guest author edit screen would sanitize them.

If a guest author with the same email address, or failing that the same login, already exists, the command reports it and creates nothing. That is treated as success, so the command is safe to run again. A login that matches an existing WordPress user's login or nicename is refused, as on the [edit screen](./guest-authors.md#creating-a-guest-author). When creation fails, the command exits with 1.

### `create-guest-authors`

Creates a guest author profile, linked to the account, for each WordPress user on the site.

```sh
wp co-authors-plus create-guest-authors [--offset=<offset>] [--number=<number>]
```

- `--offset`: how many users to skip before starting. Defaults to 0.
- `--number`: how many users to process. Defaults to all of them.

Users are taken in `user_login` order, so `--offset` and `--number` let you work through a large user list in chunks. Users that already have a linked profile are skipped, so an interrupted run can be repeated. The command shows a progress bar, then the number of profiles created and skipped. Any user whose profile couldn't be created is counted as skipped.

On multisite, it covers the users who are members of the current site.

### `create-guest-authors-from-csv`

Creates guest authors from a CSV file.

```sh
wp co-authors-plus create-guest-authors-from-csv --file=<file>
```

- `--file`: path to the CSV file. Required.

The first row names the columns. The command reads `display_name`, `user_login`, `user_email`, `first_name`, `last_name`, `website`, `description` and `avatar` (an attachment ID for the profile image), and ignores other columns. Each row needs a `display_name` and a `user_login`. Where a row has neither `first_name` nor `last_name`, the display name is split at its first space.

Include every column in the header, even if some are empty: a missing column produces PHP warnings for every row. See the [behavior notes](./cli-behaviour-notes.md#create-guest-authors-from-csv).

Existing profiles are detected as for `create-author`. A row that fails doesn't stop the import. The command reports how many rows failed, and exits with 0.

### `create-guest-authors-from-wxr`

Creates a guest author for each author listed in a WordPress export (WXR) file.

```sh
wp co-authors-plus create-guest-authors-from-wxr --file=<file>
```

- `--file`: path to the WXR file. Required.

It uses the parser from the WordPress Importer plugin, which must be installed. If it isn't, the command says so; install it with `wp plugin install wordpress-importer`. The command reads each author's login, email address, display name, first name and last name, and stores the author's ID from the source site in `_original_author_id` post meta.

It creates profiles only. It doesn't import posts or assign bylines. Existing profiles are detected as for `create-author`, and a failed author doesn't stop the run.

## Moving guest authors between sites

### `export-coauthors`

Writes the site's guest authors, and the posts each is assigned to, to a JSON file for `import-coauthors`.

```sh
wp co-authors-plus export-coauthors [--file=<path>] [--post-types=<post-types>]
```

- `--file`: where to write the file. Defaults to `cap-coauthors-export.json` in the `wp-content` directory. An existing file is overwritten.
- `--post-types`: comma-separated post types to record assignments for. Defaults to the post types Co-Authors Plus is enabled for.

Each post is recorded by its slug and post type, with the guest author's position in the byline, rather than by post ID. That keeps the file valid after a WordPress export and import has renumbered the posts. Posts in any status except trash are included.

Only guest authors are exported. Co-authors who are WordPress users are not, since user accounts move by other means.

### `import-coauthors`

Reads a file written by `export-coauthors`, creates any guest authors the site doesn't have, and puts each one back on its posts.

```sh
wp co-authors-plus import-coauthors --file=<path> [--dry-run] [--skip-create]
```

- `--file`: the export file. Required.
- `--dry-run`: report what would happen without writing anything.
- `--skip-create`: don't create missing profiles; only link existing ones to posts. Use this when the guest author profiles came across in a WordPress import already.

Guest authors are matched by login. Posts are matched by slug and post type, in any status. Each guest author is added to the byline at its recorded position, alongside any co-authors the post already has. A post that already has the guest author is left alone, so an interrupted import can be run again.

The command warns when the file came from a different version of the plugin, and for each post it couldn't find. These are warnings, not errors, and the command exits with 0. The dry run counts every post it can find, including posts that already have the guest author, so its figure can be higher than a real run's.

## Multisite

Author terms and guest authors belong to each site, not to the network. Run a command once for each site you want to change, using `--url`:

```sh
wp site list --field=url | xargs -I{} wp --url={} co-authors-plus create-author-terms-for-posts
```

The commands are only registered on sites where the plugin is active, so this fails for any site where it isn't.

## Troubleshooting

- **`No co-author found for <login>` or `Please specify a valid co-author login`:** the login isn't a user or guest author on this site. Check with `wp user list --field=user_login`, and `wp post list --post_type=guest-author --post_status=any --fields=ID,post_title,post_name`. A guest author's `post_name` is `cap-` followed by its login.
- **Author term counts look wrong:** run `update-author-terms`.
- **The backfill keeps missing some posts:** check them for `_cap_skip_backfill` post meta. Fix the cause, then run `delete-postmeta-that-skip-author-term-backfill`. See [Backfilling author terms](./backfilling-author-terms.md#posts-the-backfill-skips).
- **Long runs:** most commands print a line per post. When running from a scheduler, send the output to a file, for example `wp co-authors-plus create-author-terms-for-posts > backfill.log 2>&1`.
