# Template tags

Co-Authors Plus saves bylines in the admin, but a classic theme only shows them once it calls the plugin's template tags. Core tags such as `the_author()` and `the_author_posts_link()` only know about one author per post.

The template tags are global functions defined in `template-tags.php`. Use them anywhere you would normally output the author.

- **Block themes:** the [Co-Authors blocks](./blocks.md) are usually a better fit. They receive the same post context and need no PHP.
- **JavaScript and external clients:** read the `coauthors` field on the core post resource (`GET /wp/v2/posts/{id}`), then resolve the term IDs with the `coauthors/v1/authors-by-term-ids` endpoint. See the [REST API reference](./rest-api.md).

## Integrating template tags into your theme

Replace the theme's author template tags with a conditional that uses the Co-Authors Plus tag when the plugin is active. The `function_exists()` check stops the site breaking if the plugin is deactivated.

For example, to replace `the_author_posts_link()` with `coauthors_posts_links()`:

```php
if ( function_exists( 'coauthors_posts_links' ) ) {
	coauthors_posts_links();
} else {
	the_author_posts_link();
}
```

This is a simple byline. Your theme will probably need its own markup around it.

### Classic themes

If a classic theme already calls `the_author_posts_link()` inside its own function, filtering the core output is often the least invasive change. `coauthors_posts_links()` returns its output when the fifth argument is `false`:

```php
add_filter( 'the_author_posts_link', function ( $link ) {
	if ( ! function_exists( 'coauthors_posts_links' ) ) {
		return $link;
	}

	return coauthors_posts_links( null, null, null, null, false );
} );
```

The plugin can also filter `the_author` and `the_author_posts_link` for you, and add every co-author to RSS feeds, when the `coauthors_auto_apply_template_tags` filter returns `true`. See [Filters and actions](./filters.md).

### Block themes

Use the [Co-Authors blocks](./blocks.md) in your byline template parts.

## Byline template tags

These tags output the co-authors of the current post in the Loop. They all share the same signature:

```php
coauthors_posts_links( $between = null, $betweenLast = null, $before = null, $after = null, $echo = true );
```

| Tag | Outputs each co-author as | Core equivalent |
| --- | --- | --- |
| `coauthors()` | Display name, no link | `the_author()` |
| `coauthors_posts_links()` | Display name linked to their author archive | `the_author_posts_link()` |
| `coauthors_links()` | Display name linked to their website, or plain name if they have none | `the_author_link()` |
| `coauthors_firstnames()` | First name | |
| `coauthors_lastnames()` | Last name | |
| `coauthors_nicknames()` | Nickname | |
| `coauthors_emails()` | Email address | |
| `coauthors_ids()` | ID (a user ID, or the post ID of a guest author) | |

### Parameters

| Name | Default | Description |
| --- | --- | --- |
| `$between` | `', '` | Placed between co-authors. |
| `$betweenLast` | `' and '` (translatable) | Placed between the last two co-authors. |
| `$before` | `''` | Placed before the list. |
| `$after` | `''` | Placed after the list. |
| `$echo` | `true` | Whether to echo the output. The output is always returned as well. |

