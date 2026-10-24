# Blocks

Co-Authors Plus registers five blocks in the Theme category. Use them in block themes, in templates and template parts, and inside a Query Loop. For classic themes, see [Template tags](./template-tags.md).

| Block | Name | Shows |
| --- | --- | --- |
| Co-Authors | `co-authors-plus/coauthors` | A repeating template, once per co-author of the post |
| Co-Author Name | `co-authors-plus/name` | The co-author's display name |
| Co-Author Avatar | `co-authors-plus/avatar` | A small avatar |
| Co-Author Biography | `co-authors-plus/description` | The co-author's biographical info |
| Co-Author Featured Image | `co-authors-plus/image` | A guest author's featured image at a theme image size |

## Co-Authors

The Co-Authors block works like the Post Template in a Query Loop. It repeats its inner blocks once for each co-author of the post. It starts with a Co-Author Name block, and you can add any other blocks to the template. The other co-author blocks inside it receive the author they need automatically.

The block reads the post ID from its context, so it works on single post templates and inside a Query Loop. Without a post ID it outputs nothing on the front end.

It supports text, background and link colors, typography, margin, padding, block spacing and text alignment.

### Layouts

Choose a layout from the block toolbar:

| Toolbar option | Layout type |
| --- | --- |
| Inline view (default) | `default` |
| List view | `constrained` |
| Grid view | `grid` |
| Row view | `flex`, horizontal |
| Stack view | `flex`, vertical |

Inline view shows the co-authors on one wrapping line, with a prefix, separators and suffix. The other layouts place each co-author in its own block, and you can use the block spacing setting to control the gap. Build each co-author's layout with blocks like Group, Row or Stack, as you would for each post in a Query Loop.

### Prefix, separators and suffix

These only apply to the inline layout. Type the prefix and suffix directly in the block (bold, italic and text color are allowed). Set the separators in the block settings sidebar, under **Co-Authors Layout**.

