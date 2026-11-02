# Backfilling author terms for existing posts

Co-Authors Plus stores each post's byline as terms in its `author` taxonomy, one term per co-author. When a post is saved while the plugin is active, and the post has no author terms, the plugin gives it the term for its `post_author` user. Posts that were never saved that way have no author terms.

The `create-author-terms-for-posts` command finds those posts and gives each one the term for its `post_author` user. This page explains when to run it and what it does. For the other commands, see [WP-CLI commands](./wp-cli.md).

## When to run it

Run the backfill:

- After installing Co-Authors Plus on a site that already has posts.
- After the plugin has been inactive for a while. Posts created or saved while it was inactive have no author terms, and reactivating the plugin doesn't add them.
- After enabling co-authors for another post type. Posts of that type saved before then have no author terms.
- After writing posts straight to the database, or with a tool that doesn't fire WordPress's `save_post` action.

Posts created or edited through the editors, the REST API or `wp post` while the plugin is active get their term automatically, so normal publishing doesn't need it.

A post with no author terms can look fine. `get_coauthors()`, which the template tags use, falls back to the `post_author` user when a post has no terms (unless guest authors are forced with the `coauthors_guest_authors_force` filter). Anything that works from terms misses the post, though. Examples are `swap-coauthors`, taxonomy queries on the `author` taxonomy, and author queries on a site that turns off [`coauthors_plus_should_query_post_author`](./filters.md#coauthors_plus_should_query_post_author).

## Not the same as `update-author-terms`

`update-author-terms` refreshes the author terms themselves: their descriptions and post counts. It also creates a term for any user or guest author that lacks one. It never attaches a term to a post, so it doesn't help posts that have no author terms. Use `create-author-terms-for-posts` for those. See [`update-author-terms`](./wp-cli.md#update-author-terms).

## Check what needs backfilling

The backfill has no dry run. Use [`list-posts-without-terms`](./wp-cli.md#list-posts-without-terms) to see which posts have no author terms first:

```sh
wp co-authors-plus list-posts-without-terms --post_type=page
```

It prints one CSV line per post, with the ID, title, permalink and date, and changes nothing.

Its scope differs from the backfill's, so its results won't always match the backfill's count:

- It checks one post type per run.
- It includes every post status except trash and auto-drafts. The backfill covers published posts only, unless you pass `--post-statuses`.
- It includes posts the backfill has already marked to skip.

The backfill's first line, `Found N posts with missing author terms.`, is its own count for the options you gave.

## Run the backfill

```sh
wp co-authors-plus create-author-terms-for-posts [options]
```

With no options, it covers published posts of the `post` post type, 250 at a time. A run can be stopped and started again: a post that has been given its term, or marked to skip, isn't selected again.

### Options

| Option | Default | Description |
| --- | --- | --- |
| `--post-types=<post-types>` | `post` | Comma-separated post types to cover. Each must be one Co-Authors Plus is enabled for. |
| `--post-statuses=<post-statuses>` | `publish` | Comma-separated post statuses to cover, for example `publish,future,draft,pending,private`. |
| `--records-per-batch=<number>` | `250` | How many posts to fetch per batch, as a positive integer. |
| `--unbatched` | Off | Fetch every matching post in one query. Fine on a small site; on a large one it loads every row into memory at once. |
| `--specific-post-ids=<ids>` | None | Comma-separated post IDs. Only these posts are considered, and they must still match the post types and statuses. |
| `--above-post-id=<id>` | None | Only consider posts with an ID greater than this. |
| `--below-post-id=<id>` | None | Only consider posts with an ID less than this. |

`--above-post-id` and `--below-post-id` can be used alone or together. Together, they select a range, which lets you split a large backfill across several runs. The command stops with an error if `--above-post-id` isn't less than `--below-post-id`. When `--specific-post-ids` is given, the range options are ignored, with a warning.

The command stops with an error before writing anything if `--records-per-batch` isn't a positive integer, or if `--post-types` includes a post type Co-Authors Plus isn't enabled for. To list the enabled post types:

```sh
wp eval 'echo implode( ",", $GLOBALS["coauthors_plus"]->supported_post_types() );'
```

### Examples

Backfill published posts:

```sh
wp co-authors-plus create-author-terms-for-posts
```

Backfill several post types, including unpublished posts:

```sh
wp co-authors-plus create-author-terms-for-posts \
    --post-types=post,page \
    --post-statuses=publish,future,draft,pending,private
```

Try a small ID range first, to see how long a batch takes before running the whole site:

```sh
wp co-authors-plus create-author-terms-for-posts \
    --above-post-id=0 --below-post-id=10000 \
    --records-per-batch=100
```

Fix a few specific posts:

```sh
wp co-authors-plus create-author-terms-for-posts --specific-post-ids=4821,5103,5247
```

## What the command does

For each post that matches the options, has no author terms, and isn't marked to skip:

1. It loads the user in `post_author`.
2. It finds that user's author term, creating it if needed, and updates the term's description.
3. It sets the term on the post with `wp_set_object_terms()`. That fires the `set_object_terms` action, which the plugin uses to clear its cached co-authors for the post.

Term counting is deferred while the command runs, and each term is recounted once at the end. Co-Authors Plus counts an author's published posts by `post_author` as well as by term, so a term's count may not change after a backfill.

The command prints a line for each post, a line for each new batch, and a summary of how many posts it changed and skipped.

## What it doesn't do

- It doesn't change a post that already has author terms, even if they are wrong. Fix those in the editor, or with [`swap-coauthors`](./wp-cli.md#swap-coauthors).
- It doesn't create guest authors. `post_author` is always a WordPress user. To give the posts a guest author byline afterwards, create the guest author, then use [`swap-coauthors`](./wp-cli.md#swap-coauthors).
- It doesn't read authorship from post meta or anywhere else; `post_author` is its only source. To assign co-authors from post meta, use [`assign-coauthors`](./wp-cli.md#assign-coauthors).

## Posts the backfill skips

When the command can't give a post its term, it adds `_cap_skip_backfill` post meta to the post, prints a warning, and moves on. Later runs don't select marked posts. The meta value records the reason:

- `nonexistent_post_author_id`: the `post_author` user doesn't exist. Posts with a `post_author` of 0 end up here too.
- `author_term_not_created`: the user's author term couldn't be created.
- `author_term_not_set`: the term couldn't be set on the post.

To find the marked posts:

```sh
wp post list --post_type=any --post_status=any --meta_key=_cap_skip_backfill --fields=ID,post_title,post_author
```

For posts whose user was deleted, give them to an existing co-author with [`assign-user-to-coauthor`](./wp-cli.md#assign-user-to-coauthor) and its `--user_id` option. For other causes, fix the problem, clear the marker with [`delete-postmeta-that-skip-author-term-backfill`](./wp-cli.md#delete-postmeta-that-skip-author-term-backfill), and run the backfill again.

## Performance

- The command pauses for one second after every 500 posts, to ease the load on the database.
- Each batch is a fresh query for posts that still need a term, so lowering `--records-per-batch` means more, smaller queries.
- On a large site, split the work into ID ranges with `--above-post-id` and `--below-post-id`, and run them in separate sessions.
- The command prints at least one line per post. For a long run, send the output to a file: `wp co-authors-plus create-author-terms-for-posts > backfill.log 2>&1`.
- On multisite, run it once per site with `--url`. See [Multisite](./wp-cli.md#multisite).

## Related commands

- [`list-posts-without-terms`](./wp-cli.md#list-posts-without-terms): list the posts with no author terms, before and after a backfill.
- [`delete-postmeta-that-skip-author-term-backfill`](./wp-cli.md#delete-postmeta-that-skip-author-term-backfill): clear the skip marker so the backfill tries those posts again.
- [`create-terms-for-posts`](./wp-cli.md#create-terms-for-posts): an older, slower backfill that checks every post of every enabled post type. Prefer `create-author-terms-for-posts`.
- [`update-author-terms`](./wp-cli.md#update-author-terms): refresh author term descriptions and counts.
- [`assign-coauthors`](./wp-cli.md#assign-coauthors): set co-authors from a post meta value, for imports.