Pass `null` to use the default. The defaults come from the `COAUTHORS_DEFAULT_BEFORE`, `COAUTHORS_DEFAULT_BETWEEN`, `COAUTHORS_DEFAULT_BETWEEN_LAST` and `COAUTHORS_DEFAULT_AFTER` constants when they are defined, and then pass through the [`coauthors_default_before`](./filters.md#coauthors_default_before), [`coauthors_default_between`](./filters.md#coauthors_default_between), [`coauthors_default_between_last`](./filters.md#coauthors_default_between_last) and [`coauthors_default_after`](./filters.md#coauthors_default_after) filters.

### Output

```php
coauthors_posts_links( ', ', ' and ', 'By ', '.' );
// By <a …>Anna Example</a>, <a …>Ben Example</a> and <a …>Cara Example</a>.
```

A few details:

- If a co-author's value is empty (for example, no first name), the tag outputs their `user_login` instead.
- Every tag escapes the co-author values it outputs. The `$between`, `$betweenLast`, `$before` and `$after` separators are output as given, so they can contain markup.
- `coauthors_posts_links()` adds `rel="author"`, `class="author url fn"` and a "Posts by …" title to each link. Change these with the [`coauthors_posts_link`](./filters.md#coauthors_posts_link) filter.
- `coauthors_links()` uses a guest author's Website field, or a user's `user_url`. Each link has `rel="author external"`.
- `coauthors_posts_links_single( $author )` and `coauthors_links_single( $author )` return the markup for one co-author object.

## Getting co-author data

### `get_coauthors()`

```php
get_coauthors( $post_id = 0 );
```

Returns an array of co-author objects for a post, in byline order. With no `$post_id`, it uses the current post. Registered users are `WP_User` objects and guest authors are plain objects with `type` set to `guest-author`. A user who is linked to a guest author is returned as the guest author.

If the post has no co-authors assigned, it falls back to the post's `post_author`, unless guest authors are forced with `coauthors_guest_authors_force`. The result passes through the [`get_coauthors`](./filters.md#get_coauthors) filter.

Use it when you need markup that the byline tags don't provide:

```php
foreach ( get_coauthors() as $coauthor ) {
	printf(
		'<a href="%1$s">%2$s %3$s</a>',
		esc_url( get_author_posts_url( $coauthor->ID, $coauthor->user_nicename ) ),
		coauthors_get_avatar( $coauthor, 48 ),
		esc_html( $coauthor->display_name )
	);
}
```

### `is_coauthor_for_post()`

```php
is_coauthor_for_post( $user, $post_id = 0 );
```

Returns `true` if `$user` is a co-author of the post, either directly or through a linked guest author. `$user` is a user ID or a co-author object. With no `$post_id`, it uses the current post.

### `get_coauthor_role()`

```php
get_coauthor_role( $coauthor, $post_id = 0 );
```

Returns a co-author's role on a post, like `Photographer`, or an empty string when none is set. `$coauthor` is a co-author object, for example from `get_coauthors()`. With no `$post_id`, it uses the current post. Roles are set per post in the Authors box. The value is raw, so escape it on output:

```php
foreach ( get_coauthors() as $coauthor ) {
	$role = get_coauthor_role( $coauthor );
	echo esc_html( $coauthor->display_name );
	if ( $role ) {
		echo ', ' . esc_html( $role );
	}
}
```

### `get_the_coauthor_meta()` and `the_coauthor_meta()`

```php
get_the_coauthor_meta( $field, $user_id = false );
the_coauthor_meta( $field, $user_id = 0 );
```

`get_the_coauthor_meta()` returns an array of the field's value for each co-author of the current post, keyed by co-author ID. Pass `$user_id` to look up a single co-author instead. The short names `login`, `pass`, `nicename`, `email`, `url`, `registered`, `activation_key` and `status` map to the matching `user_` field. `url` returns a guest author's Website field.

`the_coauthor_meta()` echoes each value, escaped, with no separator between them.

### `coauthors_get_avatar()`

```php
coauthors_get_avatar( $coauthor, $size = 32, $default = '', $alt = false, $class = null );
```

Returns (does not echo) an avatar `<img>` tag for a co-author object. Use it instead of `get_avatar()`, because guest authors can share an email address.

- For a guest author with a featured image, it returns that image at `$size` by `$size`, with the classes `avatar avatar-{$size} photo` plus `$class`. `$default` and `$alt` are not used.
- Otherwise it calls `get_avatar()` with the co-author's email address.
- It returns an empty string if `$coauthor` is not an object, or has no `user_email` property.

## Create a list of all co-authors

`coauthors_wp_list_authors()` lists the site's co-authors, each linked to their author archive. It accepts many of the same arguments as `wp_list_authors()`.

```php
coauthors_wp_list_authors( $args = array() );
```

| Argument | Default | Description |
| --- | --- | --- |
| `optioncount` | `false` | Show the published post count in brackets after each name. |
| `show_fullname` | `false` | Show "First Last" instead of the display name, when both names are set. |
| `hide_empty` | `true` | Leave out co-authors with no published posts. This is applied after the `number` limit. |
| `feed` | `''` | If set, add a feed link after each name, using this as its text. |
| `feed_image` | `''` | If set, use this image URL for the feed link. |
| `feed_type` | `''` | Feed type passed to `get_author_feed_link()`. |
| `echo` | `true` | Echo the output. If `false`, return it. |
| `style` | `'list'` | `'list'` wraps each co-author in `<li>`, so add your own `<ul>` around it. Any other value separates co-authors with commas. |
| `html` | `true` | If `false`, output plain names separated by commas, with no links. |
| `number` | `20` | Maximum number of co-authors to fetch. |
| `guest_authors_only` | `false` | Only list guest authors. |
| `authors_with_posts_only` | `false` | Leave out co-authors with no posts in the query itself, before `number` is applied. |
| `orderby` | `'name'` | Passed to `get_terms()`. Author term names are user logins, so `'name'` sorts by login, not display name. |

A user who is linked to a guest author is listed once, as the guest author. Post counts follow the [`coauthors_count_published_post_types`](./filters.md#coauthors_count_published_post_types) filter.

```php
<ul class="authors">
	<?php coauthors_wp_list_authors( array( 'optioncount' => true ) ); ?>
</ul>
```

To change the list before it is rendered, use the [`coauthors_wp_list_authors_array`](./filters.md#coauthors_wp_list_authors_array) filter. To get the co-author objects without any markup, call `coauthors_get_users( $args )`, which accepts `number`, `guest_authors_only`, `authors_with_posts_only` and `orderby`, and sets a `post_count` property on each co-author.

## Adding co-authors to custom post types

Co-Authors Plus attaches to every post type that supports `author`. To show the Authors box on your custom post type, include `'author'` in `supports` when you call `register_post_type()`, or call `add_post_type_support( 'my_cpt_slug', 'author' )`. The support must be in place before the plugin registers its taxonomy on `init`. See [`coauthors_supported_post_types`](./filters.md#coauthors_supported_post_types) for the timing and the post types that are always excluded.

Published post counts only include `post` by default. To count posts of your custom post type too, use the `coauthors_count_published_post_types` filter:

```php
add_filter( 'coauthors_count_published_post_types', function ( $post_types ) {
	$post_types[] = 'my_cpt_slug';
	return $post_types;
} );
```
