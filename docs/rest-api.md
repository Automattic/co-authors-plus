# REST API

Co-Authors Plus participates in the REST API in two ways: through core WordPress endpoints (exposed because the `author` taxonomy and the `coauthors` field are `show_in_rest => true`), and through its own `coauthors/v1` namespace.

## Core WordPress endpoints

Since the block editor integration landed in 4.0, the preferred way to read and write co-authors is through the existing core endpoints.

### `GET /wp/v2/coauthors`

Lists the `author` taxonomy terms — one per co-author. Each response item is a standard WP taxonomy term: `id`, `name`, `slug`, `description`, `taxonomy`, etc.

This is the endpoint to use when you want to enumerate all co-authors on a site.

The term `description` holds the plugin's search index for the author (names, login, ID and email). It is removed from the response for users without the `edit_posts` capability. Change that capability with [`coauthors_rest_view_description_cap`](./filters.md#coauthors_rest_view_description_cap).

### `GET /wp/v2/coauthors/{id}`

Returns a single taxonomy term.

### `GET /wp/v2/posts/{id}`

The post response gains a `coauthors` field containing the array of taxonomy term IDs for that post.

```json
{
  "id": 42,
  "title": { "rendered": "Example post" },
  "coauthors": [ 13, 15, 11, 3 ]
}
```

### `PUT /wp/v2/posts/{id}`

Writable for the same field. Send an array of term IDs to set the co-authors, in the desired order.

```json
{ "coauthors": [ 3, 11 ] }
```

This is the endpoint the 4.0 block editor sidebar writes to. The list order is preserved and becomes the byline order.

Co-Authors Plus also keeps `post_author` in step with the new list, using a co-author that is, or is linked to, a WordPress user. A byline of only unlinked guest authors leaves `post_author` unchanged.

If you narrow a post response with `_fields`, include `coauthors` when you need it; otherwise the field is left out. Co-Authors Plus 4.0.0 wrongly treated a missing field as "no co-authors" and overwrote them. See [Recovering from the 4.0.0 REST backfill regression](./recovering-from-4.0.0.md).

## Plugin endpoints (`coauthors/v1`)

These predate the core-integration approach and are used internally by the admin UIs. They remain supported.

### `GET /coauthors/v1/coauthors?post_id={id}`

Returns the co-authors of a given post, in byline order. Each item has `id`, `display_name`, `description` (`raw` and `rendered`), `user_nicename`, `link`, `featured_media` and, when the site shows avatars, `avatar_urls`.

```json
[
  {
    "id": 42,
    "display_name": "Ada Lovelace",
    "description": {
      "raw": "First programmer.",
      "rendered": "<p>First programmer.</p>\n"
    },
    "user_nicename": "ada-lovelace",
    "link": "https://example.com/author/ada-lovelace/",
    "featured_media": 108,
    "avatar_urls": { "24": "…", "48": "…", "96": "…" }
  }
]
```

- `id` is the user ID for a registered user, or the guest author's post ID.
- `description.raw` is the user's biographical info, or the guest author's description field. It is returned to every requester who passes the permission check below.
- `featured_media` is the guest author's avatar image (the post thumbnail ID), or `0`. It is always `0` for registered users.
- The response has no email address.

**Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `post_id` | integer | yes | ID of the post whose co-authors to return. |

**Permission**

Available to anonymous requesters when the post is publicly viewable (`is_post_publicly_viewable()`). Otherwise the requester must have `read_post` permission for that post ID. Tightened in 4.0 to stop draft/private/scheduled posts from leaking author data.

A missing post returns `rest_post_invalid_id` (404). A post the requester can't read returns `rest_forbidden` (401 or 403).

The response array passes through the `rest_coauthors_prepare_items` filter (added in 4.2.0) before serialization, which is the supported extension point for limiting, reordering, or replacing the returned list. The Co-Authors block is the main consumer of this endpoint, but the filter applies to every response from it. See [filters.md](./filters.md).

**Defined in** `php/api/endpoints/class-coauthors-controller.php`.

### `GET /coauthors/v1/coauthors/{user_nicename}`

