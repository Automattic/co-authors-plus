# Upgrading to Co-Authors Plus 4.0

Version 4.0 is the first major release in six years. Most sites will upgrade without intervention, but there are a handful of changes that can affect third-party code or downstream plugins. This guide covers each of them and what to do.

If you only need the bullet-point view, the [CHANGELOG entry for 4.0.0](../CHANGELOG.md) lists every change; this document focuses on the ones likely to require action.

## Minimum WordPress 6.4

The minimum supported WordPress version moved from 5.9 to 6.4. 6.4 has been the practical floor for the block editor integration for some time; this release makes it explicit. If you cannot upgrade WordPress, stay on the 3.7 branch.

## Installing with Composer

The compiled JavaScript and CSS in `build/` are no longer committed to Git. They are built when a release is deployed, so the WordPress.org release includes them, but the `automattic/co-authors-plus` package on Packagist does not: Packagist serves GitHub's archive of the tagged source, which has no `build/` directory.

**Why it matters.** Without `build/`, the plugin's blocks aren't registered and the block editor's Authors panel doesn't load, so blocks that show bylines render nothing and editors can't change a post's co-authors in the block editor. The classic editor and the template tags still work. From 4.2.0, administrators see a notice when `build/` is missing.

**If you install Co-Authors Plus with Composer,** require the WordPress.org release through [WPackagist](https://wpackagist.org/) instead, and remove `automattic/co-authors-plus`:

```json
{
	"repositories": [
		{
			"type": "composer",
			"url": "https://wpackagist.org",
			"only": [ "wpackagist-plugin/*", "wpackagist-theme/*" ]
		}
	],
	"require": {
		"wpackagist-plugin/co-authors-plus": "^4.1"
	}
}
```

[WP Packages](https://wp-packages.org/) works the same way, with `https://repo.wp-packages.org` as the URL and `wp-plugin/co-authors-plus` as the package name.

**If you work from a Git checkout,** or install `automattic/co-authors-plus` with `--prefer-source`, run `npm ci && npm run build` in the plugin directory. The Packagist archive can't be built in place, because it leaves out the JavaScript source.

## Block editor data store is now the core entity store

Before 4.0, the sidebar maintained a custom Redux store under the `cap/authors` namespace for the authors of the post being edited. That store has been removed. Co-author data now reads and writes through WordPress's own `core/editor` store, the same way any other post attribute does.

**Why it matters.** The old arrangement was invisible to the block editor's autosave, revision, and collaborative-editing machinery, which is why the Save button never lit up when you changed the co-authors. Using the core entity store is what makes Co-Authors Plus compatible with real-time collaboration.

**If you called `select( 'cap/authors' )`:**

```js
// Before 4.0
const authors = wp.data.select( 'cap/authors' ).getAuthors();

// 4.0 onwards
const termIds = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'coauthors' );
```

**If you dispatched to `cap/authors`:**

```js
// Before 4.0
wp.data.dispatch( 'cap/authors' ).setAuthors( newAuthors );

// 4.0 onwards
wp.data.dispatch( 'core/editor' ).editPost( { coauthors: termIds } );
```

`getEditedPostAttribute( 'coauthors' )` returns an array of taxonomy term IDs. To resolve those to rich author data (display name, avatar, etc.), use the new `authors-by-term-ids` REST endpoint — see [REST API reference](./rest-api.md).

## Guest-author custom post type is now private

The `guest-author` CPT was registered with `public => true` since forever, which meant it leaked into anything that scanned the list of public post types — most visibly, Yoast SEO's sitemap and content analysis. As of 4.0 it's registered with `public => false` and `show_ui => true`. The admin editor continues to work, but front-end queries for `?post_type=guest-author` no longer resolve.

**If you were implicitly relying on this** (for example, custom code iterating `get_post_types( [ 'public' => true ] )`), the CPT will no longer appear. Either consume guest authors through the plugin's documented template tags, opt the CPT back in with `register_post_type_args`, or filter it into your own list.

**Verifying the change on your site:**

```php
$args = get_post_type_object( 'guest-author' );
var_dump( $args->public, $args->publicly_queryable, $args->show_ui, $args->has_archive );
// bool(false) bool(false) bool(true) bool(false)
```

## `COAUTHORS_PLUS_PATH` and `COAUTHORS_PLUS_URL` removed

The `deprecated.php` file has been deleted. The two constants it defined were moved into that file in 2013 and have been unreferenced in the plugin ever since. If any third-party code still uses them, switch to WordPress's `plugin_dir_path()` and `plugin_dir_url()`.

## Filters deprecated on REST saves

Two filters are now soft-deprecated when a post is saved through the REST API:

- `coauthors_post_list_pluck_field`
- `coauthors_post_get_coauthor_by_field`

They still fire and still return their filtered values; the change is that a `_deprecated_hook` notice is emitted and both will be removed in a future release. The replacement is the WordPress core `set_object_terms` action on the `author` taxonomy, which runs on any save path (REST, classic editor, CLI).

```php
// Before 4.0 — specific to REST saves
add_filter( 'coauthors_post_list_pluck_field', function () {
    return 'user_nicename';
} );

// 4.0 onwards — covers every save path
add_action( 'set_object_terms', function ( $object_id, $terms, $tt_ids, $taxonomy ) {
    if ( 'author' !== $taxonomy ) {
        return;
    }
    // Your logic here.
}, 10, 4 );
```

## Reference

- [CHANGELOG.md](../CHANGELOG.md)
- [Filters and actions](./filters.md)
- [REST API](./rest-api.md)
