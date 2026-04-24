# Co-Authors Plus

Stable tag: 4.0.1  
Requires at least: 6.4  
Tested up to: 6.9  
Requires PHP: 7.4  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  
Tags: authors, users, multiple authors, co-authors, multi-author  
Contributors: batmoo, danielbachhuber, automattic, GaryJ  

Assign multiple bylines to posts, pages, and custom post types with a search-as-you-type input box.

## Description

Assign multiple bylines to posts, pages, and custom post types via a search-as-you-type input box. Co-authored posts appear on a co-author's archive page and in their feed. Co-authors may edit the posts they are associated with, and co-authors who are contributors may only edit posts if they have not been published (as is core behavior).

Add writers as bylines without creating WordPress user accounts. Simply [create a guest author profile](https://github.com/Automattic/Co-Authors-Plus/wiki/Creating-and-editing-guest-authors) for the writer and assign the byline as you normally would.

On the frontend, use the [Co-Authors Plus template tags](https://github.com/Automattic/Co-Authors-Plus/wiki/Template-tags) or the [block editor blocks](https://github.com/Automattic/Co-Authors-Plus/wiki/Blocks) to list co-authors anywhere you'd normally list the author.

## Documentation

- **Usage** — themer and site-owner guides live on the [GitHub Wiki](https://github.com/Automattic/Co-Authors-Plus/wiki): template tags, blocks, guest author management, and multisite.
- **Developer reference** — lives in-repo under [`docs/`](./docs/) (not bundled in the distribution ZIP):
  - [Filters and actions](./docs/filters.md)
  - [REST API](./docs/rest-api.md)
  - [Upgrading to 4.0](./docs/upgrading-to-4.0.md)

Co-Authors Plus is an almost complete rewrite of the [Co-Authors](https://wordpress.org/plugins/co-authors/) plugin originally developed by Weston Ruter (2007). The original plugin was inspired by the '[Multiple Authors](https://txfx.net/2005/08/16/new-plugin-multiple-authors/)' plugin by Mark Jaquith (2005).

## Installation

1. IMPORTANT: If you are using the original Co-Authors plugin, disable it before installing Co-Authors Plus.
2. Extract the coauthors-plus.zip file and upload its contents to the `/wp-content/plugins/` directory. Alternately, you can install directly from the Plugin directory within your WordPress Install.
3. Activate the plugin through the "Plugins" menu in WordPress.
4. Place [co-authors template tags](https://github.com/Automattic/Co-Authors-Plus/wiki/Template-tags) in your template.
5. Add co-authors to your posts and pages.

## Screenshots

1. Multiple authors can be added to a Post, Page, or Custom Post Type using an auto-complete interface.
2. Guest authors allow you to assign bylines without creating WordPress user accounts. You can also override existing WordPress account meta by mapping a guest author to a WordPress user.

## Frequently Asked Questions

### How do I add Co-Authors Plus support to my theme?

If you've just installed Co-Authors Plus, you might notice that the bylines are being added in the backend but aren't appearing on the front end. You'll need to [add the template tags to your theme](https://github.com/Automattic/Co-Authors-Plus/wiki/Template-tags) before the bylines will appear.

### What happens to posts and pages when I delete a user assigned to a post or page as a co-author?

When a user is deleted from WordPress, they will be removed from all posts for which they are co-authors. If you reassign their posts to another user, that user will be the co-author instead.

### Can I use Co-Authors Plus with WordPress multisite?

Yes! You can [use Co-Authors Plus on WordPress multisite](https://github.com/Automattic/Co-Authors-Plus/wiki#wordpress-multisites). Co-Authors Plus can be activated on a site-by-site basis or network-activated. If you create guest authors, however, those guest authors will exist on a site-by-site basis.

### Who needs permission to do what?

A WordPress user will need the `edit_others_posts` capability to assign co-authors to posts. This is typically granted to the Editor role but can be altered with the `coauthors_plus_edit_authors` filter.

A WordPress user will need the `list_users` capability to create new guest author profiles. This is typically granted to the Administrator role but can be altered with the `coauthors_guest_author_manage_cap` filter.

### Can I easily create a list of all co-authors?

Yes! You can [create a list of all co-authors with a template tag](https://github.com/Automattic/Co-Authors-Plus/wiki/Template-tags#create-a-list-of-all-co-authors) `coauthors_wp_list_authors()` template tag. This template tag accepts many of the same arguments as `wp_list_authors()`. Look in `template-tags.php` for more details.

### Can I disable Guest Authors?

Yes! You can disable guest authors entirely through a filter. Having the following line load on `init` will do the trick:
`add_filter( 'coauthors_guest_authors_enabled', '__return_false' )`

## Change Log

[View the change log](./CHANGELOG.md).
