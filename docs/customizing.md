# Customizing Co-Authors Plus

These recipes cover common changes that need a filter. Add the code to a plugin or to your theme's `functions.php`. Each recipe links to its filter in the [filters and actions reference](./filters.md), which has the full details.

## Can I make every byline come from guest authors only?

Yes. Some sites keep all bylines as guest authors and use WordPress accounts only for logging in. In that case you don't want the plugin to fall back to the post's `post_author` user when a post has no co-authors. Add this before `init`:

```php
add_filter( 'coauthors_guest_authors_force', '__return_true' );
```

With this on, a post with no co-authors shows no byline instead of the WordPress user who created it, and author queries ignore the `post_author` column. In the classic editor, the Authors box on a new post starts empty unless your account is linked to a guest author. It only works while guest authors are enabled.

Note that this does not stop Co-Authors Plus from adding the post's creator as a co-author when a post is saved with none. It mostly matters for older posts that have no co-authors yet.

See [`coauthors_guest_authors_force`](./filters.md#coauthors_guest_authors_force) in the reference.

## How do I stop author archives from matching on `post_author`?

By default, an author archive shows posts where the author is assigned as a co-author, plus posts where they are the WordPress `post_author`. If your `post_author` values are not reliable (for example after an import, or when an editor creates posts for other people), you can match on co-authors only. Add this before `init`:

```php
add_filter( 'coauthors_plus_should_query_post_author', '__return_false' );
```

This also gives a simpler author query. Posts that were never given a co-author will drop out of author archives, so give them co-authors before you add the filter. Don't backfill them all from `post_author`: the backfill takes each post's co-author straight from `post_author`, so it would copy the unreliable value into the byline. Running it again won't correct it either, because it skips any post that already has author terms.

1. Find the posts with no co-authors with `wp co-authors-plus list-posts-without-terms`. It includes drafts and other unpublished posts, but only the `post` post type unless you pass `--post_type`, for example `--post_type=page`.
2. Fix the bylines of the posts where `post_author` is wrong, for example in the editor.
3. Run `wp co-authors-plus create-author-terms-for-posts` for the rest, where `post_author` is known to be right. The posts you fixed in step 2 now have author terms, so it skips them. Unlike the older `create-terms-for-posts`, which covers every supported post type, it only covers published posts of the `post` post type unless you pass `--post-types` and `--post-statuses`, for example `--post-types=post,page --post-statuses=publish,draft,pending,private`.

See [`coauthors_plus_should_query_post_author`](./filters.md#coauthors_plus_should_query_post_author) in the reference, and the wiki's [Backfilling coauthor terms for legacy posts](https://github.com/Automattic/Co-Authors-Plus/wiki/Backfilling-coauthor-terms-for-legacy-posts).

## Can I stop Co-Authors Plus changing one specific query?

Yes. Co-Authors Plus rewrites author queries (author archives and `WP_Query` calls with `author`, `author_name` or `author__in`) so they find co-authored posts. If you have a query that should only look at `post_author`, for example a report of who created each post, you can opt that one query out:

```php
add_filter( 'coauthors_plus_is_author_query', function ( $is_author, $query ) {
	if ( $query->get( 'my_report' ) ) {
		return false;
	}
	return $is_author;
}, 10, 2 );

$report = new WP_Query( array( 'author' => 5, 'my_report' => true ) );
```

See [`coauthors_plus_is_author_query`](./filters.md#coauthors_plus_is_author_query) in the reference. For more on author queries, see [Querying posts by author or co-author](./querying-posts.md).

## Can I show all co-authors without editing my theme?

Partly. If your theme uses `the_author()` and `the_author_posts_link()`, you can make them show all co-authors by adding this before `init`. It also lists every co-author as a `dc:creator` in RSS2 feeds.

```php
add_filter( 'coauthors_auto_apply_template_tags', '__return_true' );
```

For full control over the markup, use the [template tags](https://github.com/Automattic/Co-Authors-Plus/wiki/Template-tags) or the [blocks](https://github.com/Automattic/Co-Authors-Plus/wiki/Blocks) instead.

See [`coauthors_auto_apply_template_tags`](./filters.md#coauthors_auto_apply_template_tags) in the reference.
