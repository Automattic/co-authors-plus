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

See [`coauthors_plus_should_query_post_author`](./filters.md#coauthors_plus_should_query_post_author) in the reference, and [Backfilling author terms for existing posts](./backfilling-author-terms.md).

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

For full control over the markup, use the [template tags](./template-tags.md) or the [blocks](./blocks.md) instead.

See [`coauthors_auto_apply_template_tags`](./filters.md#coauthors_auto_apply_template_tags) in the reference.

## Can I show more about each author in the co-author search?

Yes. By default, the block editor shows each author as `Name | email`, and the classic editor shows just the name. To tell apart authors with the same name, for example guest authors who have no email address, you can add something like a job title.

In the block editor, add the value to the author data in PHP, then show it in the label with JavaScript:

```php
add_filter( 'coauthors_rest_author_data', function ( $data, $author ) {
	// Guest authors are posts, and their fields use the `cap-` prefix.
	$data['jobTitle'] = $author instanceof WP_User
		? get_user_meta( $author->ID, 'job_title', true )
		: get_post_meta( $author->ID, 'cap-job_title', true );
	return $data;
}, 10, 2 );
```

```js
import { addFilter } from '@wordpress/hooks';

addFilter( 'coAuthors.formatAuthorData.label', 'site/label', ( label, author ) =>
	author.jobTitle ? `${ author.displayName } (${ author.jobTitle })` : label );
```

The `import` line needs a build step. Without one, call `wp.hooks.addFilter()` instead, in a script you enqueue on `enqueue_block_editor_assets` with `wp-hooks` as a dependency.

The classic editor has no JavaScript label filter, so change the `label` in PHP. It can be output as HTML, so keep it plain text:

```php
add_filter( 'coauthors_ajax_suggest_author_data', function ( $result, $author ) {
	$job_title = $author instanceof WP_User
		? get_user_meta( $author->ID, 'job_title', true )
		: get_post_meta( $author->ID, 'cap-job_title', true );
	$job_title = sanitize_text_field( $job_title );
	if ( $job_title ) {
		$result['label'] .= ' (' . $job_title . ')';
	}
	return $result;
}, 10, 2 );
```

See [`coauthors_rest_author_data`](./filters.md#coauthors_rest_author_data), [`coAuthors.formatAuthorData.label`](./filters.md#coauthorsformatauthordatalabel) and [`coauthors_ajax_suggest_author_data`](./filters.md#coauthors_ajax_suggest_author_data) in the reference.

## Can I hide email addresses in the co-author search?

Partly. You can remove the email address from the author data that both editors get from the co-author search:

```php
add_filter( 'coauthors_rest_author_data', function ( $data ) {
	unset( $data['email'] );
	return $data;
} );

add_filter( 'coauthors_ajax_suggest_author_data', function ( $result ) {
	unset( $result['email'] );
	return $result;
} );
```

The block editor then shows only the display name. The email is what tells apart two authors with the same name, so consider showing something else in its place, as in the recipe above.

This is not a privacy or access-control setting. Editors can still see co-author email addresses in other places, like the classic editor Authors box and the posts list, and the search still matches on email addresses.

See [`coauthors_rest_author_data`](./filters.md#coauthors_rest_author_data) and [`coauthors_ajax_suggest_author_data`](./filters.md#coauthors_ajax_suggest_author_data) in the reference.

## Can I use a visual editor for the guest author bio?

Yes. The Biographical Info field accepts the same HTML as post content, such as links and lists, but by default it's a plain text area. To edit it with the classic visual editor instead:

```php
add_filter( 'coauthors_name_field_type_cap-description', function () {
	return 'editor';
} );
```

The editor has a reduced toolbar and no Add Media button. What's saved doesn't change: the bio is still cleaned with `wp_kses_post()`, so markup that post content wouldn't allow is removed.

The same works for any field you add to the `about` group. See [`coauthors_name_field_type_{$field}`](./filters.md#coauthors_name_field_type_field) in the reference.
