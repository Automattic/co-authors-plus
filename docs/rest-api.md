# REST API

Co-Authors Plus participates in the REST API in two ways: through core WordPress endpoints (exposed because the `author` taxonomy and the `coauthors` field are `show_in_rest => true`), and through its own `coauthors/v1` namespace.

## Core WordPress endpoints

Since the block editor integration landed in 4.0, the preferred way to read and write co-authors is through the existing core endpoints.

### `GET /wp/v2/coauthors`

Lists the `author` taxonomy terms — one per co-author. Each response item is a standard WP taxonomy term: `id`, `name`, `slug`, `description`, `taxonomy`, etc.

This is the endpoint to use when you want to enumerate all co-authors on a site.

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

## Plugin endpoints (`coauthors/v1`)

These predate the core-integration approach and are used internally by the admin UIs. They remain supported.

### `GET /coauthors/v1/coauthors?post_id={id}`

Returns rich co-author data (display name, avatar, email, etc.) for a given post.

**Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `post_id` | integer | yes | ID of the post whose co-authors to return. |

**Permission**

Available to anonymous requesters when the post is publicly viewable (`is_post_publicly_viewable()`). Otherwise the requester must have `read_post` permission for that post ID. Tightened in 4.0 to stop draft/private/scheduled posts from leaking author data.

**Defined in** `php/api/endpoints/class-coauthors-controller.php`.

### `GET /coauthors/v1/coauthors/{user_nicename}`

Returns a single co-author by user nicename (slug).

**Permission**

Available to anonymous requesters when the author has at least one publicly viewable post. Otherwise the requester must be able to set authors (`edit_others_posts` by default, filterable via `coauthors_plus_edit_authors`).

### `GET /coauthors/v1/search?q={text}`

Searches registered users and guest authors. Used by the sidebar autocomplete.

**Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `q` | string | yes | Search text. |
| `existing_authors` | string | no | Comma-separated list of author IDs to exclude from results. |

**Permission:** same as below — `edit_others_posts` via `coauthors_plus_edit_authors`.

### `GET /coauthors/v1/authors-by-term-ids?ids={csv}`

Resolves a comma-separated list of `author` taxonomy term IDs to rich author data. New in 4.0 — this is the endpoint the block editor sidebar uses to hydrate the `coauthors` field read from the core entity store.

**Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `ids` | string | yes | Comma-separated term IDs. |

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

### `GET /coauthors/v1/authors/{post_id}`

Returns rich co-author data for a post. Similar to `/coauthors/v1/coauthors?post_id=` but older and protected by the author-editing capability rather than public-visibility.

### `POST /coauthors/v1/authors/{post_id}`

Writes co-authors to a post. Used by the classic-editor meta box.

**Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `post_id` | integer | yes | Post to update. |
| `new_authors` | string | no | Comma-separated list of author nicenames. |

## Filters

Responses from the plugin endpoints can be modified with:

- `rest_coauthors_item_schema` — the resource schema
- `rest_prepare_coauthor` — the response for a single co-author

See [filters.md](./filters.md) for the full list.

## See also

- [Upgrading to 4.0](./upgrading-to-4.0.md) — migrating from the removed `cap/authors` store to the core entity store
- [Filters and actions](./filters.md)
