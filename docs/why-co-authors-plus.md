# Why use Co-Authors Plus?

WordPress core allows one author per post, and that author must be a user account. Co-Authors Plus adds multiple ordered bylines and authors without logins. It's complex because it has to make everything in core that assumes one author work with several, not because the feature itself is hard.

## What it adds to core

| Need | Core | Co-Authors Plus |
|---|---|---|
| **Bylines** | One `post_author` | Several, in order, stored in an `author` taxonomy |
| **Writers without logins** | Needs a user account | [Guest author](./guest-authors.md) profiles, which can be linked to a user |
| **Archives and feeds** | Only that one author's posts | Includes co-authored posts on archives, in feeds, and in post counts |
| **Editing rights** | Author, or someone with `edit_others_posts` | Co-authors get the same rights as the post author, and never more. See [roles and capabilities](./roles-and-capabilities.md) |
| **Comment moderation emails** | Sent to the site admin and the post author | Also sent to every co-author who can moderate the comment |
| **Editorial tools** | Single author picker | Search-as-you-type picker and Bulk Edit support |
| **Developer tools** | None for co-authors | [Template tags](./template-tags.md), [five blocks](./blocks.md), a [REST API](./rest-api.md), and [many WP-CLI commands](./wp-cli.md) |
| **Integrations** | None | Yoast SEO, AMP, Jetpack, and WordPress Importer |

## Why it's complex

- **Queries:** it rewrites `posts_where`, `posts_join`, and `posts_groupby` so that author queries also find co-authored posts.
- **Permissions:** `map_meta_cap` has to apply core's author rules to co-authors, including for trashed published posts.
- **Compatibility:** `post_author` stays in sync with the first co-author, because core and other plugins still read it.
- **Security:** every path that can change a post's authors needs the same permission checks.

## When you don't need it

A site that only wants to show a second name doesn't need Co-Authors Plus; a custom field or simple taxonomy will do. Co-Authors Plus earns its place when co-authors need their own archives, feeds and counts, editing rights, or guest profiles.
