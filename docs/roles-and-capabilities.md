# Roles and capabilities

Co-Authors Plus doesn't add roles or capabilities of its own. It checks core WordPress capabilities, and some of those checks can be changed with a filter. This page lists each check, what it allows by default, and how to change it.

The defaults below are for a single site with the standard roles. Multisite differences are noted where they apply.

## Assigning co-authors

Changing the co-authors of a post goes through `CoAuthors_Plus::current_user_can_set_authors()`. It returns:

- `true` for super admins, without applying the filter. On a single site, WordPress counts every user with the `delete_users` capability as a super admin, so this includes Administrators. On multisite, site Administrators aren't super admins, so the filter applies to them.
- otherwise, whether the user's role has `edit_others_posts`, passed through the [`coauthors_plus_edit_authors`](./filters.md#coauthors_plus_edit_authors) filter.

The check reads the user's stored capabilities (from their role and any added to the user directly), so a plugin that grants `edit_others_posts` through the `user_has_cap` filter doesn't change the result; use the filter instead. It always checks `edit_others_posts`, whatever the post type, so a role with `edit_others_pages` but not `edit_others_posts` can't set authors on pages.

These places use it:

- the Authors box in the classic editor, and saving it.
- the Authors panel in the block editor, which is only loaded for users who pass.
- Quick Edit and Bulk Edit. Bulk Edit also needs `edit_post` on each post.
- the classic editor's author search. Its nonce is only printed for users who pass.
- `post_author` changes on REST saves of the `coauthors` field.
- the `coauthors/v1` endpoints `search`, `authors-by-term-ids` and `authors/{post_id}`. The `authors/{post_id}` endpoints also need `edit_post` on the post. See the [REST API](./rest-api.md).

To let more users assign co-authors:

```php
// Let anyone who can edit posts change the byline.
add_filter( 'coauthors_plus_edit_authors', function ( $can_set_authors ) {
	return $can_set_authors || current_user_can( 'edit_posts' );
} );
```

Returning `false` from the filter doesn't stop Administrators on a single site, or super admins on multisite, because the filter isn't applied for them.

## Who can be picked as a co-author

The author search only returns WordPress users with the capability from the [`coauthors_edit_author_cap`](./filters.md#coauthors_edit_author_cap) filter, `edit_posts` by default (Contributor and above). The same capability limits the users offered in a guest author's **WordPress User Mapping** dropdown.

Guest authors are always returned, because they don't have capabilities. To hide particular co-authors from the search, use [`coauthors_edit_ignored_authors`](./filters.md#coauthors_edit_ignored_authors).

```php
// Only offer users with a custom capability.
add_filter( 'coauthors_edit_author_cap', function () {
	return 'byline_eligible';
} );
```

This only affects the search. Code and WP-CLI commands can still add any user to a byline.

## Editing posts you co-author

A co-author is treated as the post's author when WordPress checks `edit_post` and `delete_post`. Where WordPress would ask for `edit_others_posts` or `delete_others_posts`, it asks for `edit_posts` or `delete_posts` instead. The other capabilities for the post's status, such as `edit_published_posts`, still come from the user's role. A trashed post that was published or scheduled still needs the published capability, as it does for the post author.

So being a co-author never gives a user more than they would have as the post's author:

| Role | Drafts and pending posts | Published posts |
| --- | --- | --- |
| Author | Edit and delete | Edit and delete |
| Contributor | Edit and delete | Neither |
| Subscriber | Neither | Neither |

This applies to post types Co-Authors Plus is enabled for. A user linked to a guest author counts as a co-author of posts bylined to that guest author.

Being a co-author doesn't let a user change the byline. That still needs [Assigning co-authors](#assigning-co-authors). In the classic editor and Quick Edit, a co-author who isn't the `post_author` can save the post as long as they don't change its `post_author`.

## Guest author profiles

Guest authors are managed from the **Users > Guest Authors** screen. These checks apply:

| Action | Capability checked | Default roles | Filter |
| --- | --- | --- | --- |
| Open **Users > Guest Authors**, and its search | `list_users` | Administrator | [`coauthors_guest_author_manage_cap`](./filters.md#coauthors_guest_author_manage_cap) |
| Delete a guest author from that screen | `delete_post` on the profile, for the **Delete** link, the confirmation screen and the deletion; the confirmation screen and the deletion also need `list_users` | Administrator | `coauthors_guest_author_manage_cap`, for `list_users` |
| **Create Profile** and **Edit Profile** on the Users list | `list_users` | Administrator. **Create Profile** is hidden unless [`coauthors_show_create_profile_user_link`](./filters.md#coauthors_show_create_profile_user_link) returns `true` | `coauthors_guest_author_manage_cap` |
| Link a guest author to a user | Anyone who can edit the profile | As above | [`coauthors_edit_author_cap`](./filters.md#coauthors_edit_author_cap) limits who can be linked |

To let Editors use the Guest Authors screen:

```php
add_filter( 'coauthors_guest_author_manage_cap', function () {
	return 'edit_others_posts';
} );
```

To keep the screen separate from `list_users`, which also controls the core Users screen, use a capability of your own and grant it to the roles that need it:

```php
add_filter( 'coauthors_guest_author_manage_cap', function () {
	return 'manage_guest_authors';
} );

// Run once, for example on plugin activation.
get_role( 'editor' )->add_cap( 'manage_guest_authors' );
```

## Co-author biographies in REST responses

Core's `/wp/v2/coauthors` endpoints return the `author` taxonomy terms. For users without the capability from [`coauthors_rest_view_description_cap`](./filters.md#coauthors_rest_view_description_cap), `edit_posts` by default, the `description` key is removed from each term.

The term description isn't a biography. It's the plugin's search index for the co-author: display name, first and last name, login, ID and email address. Lowering the capability exposes that data, including email addresses. A low capability such as `read` still hides it from visitors who aren't logged in, because they have no capabilities.

This filter doesn't affect the `coauthors/v1` endpoints. For those, see the [REST API](./rest-api.md).

## Default roles at a glance

| | Administrator | Editor | Author | Contributor | Subscriber |
| --- | --- | --- | --- | --- | --- |
| Assign co-authors in the editor | Yes | Yes | No | No | No |
| Can be picked as a co-author | Yes | Yes | Yes | Yes | No |
| Edit and delete posts they co-author | Yes | Yes | Yes | Drafts and pending only | No |
| Open the Guest Authors screen | Yes | No | No | No | No |
| See author term descriptions in REST | Yes | Yes | Yes | Yes | No |

Administrators and Editors can already edit any post, so the co-author row adds nothing for them.

## What isn't controlled here

- WP-CLI commands run without a capability check. Anyone who can run WP-CLI on the server can change any byline or guest author.