Returns a single co-author by user nicename (slug), in the same shape as an item from the endpoint above. The nicename is sanitized with `sanitize_title()`.

**Permission**

Available to anonymous requesters when the author has at least one publicly viewable post. Otherwise the requester must be able to set authors (`edit_others_posts` by default, filterable via `coauthors_plus_edit_authors`).

"Publicly viewable post" here means a published post, of a public post type, that has the author's term. An unknown nicename returns `rest_not_found` (404) to requesters who can set authors, and `rest_forbidden` to everyone else.

### `GET /coauthors/v1/search?q={text}`

Searches registered users and guest authors. Used by the sidebar autocomplete.

**Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `q` | string | no | Search text. Matched against the author's display name, first and last name, login, ID and email. |
| `existing_authors` | string | no | Comma-separated list of `user_nicename` values to exclude from results. The sidebar sends the co-authors already on the post. |

Returns up to 10 authors (see [`coauthors_search_authors_get_terms_args`](./filters.md#coauthors_search_authors_get_terms_args)), in the same shape as the `authors-by-term-ids` response below. Registered users without the `edit_posts` capability are left out (see [`coauthors_edit_author_cap`](./filters.md#coauthors_edit_author_cap)).

**Permission:** the requester must be able to set authors on at least one supported post type, for example with `edit_others_posts` or `edit_others_pages`, filterable via `coauthors_plus_edit_authors`.

### `GET /coauthors/v1/authors-by-term-ids?ids={csv}`

Resolves a comma-separated list of `author` taxonomy term IDs to rich author data. New in 4.0 — this is the endpoint the block editor sidebar uses to hydrate the `coauthors` field read from the core entity store.

**Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `ids` | string | yes | Comma-separated term IDs. |

**Permission:** the requester must be able to set authors, as for `search`.

**Response**

An array of author objects, each shaped like:

```json
{
  "id": "42",
  "termId": 13,
  "userNicename": "anna-example",
  "login": "anna",
  "email": "anna@example.com",
  "displayName": "Anna Example",
  "avatar": "https://secure.gravatar.com/avatar/…",
  "userType": "wpuser"
}
```

`userType` is `wpuser` for registered users and `guest-author` for guest authors.

Term IDs that don't resolve to an author are skipped. The `search`, `authors-by-term-ids` and `authors/{post_id}` endpoints all return this shape, and it can be changed with [`coauthors_rest_author_data`](./filters.md#coauthors_rest_author_data).

### `GET /coauthors/v1/authors/{post_id}`

Returns rich co-author data for a post. Similar to `/coauthors/v1/coauthors?post_id=` but older and protected by the author-editing capability for the post's type (such as `edit_others_pages` for a page) rather than public-visibility.

**Permission:** the requester must be able to edit the post (`edit_post`) and to set authors.

It returns an empty array for synced patterns (`wp_block` posts), and for requests whose referer is the editor for a synced pattern.

### `POST /coauthors/v1/authors/{post_id}`

Writes co-authors to a post. Used by the classic-editor meta box.

**Permission:** the requester must be able to edit the post (`edit_post`) and to set authors.

**Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `post_id` | integer | yes | Post to update. |
| `new_authors` | string | no | Comma-separated list of author nicenames. |

The list replaces the post's co-authors and sets the byline order. The response is the post's co-authors after the update, in the same shape as `authors-by-term-ids`. If `new_authors` is empty or missing, nothing changes and the response is an empty array.

## Filters

Responses from the plugin endpoints can be modified with:

- `rest_coauthors_item_schema` — the resource schema
- `rest_prepare_coauthor` — the response for a single co-author

See [filters.md](./filters.md) for the full list.

`rest_coauthors_item_schema` can change existing properties, but adding a new property through it triggers a `_doing_it_wrong()` notice. To add a field to the `coauthors/v1/coauthors` responses, register it with `register_rest_field()` on the `coauthor` object type, or add it in `rest_prepare_coauthor`.

## See also

- [Upgrading to 4.0](./upgrading-to-4.0.md) — migrating from the removed `cap/authors` store to the core entity store
- [Filters and actions](./filters.md)
