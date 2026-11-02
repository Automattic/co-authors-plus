# Recovering from the 4.0.0 REST backfill regression

Co-Authors Plus 4.0.0 (released 22 April 2026) had a bug that could remove the co-authors from a post and replace them with the user in the post's `post_author` field. Guest authors were the most visible casualty. Version 4.0.1 (released 24 April 2026) removed the code. This page explains how to tell whether your site was affected and how to recover the affected posts.

Upgrade to 4.0.1 or later before you follow any of the recovery steps.

## What went wrong

4.0.0 added a method, `backfill_coauthor_terms_in_rest()`, on the `rest_prepare_{$post_type}` filter for every supported post type. It was meant to give posts created before Co-Authors Plus was active an author term, so the block editor had co-author data to show.

It decided whether a post had co-authors by looking at the REST response, not the database. If the response had no `coauthors` value, and the current user could edit the post, it called `add_coauthors()` with the `post_author` user. That replaced every co-author on the post.

A request that narrows the response with `_fields` leaves `coauthors` out. So a request such as `GET /wp/v2/posts?_fields=id`, made by a user who could edit the posts, rewrote the byline of every post in the response. Nothing logged the change, and the previous co-authors were not saved anywhere. Issue [#1241](https://github.com/Automattic/co-authors-plus/issues/1241) has the original report and steps to reproduce. Pull request [#1242](https://github.com/Automattic/co-authors-plus/pull/1242) removed the code in 4.0.1.

## Who is affected

A post could be damaged only if all of these were true while 4.0.0 was active:

- The post's byline was something other than just the `post_author` user. For example, it had a guest author, more than one co-author, or a co-author who was not the `post_author` user.
- The post was returned by a request to a core post endpoint (`/wp/v2/posts`, `/wp/v2/pages` or a custom post type's endpoint) that used `_fields` without `coauthors`.
- The user making the request could edit that post. Anonymous requests and users without `edit_post` did not trigger it.

The reported cases came from authenticated tools, for example an editorial calendar asking for `_fields=id,date` for a month of posts. Headless front ends, sync and export scripts, and anything using an application password could do the same. If your site had none of these and 4.0.0 was only installed briefly, it is likely to be unaffected, but the check below is the only way to be sure.

## If you are still on 4.0.0

Upgrade. If you can't upgrade straight away, remove the filter from a must-use plugin. The filter is added on `init` at priority 100, so remove it after that:

```php
add_action( 'init', function () {
	global $coauthors_plus;
	if ( ! $coauthors_plus || ! method_exists( $coauthors_plus, 'backfill_coauthor_terms_in_rest' ) ) {
		return;
	}
	foreach ( $coauthors_plus->supported_post_types() as $post_type ) {
		remove_filter( "rest_prepare_{$post_type}", array( $coauthors_plus, 'backfill_coauthor_terms_in_rest' ), 10 );
	}
}, 101 );
```

This is a stopgap, not a replacement for upgrading. If you know which tool was making the narrowed requests, consider pausing it until you have finished recovery.

## Find affected posts

Every damaged post ends up with exactly one author term: the term of its `post_author` user. This command lists the posts in that state, across all post types Co-Authors Plus supports. It resolves the user the same way 4.0.0 did, so a user with a linked guest author is matched by the guest author's term.

```sh
wp eval '
global $coauthors_plus;
$post_ids = get_posts( array(
	"post_type"      => $coauthors_plus->supported_post_types(),
	"post_status"    => "any",
	"posts_per_page" => -1,
	"fields"         => "ids",
	"no_found_rows"  => true,
) );
foreach ( $post_ids as $post_id ) {
	$term_ids = wp_get_object_terms( $post_id, $coauthors_plus->coauthor_taxonomy, array( "fields" => "ids" ) );
	if ( is_wp_error( $term_ids ) || 1 !== count( $term_ids ) ) {
		continue;
	}
	$user = get_userdata( get_post_field( "post_author", $post_id ) );
	if ( ! $user ) {
		continue;
	}
	$term = $coauthors_plus->get_author_term( $coauthors_plus->get_coauthor_by( "user_nicename", $user->user_nicename ) );
	if ( $term && (int) $term->term_id === (int) $term_ids[0] ) {
		echo $post_id, PHP_EOL;
	}
}
'
```

On a site with a very large number of posts, run it in batches (add `"paged"` and a fixed `"posts_per_page"`), or restrict `post_type` and `post_status`.

The list also includes every post that genuinely has the `post_author` user as its only author, so you need to narrow it down:

- **Compare with a backup.** A database backup from before 4.0.0 was installed is the most reliable signal. Posts whose author terms in `wp_term_relationships` differ between the backup and today are the ones to look at.
- **Compare with your own records.** If you keep a record of who wrote each piece outside WordPress, compare the list against it.

Post revisions don't help: they don't store co-authors, and the bug did not change the post's modified date.

## Recover affected posts

The correct co-authors have to come from somewhere that kept them. In order of preference:

1. **A database backup from before 4.0.0.** Restore the author term relationships for the affected posts from the backup.
2. **A copy of the site that never ran 4.0.0**, such as a staging site or a restored backup. Run [`wp co-authors-plus export-coauthors`](./wp-cli.md#export-coauthors) there and [`wp co-authors-plus import-coauthors`](./wp-cli.md#import-coauthors) on the live site. This only covers guest authors, and the import adds each guest author back at its original position without removing anything. A `post_author` user that the bug added stays on the post, so check the imported posts and remove it where it doesn't belong.
3. **Your own records.** Set the co-authors again in the post editor, or with `add_coauthors()`. It takes the post ID and a list of `user_nicename` values (for a guest author, the slug in its author archive URL), and replaces the post's co-authors in that order:

   ```sh
   wp eval 'global $coauthors_plus; $coauthors_plus->add_coauthors( 123, array( "jane-doe", "john-smith" ) );'
   ```

   `add_coauthors()` returns `false` when none of the co-authors is a WordPress user, even though it saved them. See [its return value](./cli-behaviour-notes.md#coauthors_plusadd_coauthors-return-value). Check the byline itself rather than the return value. The [`assign-coauthors`](./wp-cli.md#assign-coauthors) command is not a good fit here: it reads one author login per post from a post meta key.
4. **None of the above.** If nothing kept the original byline, it can't be recovered. Record which posts are known to be affected.

Run the detection command again afterwards. Only posts that really have the `post_author` user as their only author should remain.

## Check that it has stopped

Once the site is on 4.0.1 or later:

1. As a user who can edit posts, request `/wp/v2/posts/<id>?_fields=id` for a post with several co-authors, then check the post. Its co-authors should be unchanged.
2. Restart any tools you paused.

The backfill existed for posts created before Co-Authors Plus was active, which have no author terms. The safe way to give those posts terms is the `create-author-terms-for-posts` command. See [Backfilling author terms](./backfilling-author-terms.md).

## If you need help

Open an issue on the [Co-Authors Plus repository](https://github.com/Automattic/co-authors-plus/issues) with your plugin version history and what the detection command shows.
