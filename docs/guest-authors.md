# Guest authors

A guest author is a byline that isn't tied to a WordPress user account. You can credit a writer on a post without giving them a login. Each guest author is stored as a post of the `guest-author` post type, with its profile fields in post meta.

Guest authors are on by default. To turn the feature off, return `false` from the [`coauthors_guest_authors_enabled`](./filters.md#coauthors_guest_authors_enabled) filter. The Guest Authors screen, the profile fields and the guest author WP-CLI commands then go away. Bylines for WordPress users keep working.

For who can create, edit and delete guest authors, see [Roles and capabilities](./roles-and-capabilities.md#guest-author-profiles).

## Creating a guest author

Go to **Users > Guest Authors** and select **Add New**. The [`coauthors_guest_author_parent_page`](./filters.md#coauthors_guest_author_parent_page) filter moves the screen under a different admin menu.

The edit screen has these boxes:

- **Name**: display name, first name and last name. The display name is required.
- **Contact Info**: email address and website.
- **About the guest author**: the biographical info.
- **Unique Slug**: the slug, and the **WordPress User Mapping** dropdown for [linking a user](#linking-a-guest-author-to-a-wordpress-user).
- **Avatar**: the featured image. When set, it replaces the Gravatar for the guest author's email address.

The slug is made from the display name when you first save the profile. After that the slug field is read-only, because it's used in the author archive URL and in the author term. To change it later, use [`wp co-authors-plus rename-coauthor`](./wp-cli.md#rename-coauthor).

A profile can't be saved when:

- its slug matches a WordPress user on the same site, unless the profile is linked to that user.
- another guest author already has the same slug.

### Other ways to create guest authors

- From the Users list, with a **Create Profile** row action that makes a profile linked to that user. The action is hidden by default. Turn it on with the [`coauthors_show_create_profile_user_link`](./filters.md#coauthors_show_create_profile_user_link) filter. Users who already have a profile get an **Edit Profile** action instead.
- With WP-CLI: [`create-author`](./wp-cli.md#create-author) for one profile, [`create-guest-authors`](./wp-cli.md#create-guest-authors) for every user, [`create-guest-authors-from-csv`](./wp-cli.md#create-guest-authors-from-csv), and [`create-guest-authors-from-wxr`](./wp-cli.md#create-guest-authors-from-wxr).

## Assigning a guest author to a post

Guest authors appear in the same author search as WordPress users, in both the block editor and the classic editor. The search matches the display name, first and last name, slug and email address. Add the guest author to the byline as you would any other co-author.

Guest authors can be found in the search whatever their status, including pending ones. Which WordPress users can be found is controlled by the [`coauthors_edit_author_cap`](./filters.md#coauthors_edit_author_cap) filter, which does not apply to guest authors.

## Linking a guest author to a WordPress user

A guest author can be linked to one WordPress user, using the **WordPress User Mapping** dropdown on the edit screen. The dropdown lists users with the [`coauthors_edit_author_cap`](./filters.md#coauthors_edit_author_cap) capability (`edit_posts` by default) who aren't linked to another guest author. The [`coauthors_guest_author_linked_account_args`](./filters.md#coauthors_guest_author_linked_account_args) filter changes the dropdown's arguments. If the profile's slug is the same as the user's login, the dropdown is disabled and the link can't be removed.

When a guest author is linked to a user:

- Posts bylined to the user show the guest author's profile. You don't need to move the user's posts to the guest author.
- The user's author archive on the front end redirects to the guest author's archive.
- The user is treated as a co-author of posts bylined to the guest author, so they can edit those posts as far as their role allows. See [Roles and capabilities](./roles-and-capabilities.md#editing-posts-you-co-author).
- When the plugin sets a post's `post_author` from its byline, it uses the first co-author with a WordPress account. A linked guest author counts, so the linked user can become the post's `post_author`.
- Deleting the user also deletes the guest author. See [Deleting a WordPress user](#deleting-a-wordpress-user).

Linking doesn't copy anything between the two. The guest author keeps its own display name, biography and avatar.

## Profile fields

Each field is stored as post meta on the guest author post, with a `cap-` prefix on the key. For example, `website` is stored as `cap-website`.

| Key | Label | Group | Notes |
| --- | --- | --- | --- |
| `display_name` | Display Name | `name` | Required. Also the post title. |
| `first_name` | First Name | `name` | |
| `last_name` | Last Name | `name` | |
| `user_login` | Slug | `slug` | Required. Set from the display name on first save. |
| `linked_account` | Linked Account | `slug` | The linked user's login. |
| `user_email` | E-mail | `contact-info` | Email input. |
| `website` | Website | `contact-info` | URL input, saved with `sanitize_url()`. |
| `description` | Biographical Info | `about` | Saved with `wp_kses_post()`. |

There is also an `ID` field in the `hidden` group, which has no input.

The group decides where a field appears. Fields in `name` appear in the **Name** box, `contact-info` in **Contact Info**, and `about` in **About the guest author** (as a text area). The **Unique Slug** box only shows the two built-in `slug` fields, so other groups are not rendered on the screen.

### Adding a field

Use the [`coauthors_guest_author_fields`](./filters.md#coauthors_guest_author_fields) filter. It receives the fields for the requested groups and the list of groups. Add your field when the groups include `all` as well as its own group. The plugin asks for `all` when it saves a profile and when it loads one, so a field that only answers to its own group is shown but never saved or loaded.

This adds a Mastodon profile URL to the Contact Info box:

```php
add_filter( 'coauthors_guest_author_fields', function ( $fields, $groups ) {
	if ( in_array( 'all', $groups, true ) || in_array( 'contact-info', $groups, true ) ) {
		$fields[] = array(
			'key'               => 'mastodon',
			'label'             => 'Mastodon',
			'group'             => 'contact-info',
			'input'             => 'url',
			'sanitize_function' => 'sanitize_url',
		);
	}
	return $fields;
}, 10, 2 );
```

A field can have these keys:

- `key`: the field name. Stored as `cap-<key>`.
- `label`: the label on the edit screen and in the [personal data export](#privacy).
- `group`: one of the groups above.
- `input`: the input type for the Name and Contact Info boxes. Defaults to `text`. Use `checkbox` for a checkbox. The [`coauthors_name_field_type_{$field}`](./filters.md#coauthors_name_field_type_field) filter can change it per field.
- `sanitize_function`: a callable that cleans the value on save. Defaults to `sanitize_text_field()`.
- `required`: when `true`, profiles created in code or with WP-CLI need a value.

The field is loaded onto the guest author object, so on the front end it's a property of each co-author:

```php
foreach ( get_coauthors() as $coauthor ) {
	if ( ! empty( $coauthor->mastodon ) ) {
		printf( '<a href="%s">Mastodon</a>', esc_url( $coauthor->mastodon ) );
	}
}
```

[`get_the_coauthor_meta( 'mastodon' )`](./template-tags.md#get_the_coauthor_meta-and-the_coauthor_meta) returns the same values. These read the guest author profile only. Co-authors who are WordPress users without a linked guest author don't have the property.

## Privacy

The `guest-author` post type is registered with `public`, `publicly_queryable`, `rewrite` and `query_var` all off, and `exclude_from_search` on. The admin screens still work (`show_ui` is on), but there are no guest author pages on the front end. Guest authors are shown through bylines, author archives, the [template tags](./template-tags.md), the [blocks](./blocks.md) and the [REST API](./rest-api.md). This changed in 4.0; see [Upgrading to 4.0](./upgrading-to-4.0.md#guest-author-custom-post-type-is-now-private).

Things to know:

- The `coauthors/v1/coauthors` endpoints return a co-author's display name, description, slug, archive link, featured image and avatars, but not their email address. See the [REST API](./rest-api.md).
- Guest authors are included in **Tools > Export Personal Data**, matched by email address. The export has the guest author's ID and every profile field that has a value, under the labels the edit screen shows. That includes the linked account and any field added with [`coauthors_guest_author_fields`](./filters.md#coauthors_guest_author_fields). The [`coauthors_guest_author_personal_export_extra_data`](./filters.md#coauthors_guest_author_personal_export_extra_data) filter adds other data. The plugin doesn't register a personal data eraser. To remove a guest author's data, delete the profile.

## Deleting a guest author

On **Users > Guest Authors**, select **Delete** under the guest author. If the guest author has posts, choose what happens to them:

- **Reassign to another co-author**: the chosen co-author replaces the guest author in each byline.
- **Leave posts assigned to the mapped user**: only offered when the guest author is linked to a user who still exists. The user replaces the guest author in each byline.
- **Remove byline from posts**: the guest author is taken off the posts, and the posts keep their status. A post left with no co-authors shows its `post_author` user as the byline.

Deleting a guest author never deletes posts, and never deletes the linked user. To delete the posts as well, delete them first, then the guest author.

Delete guest authors from this screen, not by trashing the `guest-author` post elsewhere (for example at `edit.php?post_type=guest-author`). Trashing skips the steps above: the profile stops resolving, but its author term is left on the posts.

## Deleting a WordPress user

When you delete a user, Co-Authors Plus deletes the user's author term, which removes them from every byline. Then:

- If you attribute the user's content to another user, that user is added to the byline of each post whose `post_author` was the deleted user. Posts where the deleted user was a co-author but not the `post_author` aren't given a replacement; the deleted user is just removed.
- If you delete the content instead, WordPress moves the user's posts to the trash.
- If the user was linked to a guest author, that guest author is deleted too, and removed from the byline of every post it was on. This happens whether or not you attribute the content to someone else.

To keep a linked guest author, remove the link before you delete the user. See [Migrating users to guest authors](#migrating-users-to-guest-authors).

The step that adds the replacement user to bylines reads the reassignment field from the admin **Delete Users** form. `wp user delete --reassign=<id>` changes `post_author` on the posts, as WordPress does, but doesn't add the replacement user to bylines.

## Migrating users to guest authors

### On the same site

[`create-guest-authors`](./wp-cli.md#create-guest-authors) creates a guest author for every user and links it to that user:

```sh
wp co-authors-plus create-guest-authors
```

Because each profile is linked, the user's posts show the guest author profile straight away. If the users are staying, you can stop here.

If you also want to delete the user accounts, move the bylines from each user to their guest author first, or the bylines and the linked profiles are deleted with the users. For each user:

1. Find the guest author's slug. It's in the Unique Slug box on the edit screen, or run `wp post list --post_type=guest-author --meta_key=cap-linked_account --meta_value=<user-login> --fields=ID,post_name`. The slug is the `post_name` without the `cap-` prefix.
2. Remove the link between the guest author and the user. Set **WordPress User Mapping** to **-- Not mapped --** and update the profile, or run `wp post meta update <guest-author-id> cap-linked_account ''`. While the two are linked, the plugin treats them as the same co-author, so the next step refuses to run.
3. Replace the user with the guest author in published bylines, keeping each byline's order, with [`swap-coauthors`](./wp-cli.md#swap-coauthors). It covers one post type per run (`post` by default) and published posts only:

    ```sh
    wp co-authors-plus swap-coauthors --from=<user-login> --to=<guest-author-slug>
    ```

4. Add the guest author to the user's other posts, such as drafts, with [`assign-user-to-coauthor`](./wp-cli.md#assign-user-to-coauthor). This covers every post whose `post_author` is the user, in any status and enabled post type. Without `--append_coauthors`, posts that already have a byline are skipped:

    ```sh
    wp co-authors-plus assign-user-to-coauthor --user_login=<user-login> --coauthor=<guest-author-slug> --append_coauthors
    ```

    The guest author is added at the end of the byline, and the user is removed in the next step.

5. Delete the user, attributing their content to another user so WordPress doesn't trash it. The posts keep the guest author byline, and `post_author` becomes the user you name:

    ```sh
    wp user delete <user-login> --reassign=<user-id>
    ```

### Moving guest authors to another site

[`export-coauthors`](./wp-cli.md#export-coauthors) writes the guest authors, and the posts each one is on, to a JSON file. [`import-coauthors`](./wp-cli.md#import-coauthors) on the other site creates the missing profiles and puts them back on the posts. Posts are matched by slug, so this works after the posts themselves have been moved with a WordPress export and import.

The `guest-author` posts can also be moved in a WordPress export, for example with `wp export --post_type=guest-author`. The export includes the profile fields, but not the guest authors' author terms. Import it with `wp import`, which needs the WordPress Importer plugin, then run `import-coauthors --skip-create` to put the guest authors back on posts.

`wp import --authors=<file>.csv` maps users from the old site to users on the new one, which suits editorial staff who need real logins. The CSV needs a header row of `old_user_login,new_user_login`. The mapping only changes `post_author` on imported posts. It doesn't change the login stored in a guest author's `cap-linked_account` field, so check the links on the new site if any logins changed.

## Multisite

Guest authors, author terms and the links between guest authors and users all belong to one site. A guest author created on one site doesn't exist on the others, and linking a guest author to a user on one site doesn't link them anywhere else. There is no network-level Guest Authors screen, and the **Create Profile** and **Edit Profile** row actions don't appear on the Network Admin Users screen.

[`create-guest-authors`](./wp-cli.md#create-guest-authors) covers the users who are members of the current site. Run it, and the other commands, once per site with `--url`. See [WP-CLI on multisite](./wp-cli.md#multisite).

The bylines clean-up described in [Deleting a WordPress user](#deleting-a-wordpress-user) runs when WordPress deletes a user from a site. Removing a user from a site with the **Remove** action on the site's Users screen doesn't run it, so the user's author term, bylines and linked guest author stay in place.