| Attribute | Default | Default filter |
| --- | --- | --- |
| `prefix` | `'By '` (translatable) | [`coauthors_default_before`](./filters.md#coauthors_default_before) |
| `separator` | `', '` | [`coauthors_default_between`](./filters.md#coauthors_default_between) |
| `lastSeparator` | `' and '` (translatable) | [`coauthors_default_between_last`](./filters.md#coauthors_default_between_last) |
| `suffix` | `''` | [`coauthors_default_after`](./filters.md#coauthors_default_after) |

The filters set the attribute defaults when the block is registered on `init`. They are the same filters the template tags use, but the block's prefix default is `'By '` where the template tags' is `''`. If `lastSeparator` is empty, `separator` is used before the last co-author.

The output uses these classes:

- `wp-block-co-authors-plus-coauthor` wraps each co-author.
- `wp-block-co-authors-plus-coauthors__prefix`, `__separator` and `__suffix` wrap the prefix, each separator and the suffix.

### Where the data comes from

When it renders, the block requests the post's co-authors from the `coauthors/v1/coauthors?post_id={id}` endpoint, so the front end matches what the editor shows. Filters on that endpoint, such as `rest_coauthors_prepare_items` and `rest_prepare_coauthor`, change the block output too. See the [REST API reference](./rest-api.md).

The block renders its inner template once per co-author, with that co-author in context. Core's usual separate pass over the inner blocks is skipped, so inner blocks are never rendered without an author.

## Co-Author Name

Shows the co-author's display name, and can link it to their author archive.

| Attribute | Default | Description |
| --- | --- | --- |
| `isLink` | `false` | Link the name to the co-author's archive. |
| `rel` | `'author'` | `rel` attribute for the link. |
| `tagName` | `'p'` | HTML element for the name. |
| `textAlign` | | Text alignment. |

Choose the HTML element under the block's **Advanced** settings. The editor offers `p`, `span` and `h1` to `h6`. A heading is useful on an author archive, for example. It supports colors, typography, margin and padding.

## Co-Author Avatar

Like the Avatar block in core, this shows a small square image of the co-author. It uses Gravatar, with the default avatar from your site's Discussion settings. For a guest author with a featured image, it uses that image instead.

| Attribute | Default | Description |
| --- | --- | --- |
| `size` | `24` | Width and height in pixels. |
| `isLink` | `false` | Link the image to the co-author's archive. |
| `rel` | | `rel` attribute for the link. |
| `align` | | Alignment, in the layouts other than inline. |
| `verticalAlign` | | Vertical alignment, in the inline layout. |

The available sizes come from `rest_get_avatar_sizes()` (24, 48 and 96 by default). To change them, use the [`rest_avatar_sizes`](https://developer.wordpress.org/reference/hooks/rest_avatar_sizes/) filter. The block outputs nothing when **Show Avatars** is turned off in the Discussion settings. It supports border, margin and padding.

## Co-Author Biography

Shows the co-author's biographical info, from their user profile or guest author profile. The text is passed through `wptexturize()` and `wpautop()`, then `wp_kses_post()`. It supports text alignment, colors, typography, margin and padding.

## Co-Author Featured Image

Shows a guest author's featured image (the avatar uploaded to the media library) using your theme's and site's image sizes. Use it for a larger or higher resolution image than the Co-Author Avatar block. It only works with [guest authors](./guest-authors.md): it does not use Gravatar, and outputs nothing for registered users or guest authors without a featured image.

| Attribute | Default | Description |
| --- | --- | --- |
| `sizeSlug` | `'thumbnail'` | Image size. |
| `width`, `height` | | CSS width and height. |
| `aspectRatio` | | CSS aspect ratio. |
| `scale` | `'cover'` | CSS `object-fit`. |
| `isLink` | `false` | Link the image to the co-author's archive. |
| `rel` | | `rel` attribute for the link. |
| `align` | | Alignment, in the layouts other than inline. |
| `verticalAlign` | | Vertical alignment, in the inline layout. |

It supports border, margin and padding.

## Block context

### Posts, pages and Query Loops

Blocks receive the post context. The Co-Authors block uses the post ID to find the co-authors, then renders each copy of its inner blocks with its own context, including `postId` and `postType`, plus two more values:

- `co-authors-plus/author`: the co-author, shaped like a response from the `coauthors/v1/coauthors` endpoint.
- `co-authors-plus/layout`: the Co-Authors block's layout type, such as `default`.

### Author archives

To show an author's details on their own archive, use the co-author blocks directly, without a Co-Authors block around them. On an author archive, Co-Authors Plus gives `co-authors-plus/author` context to any block that uses it and doesn't already have it. The author is the archive's queried author, which the plugin resolves from the `author_name` query var. It is added to the block's existing context, so a block in a Query Loop's post template still receives `postId` and `postType`.

### Using the author context in your own block

Add `co-authors-plus/author` to `usesContext` in your block's `block.json`:

```json
{
	"usesContext": [ "co-authors-plus/author" ]
}
```

## Example data

In the Site Editor, and in the post editor before the co-authors have loaded, the blocks show placeholder data. It is shaped like a response from the `coauthors/v1/coauthors/{user_nicename}` endpoint.

If your plugin adds fields to that REST response, add them to the placeholder too. Use the [`coauthors_blocks_store_data`](./filters.md#coauthors_blocks_store_data) filter on the server, or the [`co-authors-plus.author-placeholder`](./filters.md#co-authors-plusauthor-placeholder-javascript) filter in JavaScript.

## Turning the blocks off

To stop Co-Authors Plus registering its blocks, return `false` from the [`coauthors_plus_support_blocks`](./filters.md#coauthors_plus_support_blocks) filter. The filter runs on `init`, so add it in a plugin or your theme's `functions.php`.

```php
add_filter( 'coauthors_plus_support_blocks', '__return_false' );
```

## Block editor data store

The 4.0 release removed the sidebar's `cap/authors` store. See [Upgrading to 4.0](./upgrading-to-4.0.md#block-editor-data-store-is-now-the-core-entity-store).
