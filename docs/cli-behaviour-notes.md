# WP-CLI command behaviour notes

A catalogue of the *current, observed* behaviour of the `wp co-authors-plus`
subcommands, recorded while writing the Behat characterisation suite in
`features/`.

**These describe what the code does today, not what it should do.** Several
entries are bugs or clear infelicities. They are pinned as-is by the feature
files so that the planned refactor (splitting `CoAuthorsPlus_Command` into one
class per command) can be proven behaviour-preserving. Each entry is therefore a
candidate for its own fix PR, where the fix and the corresponding re-pinned
scenario land together.

Every entry below was verified against a live environment (WordPress trunk,
PHP 8.4) rather than inferred from reading the source.

## assign-coauthors

(Calibrated against the live env 2026-09-01; all scenarios green.)

- Summary lines never pluralise: `- 1 posts now have the proper co-author`,
  `- 1 posts already had the co-author assigned` even for a single post
  (php/class-wp-cli.php:415-425). Confirmed.
- When a run matches nothing, the output is just `All done! Here are your results:`
  with no result lines at all — there is no "0 posts" line and no per-post output.
  Confirmed.
- The "already associated" path (meta value loosely matches an existing co-author,
  e.g. via the post_author fallback in `get_coauthors()`) `continue`s BEFORE
  `add_coauthors()` runs (php/class-wp-cli.php:388-392), so the command never
  backfills an author term for such a post. Term-based tooling (e.g.
  `swap-coauthors`' tax_query) will therefore not see it. Note: in practice a post
  created with a valid `post_author` gets its author term immediately from the
  plugin's own `save_post` hook (`coauthors_update_post()`), so the pinned scenario
  removes that term first (`wp post term remove`) to exercise the fallback, then
  asserts `--format=count` = 0 after the command runs. Confirmed.
- The effective default `--meta_key` is `_original_import_author`; neither the
  docblock nor the synopsis documents this default. (The phase 0 brief's note that
  the default is `author` does not match the code.)
- Posts lacking the meta key are invisible to the command (the WP_Query filters on
  meta-key existence), so they are not counted in any total. Confirmed.
- The log echoes the RAW meta value while assigning the co-author found via the
  `sanitize_title()` fallback: meta `Author One` assigns user `author-one` but logs
  `... has been assigned "Author One" as the author`. Confirmed.

- Re-calibrated 2026-09-02 after adversarial review: 10 scenarios, all green.
- The three summary blocks are emitted in a FIXED source order — already-associated,
  then missing, then associated (php/class-wp-cli.php:415-425) — and no single-outcome
  scenario can show that. Now pinned in "Report every outcome in one run and ignore
  posts the query cannot reach", where one run produces all three blocks and the
  per-post counter runs across the mixed outcomes (`1:` assigned, `2:` already,
  `3:` missing).
- The WP_Query at :369 filters on meta-key EXISTENCE only and sets no `post_status`,
  and WP-CLI runs unauthenticated, so two whole classes of post are invisible to this
  command: posts without the meta key, and DRAFT/pending/private posts that have it.
  Neither is counted in any total or summary line, and there is no `--post_status`
  flag to opt in — an editorial team backfilling before publication is silently
  skipped. That same mixed scenario now carries a no-meta post and a draft-with-meta
  post as discriminators: neither is enumerated, and both end with zero author terms.
- The `sanitize_title()` fallback path is NOT idempotent. The already-associated check
  compares the RAW meta value against `$existing_coauthor->user_login` (:381-386),
  while assignment uses the co-author's nicename (:401), so meta `Author One` -> user
  `author-one` re-assigns and re-counts on EVERY run — `1: Post #N has been assigned
  "Author One" as the author` / `- 1 posts now have the proper co-author`, for ever.
  On a large import that is a needless write storm (`add_coauthors()` plus
  `wp_set_post_terms()` per post per run). Pinned by the second run in "Fall back to a
  sanitised meta value, re-assigning on every run". The exact-match path IS idempotent
  (its second run reports "already has ... associated as a co-author").
- An empty meta VALUE is processed, not skipped: the key exists, `get_post_meta()`
  returns '', both `get_coauthor_by()` lookups fail, and the post is reported as
  `does not have "" associated as a co-author but there is not a co-author profile`.
  The summary then implodes that empty string into the missing list, so the last line
  reads `  ghostwriter, phantom, ` with a dangling comma and trailing space (:422).
  Pinned in "Report missing co-author profiles once each in the summary" (the
  wp-env harness trims the trailing space; a run whose ONLY missing value is empty
  loses the whole line, because the harness drops whitespace-only lines).
  NB `wp post meta update <id> <key> ""` does NOT store an empty string — WP-CLI
  replies `Success: Value passed for custom field '<key>' is unchanged.` and writes
  nothing — so the fixture uses `wp eval 'update_post_meta( ..., "" );'`.
- Assigning an unlinked guest author sets the author term but leaves
  `wp_posts.post_author` on the previous user: `add_coauthors()` bails at
  php/class-coauthors-plus.php:1705-1709 and returns false AFTER
  `wp_set_post_terms()` has run, and `assign_coauthors()` ignores that return value,
  still logging `has been assigned` and counting the post as successfully associated.
  Pinned in "Assign an unlinked guest author from the meta value": term becomes
  `cap-jane-doe`, `post_author` stays the author1 user ID. Note the value handed to
  `add_coauthors()` is `$coauthor->user_nicename`, which for a guest author is
  `sanitize_title( user_login )` (`jane-doe`, NOT the `cap-`-prefixed post_name); the
  prefix is re-added inside `get_post_meta_key()` during the nicename lookup, so a
  refactor that "normalised" this to `user_login` (as swap-coauthors does) would
  happen to keep working, whereas one that passed the post_name would double-prefix.

## swap-coauthors

(Calibrated against the live env 2026-09-01; all scenarios green.)

- ~~`--dry` uses the raw string value in a boolean context, so ANY non-empty
  value — including `--dry=false` — enables dry-run mode.~~ **FIXED.** The
  command now declares `[--dry-run]` as a boolean flag per WP-CLI convention and
  reads it with `Utils\get_flag_value()`. `--dry` remains accepted as a
  deprecated alias, emitting a notice.
- When a post has multiple co-authors, the swap rewrites `post_author` to the FIRST
  remaining co-author with a WP user (term-name order from `get_coauthors()`), not
  necessarily the `--to` author, even though the log claims the `--to` author "has
  been assigned". Pinned in "Preserve other co-authors when swapping" (post_author
  ends up as author3, not author2). Confirmed.
- WP-CLI accepts an explicit empty value for the required parameter (`--to=`), so
  the plugin's own `--to param must not be empty` guard is reachable and fires.
  Confirmed.
- The `--to param must not be empty` guard (php/class-wp-cli.php:704-706) runs only
  AFTER the `--from` lookup, so an invalid `--from` combined with an empty `--to`
  reports `No co-author found` first.
- The tax_query only matches the prefixed `cap-<user_login>` slug
  (php/class-wp-cli.php:695, 722-728), so posts carrying a legacy unprefixed author
  term for the `--from` co-author are not swapped (not pinned in a scenario; noted
  from source).

- Re-calibrated 2026-09-02 after adversarial review: 11 scenarios, all green. The
  `--from`-before-`--to` guard order above is now pinned in "Validate --from before an
  empty --to", and all five error scenarios assert `the return code should be 1` plus
  `STDOUT should be empty`. The `missing --from parameter` scenario was LOOSENED to
  `STDERR should contain:` because the surrounding `Error: Parameter errors:`
  rendering belongs to WP-CLI, not to CAP (and CI installs wp-env, hence WP-CLI,
  unpinned), so a one-class-per-command split that swaps `@synopsis` for a
  `## OPTIONS` docblock must not fail this file.
- ~~**Unbounded loop.** Outside preview mode the drain loop advances `paged`
  only when previewing, relying on `add_coauthors()` removing the `cap-<from>`
  term to make progress; `--from` equal to `--to`, or a `--from` whose case
  differed from the stored `user_login`, left the term in place and the command
  ran forever.~~ **FIXED.** The command now works from the resolved co-author
  logins rather than the raw input, which removes the case-mismatch cause
  entirely, rejects `--from` equal to `--to` up front, and aborts with a clear
  error if a page of posts ever comes back unprocessed. Both former triggers are
  now pinned by scenarios, which are safe to run.
- ~~`--dry` truthiness: `--dry=true` and `--dry=false` are BOTH previews, but
  `--dry=0` and the valueless `--dry` perform a REAL swap — WP-CLI matches the
  bare flag against the `[--dry=<dry>]` synopsis, warns `--dry parameter needs a
  value`, DISCARDS it, and the `false` default applies.~~ **FIXED.** This was the
  worst defect the characterisation pass found: the two most natural ways to ask
  for a preview both mutated the site and exited 0. `--dry-run` (and the
  deprecated `--dry`) now preview correctly in their bare form. Following WP-CLI
  convention, `--dry-run=0` and `--no-dry-run` remain real swaps, since flag
  values use PHP truthiness and `--no-<flag>` is the documented negation; both
  are pinned in "Treat --dry-run=0 and --no-dry-run as a real swap".
- The command is TERM-driven, not `post_author`-driven. A post whose only link to the
  `from` author is `wp_posts.post_author` (its `cap-<from>` term removed) is reported
  as `Found 0 posts to update.` / `Success: All done!` and left untouched — unlike
  `assign-user-to-coauthor` and `get_coauthors()`, which both fall back to
  `post_author`. Sites migrating from plain WordPress authorship therefore get a
  silent no-op. Pinned in "Ignore posts the swap query cannot reach".
- The WP_Query at :715-730 sets no `post_status` and WP-CLI runs unauthenticated, so
  only public statuses match: a DRAFT post carrying the `cap-<from>` term (CAP's
  `save_post` hook gives it one) is silently skipped and is not even counted in
  `Found N posts to update.`. There is no `--post_status` flag to opt in. Pinned in
  the same scenario; `assign-coauthors` has the identical restriction.
- Swapping TO an unlinked guest author leaves `wp_posts.post_author` pointing at the
  previous WP user indefinitely: `wp_set_post_terms()` has already run by the time
  `add_coauthors()` bails at php/class-coauthors-plus.php:1705-1709 and returns false,
  and `swap_coauthors()` ignores that return value — so the byline changes, the log
  still says `has been assigned` and the command still reports `Success: All done!`.
  Pinned in "Swap to a guest author with no linked account" (term becomes
  `cap-jane-doe`, `post_author` stays the author1 user ID).
- NOT a bug — an adversarial-review claim tested live on 2026-09-02 and REJECTED:
  logins whose `user_nicename` differs from the raw login, e.g. `john.doe` (nicename
  `john-doe`, term `cap-john-doe`), ARE swapped correctly even though the tax_query
  slug is built naively as `'cap-' . $from_userlogin` (:694-695). `WP_Tax_Query`
  sanitises `slug` terms with `sanitize_term_field()`, so `cap-john.doe` becomes
  `cap-john-doe` before the query runs. Verified: `swap-coauthors --from=john.doe`
  reported `Found 1 posts to update.` and moved the term. No scenario added.
- Multi-post behaviour is now pinned (3 posts): `Found 3 posts to update.`, per-post
  counter `1:`/`2:`/`3:` in ascending ID order, and a re-run reporting
  `Found 0 posts to update.` — which is also the only proof that the non-dry drain
  loop terminates because the `from` term really is removed. The `cap-<from>` term
  itself survives with count 0 (`--slug=cap-author1 --format=count` is 1).
- Test-isolation note: the Background now deletes every author term before each
  scenario (same convention as migrate-author-terms.feature and
  reassign-terms.feature), because author terms survive `reset_database_state()`. Two
  assertions in this file are global by nature (`--slug=cap-author1|cap-author2
  --format=count`), and without that cleanup an orphan `cap-author2` term left by any
  other feature — guest-author terms are never cleaned up at all — would fail the dry
  scenario with an unrelated "dry run created a term" message.

## CoAuthors_Plus::add_coauthors() return value

- Its return value does not mean "the assignment succeeded". When `$append` is
  false and none of the given co-authors resolves to a WordPress user, it
  returns `false` after writing the author terms perfectly well
  (php/class-coauthors-plus.php, the `false === $append && empty( $new_author )`
  branch). A byline made up only of guest authors without linked accounts hits
  this every time, which is the normal case for a guest-author migration.
- Treating it as a success signal makes a caller report that it changed nothing
  while the terms are visibly there. Decide "did the byline change" from the
  byline itself, not from this return value. Pinned by
  `Coauthor_Assignment_Service` and its regression test.

## (shared test environment — affects everyone's calibration)

- Author taxonomy terms survive the Behat reset: `reset_database_state()` deletes
  posts and non-admin users but never author terms. User deletion removes that
  user's own term (CAP `delete_user` hook), but terms with no matching user persist
  forever — the live tests DB currently carries an orphan `cap-renamed-admin` term
  (residue of rename-coauthor.feature renaming admin's term to a login with no
  user). GLOBAL assertions such as `wp term list author --format=count` or
  unscoped `--field=slug` listings are therefore unreliable; scope them with
  `--object_ids=<ids>` or `--slug=<slug>` instead.
- CORRECTION (2026-09-02): `--slug=<slug>` scoping is only safe for NEGATIVE or
  count assertions. For a POSITIVE "the command created this term" assertion it is
  a tautology — a leftover `cap-<x>` term from any earlier feature or earlier run
  satisfies it, and `CoAuthors_Plus::get_author_term()` matches on slug alone, so
  `create()` silently REUSES such a term rather than inserting one. Use
  `--object_ids=<the post the command just created>`; term relationships are
  deleted with the post, so residue cannot satisfy them. Better still, add the
  Background term wipe (see migrate-author-terms.feature) so the term really is new.
  The guest-authors group's three features now do both.
- CAP's `save_post` hook (`coauthors_update_post()`) fires on `wp post create`, so
  any post created with a valid `--post_author` already has that author's
  `cap-<nicename>` term (creating the term if needed) before any CLI subcommand
  runs. Posts created without `--post_author` get post_author 0 and no term.

## reassign-terms

(Calibrated against the live env 2026-09-01; re-verified green 2026-09-02 — 11 scenarios.)

- ~~The documented `--author-mapping=<file>` flag IS dead code: WP-CLI passes
  the assoc arg under the hyphenated key `author-mapping`, but the method reads
  `$this->args['author_mapping']` (underscore) after `wp_parse_args`. The
  mapping file is never `require`d, the `author_mapping doesn't exist` error is
  unreachable, and even a nonexistent path is accepted silently.~~ **FIXED.**
  The defaults and the read now use the hyphenated key WP-CLI actually
  supplies, so the file is loaded, and a missing file reports an error and
  exits non-zero.
- The flag naming in this command was mixed: `--author-mapping` hyphenated
  alongside `--old_term`/`--new_term` underscored, which is what invited the
  key-mismatch bug above. `--old-term` and `--new-term` are now the documented
  spellings; the underscored forms still work but report a deprecation notice.
- ~~When neither a usable mapping nor `--old_term`/`--new_term` is supplied,
  `$authors_to_migrate` is never defined, so the `foreach` raises PHP warnings
  on PHP 8+ — yet the command still prints a zero-count summary and exits
  0.~~ **FIXED.** The variable is initialised, a mapping file that does not
  define `$cli_user_map` is reported, and the command now errors when it has
  nothing to reassign rather than pretending to succeed.
- ~~The rename path (target term absent) sets the surviving term's slug AND name to
  the raw `--new-term` value with NO `cap-` prefix, inconsistent with the plugin's
  `cap-<nicename>` slug convention.~~ **FIXED.** The slug is now
  `Prefix::prefix_slug( $new_user )` while the name stays raw, matching what
  `rename-coauthor` already does. Note this does NOT make a second run idempotent,
  and deliberately so: the command never touches the guest-author profile, so
  `post_name` still holds the old login and the entry below about the second run
  reporting a missing term still stands. Renaming the profile too is
  `rename-coauthor`'s job.
- "Error: Term 'x' doesn't exist, skipping" is emitted via `WP_CLI::log` to STDOUT
  and the exit code stays 0 (line 580), so scripted callers cannot detect the miss
  from the exit code. Confirmed.
- Summary lines never pluralise: `- 1 authors were successfully reassigned terms`
  (lines 610-612). Confirmed.
- The merge message's post count comes straight from `$old_term->count` and read
  `Reassigning 1 posts` for one published post carrying the term — CAP's custom
  `_update_users_posts_count` had run on `wp post term add`. Confirmed.

- Re-calibrated 2026-09-02 after adversarial review: 11 scenarios, all green.
- The no-args PHP warnings are no longer pinned with their location (`... in
  /var/www/html/.../php/class-wp-cli.php on line 571`); the exact `STDERR should be:`
  block was replaced by two location-free `STDERR should contain:` blocks. The line
  number is incidental — any edit above it, and certainly the planned
  one-class-per-command split, would break a green test with no behaviour change.
  Same reasoning for the underscore-variant scenario: the `Error: Parameter errors:`
  framing and the `Did you mean '--author-mapping'?` suggestion are owned by
  wp-cli/wp-cli (Dispatcher/Subcommand.php) and the wp-env image's WP-CLI version
  floats, so only rc 1 + `unknown --author_mapping parameter` is pinned now.
- Supplying only `--old_term` (no `--new_term`) falls into exactly the same
  undefined-variable path as supplying nothing — `Warning: Undefined variable
  $authors_to_migrate`, rc 0, all-zero summary. The `if ( $old_term && $new_term )`
  guard at :557 has no else branch and there is no validation of the pair. Pinned in
  the no-args scenario.
- ~~NEW (silent false success): the rename branch ignores `wp_update_term()`'s
  return value. When the target slug is already taken by an unrelated author term it
  returns `WP_Error( 'duplicate_term_slug' )`, nothing is renamed, and the command
  still logs `Success: Converted ...` and counts it a success.~~ **FIXED.** The
  return is checked and a failed rename reports a warning naming the term and the
  underlying reason, and is not counted. The scenario's fixture term had to move to
  slug `cap-newuser` to keep provoking the collision, since the prefix fix above
  changed what the rename targets — worth knowing, because had the prefix fix landed
  second this scenario would have started passing while proving nothing. Core owns
  the duplicate-slug wording, so only CAP's half of the message is pinned.
- ~~NEW (unresolved numeric `--new-term`): `--new-term=999999` with no such user
  makes `get_user_by( 'id', ... )` return false, PHP 8.4 emits `Warning: Attempt to
  read property "user_login" on false`, `$new_user` becomes null, and the rename
  branch calls `wp_update_term()` with a null name/slug whose WP_Error is discarded
  too.~~ **FIXED.** The lookup result is checked and the row is skipped with a
  warning naming the ID, so no null ever reaches core. rc stays 0, which is
  consistent with the other skip paths — see the open note about exit codes below.
- ~~NEW (data loss): `--old-term=x --new-term=x` takes the MERGE branch, because
  both lookups return the same term object. `wp_delete_term( $id, 'author', array(
  'default' => $id, 'force_default' => true ) )` reassigns the term's posts to the
  term that is about to be deleted, so those posts end up with NO author term at all
  while the summary reports a successful merge.~~ **FIXED.** The merge branch now
  compares `term_id` and skips with a warning. Comparing the two *inputs* would not
  have been enough: two different spellings can resolve to the same co-author, so the
  guard has to be on the resolved term. The scenario now asserts the post keeps
  `cap-olduser` and the term survives.
- The docblock (:518-525) advertises cleaning up after an import that created
  'author' terms under the OLD user_login, but the old-term lookup goes through
  `get_coauthor_by( 'login', ... )` -> `get_author_term()`, which returns null for a
  non-object. An orphan author term with no user or guest author behind it is
  therefore reported as `Error: Term 'orphan' doesn't exist, skipping` and left in
  place, so the documented use case cannot be served. Pinned (with a term_id state
  assertion showing the term survives) in "A missing old term is reported and skipped
  without an error exit", alongside the never-existed slug, so the two causes of the
  same message are now distinguishable.
- Idempotency: a second identical run reports the old term as missing even though
  `get_coauthor_by( 'login', 'olduser' )` still finds the guest-author profile — the
  rename left `post_name` as `cap-olduser` while the term became `newuser`, so
  `get_author_term()` (which tries `cap-<nicename>` then `<nicename>`) finds nothing.
  This command never touches the guest-author profile (contrast rename-coauthor);
  both the rename and merge scenarios now assert `wp post list
  --post_type=guest-author --field=post_name`. NB guest-author post IDs follow
  `get_users()` login order (admin, newuser, olduser), not user creation order.

## migrate-author-terms

(Calibrated against the live env 2026-09-01; re-verified green 2026-09-02 — 8 scenarios.)

- **NOT A DEFECT — do not "fix" this.** The merge branch has no `continue`, so
  after merging it also logs "isn't prefixed, adding one" and re-slugs the term.
  That fall-through is REQUIRED. `wp_delete_term()` is called on the PREFIXED
  sibling with the BARE term as its `default`, so the survivor is the bare term and
  still holds the unprefixed slug; the re-slug is what completes the migration.
  Adding a `continue` here would merge two terms and leave the survivor unprefixed —
  a silently failed migration, and non-idempotent, since the next
  `update_author_term()` would recreate the collision. Two log lines for two real
  operations is honest narration. The comment claiming the term "doesn't have a
  sibling" was wrong on this path and has been corrected in the source.
- ~~"Now migrating up to N terms" counts ALL author terms, including already
  prefixed ones that will only be skipped.~~ **FIXED.** Prefixed terms are filtered
  out before the count and the loop, so the number is the work actually to be done.
  One predicate fixes this and the stale-object entry below together: the only row
  the loop deletes is a prefixed sibling, which the filtered list no longer holds,
  so iterating a stale object became structurally impossible rather than merely
  unobserved. A `get_terms()` `WP_Error` is now caught too — without that,
  `array_filter()` on a non-array would have turned a PHP warning into a fatal.
- ~~The success message reads "All done! Grab a cold one (Affogatto)".~~ **FIXED** —
  the drink is an *affogato*. "Now migrating up to 1 terms" still does not
  pluralise; that belongs to the pluralisation sweep, which is deliberately left as
  one dedicated pass rather than scattered through fix PRs, since it touches nearly
  every command and feature file.
- Terms are processed in `get_terms` default order (name ASC). Still true, but no
  longer observable from the log, since prefixed terms are never narrated.
- **NOT A DEFECT — this entry was wrong.** Only the SLUG is prefixed and the term
  NAME is left raw, which is not drift but the plugin-wide convention. Every author
  term the plugin creates is built that way by `update_author_term()`
  (`wp_insert_term( $coauthor->user_login, ..., array( 'slug' =>
  Prefix::prefix_slug( ... ) ) )`), and both `rename-coauthor` and `reassign-terms`
  write a prefixed slug with a raw name. The term name is also read exactly once in
  the whole plugin, in a `rename-coauthor` log line — every lookup goes by slug.
  Prefixing the name would diverge from all three creation sites and buy nothing.
  The scenario stays as a guard on the convention rather than a pin on a bug.

- Re-calibrated 2026-09-02 after adversarial review: 8 scenarios, all green.
- Taxonomy scoping is now pinned. With a `guardian` category and a `cap-guardian`
  post_tag in the database, `Now migrating up to 1 terms` proves `get_terms()` is
  scoped to the author taxonomy, and the surviving post_tag proves the sibling
  lookup `get_term_by( 'slug', 'cap-' . $slug, $coauthors_plus->coauthor_taxonomy )`
  (:858) is too. That `$taxonomy` argument is optional in core, so a refactor that
  dropped it would start `wp_delete_term()`-ing same-slug terms from other
  taxonomies. NB category/post_tag terms are NOT cleared by the Behat reset either,
  so the scenario deletes its own two terms first via `wp eval`.
- ~~Reverse `get_terms()` ordering: with names "Aaa" (slug `someone`) and "Zzz"
  (slug `cap-someone`) the BARE term is processed first, its prefixed sibling is
  deleted mid-loop, and the loop then still logs `already prefixed, skipping` for a
  term that no longer exists, because the `foreach` iterates term objects fetched
  before the loop.~~ **FIXED** by the same filter as the count above. The scenario is
  retained, retitled around what it still proves — that the merge works whatever the
  ordering — since its original subject no longer exists.
- Return code 0 is now asserted on the primary happy paths: `I run` does not check
  exit codes despite its docblock, and when the exit code is non-zero the harness
  moves `Error:` lines off STDOUT, so a command that died after printing the expected
  lines would previously have satisfied every `STDOUT should be:` block here.
- Watch the discriminators here. Because skipped terms are no longer narrated, the
  "already prefixed" and "run twice" scenarios now print exactly what a run against
  an empty database prints, so their state assertions are the only thing left
  distinguishing them — the run-twice scenario had none and has been given one. A
  new scenario with two prefixed terms and one bare one exercises the count
  properly; the file previously never held more than two terms.

## remove-terms-from-revisions

(Calibrated against the live env 2026-09-01; re-verified green 2026-09-02 — 7 scenarios.)

- ~~The taxonomy is hardcoded as `'author'` in the `wp_set_post_terms` call
  instead of `$coauthors_plus->coauthor_taxonomy`.~~ **FIXED.** The read side
  went through the configured taxonomy while the write named `author`
  directly, so on a site that had changed the property the command found the
  terms, logged a removal for each revision, counted them, and cleared nothing
  — reporting success for work it had not done. A unit guard now reads the
  command sources and fails if any of them names the taxonomy directly, since
  covering it live would mean registering a taxonomy under another name before
  init. Revisions are still read via direct SQL on `post_type='revision' AND
  post_status='inherit'`.
- All output is `WP_CLI::log` — there is no `WP_CLI::success` and the exit code is
  always 0. Count lines never pluralise: "Found 1 revisions to look through",
  "1 revisions had author terms removed". Confirmed.
- Current plugin code no longer adds author terms to revisions
  (`coauthors_update_post` bails for unsupported post types such as `revision`),
  so the characterisation scenarios attach terms to revisions manually to
  exercise the removal path; a plain `wp post update` produces a term-less
  revision (pinned in "Leave a revision without author terms untouched") and
  exactly ONE revision per update on WP trunk. Confirmed.
- `wp post term add <revision-id> author <slug>` does NOT work for the manual
  attachment: entity-command rejects it with `Error: Invalid taxonomy author.`
  because the author taxonomy is not registered for the `revision` post type.
  The scenarios use `wp eval 'wp_set_post_terms( <id>, array( "cap-..." ),
  "author" );'` instead, which bypasses the object-type check — the same
  ability the plugin itself historically used to pollute revisions.
- Removal logs slugs in term insertion order (`cap-admin,cap-alice` as attached),
  and the parent post's own author terms are untouched. Confirmed.
- `wp_set_post_terms( $post_id, array(), 'author' )` detaches the terms but does
  NOT delete them, so a merely-emptied term survives; and the revision post
  itself is left in place. Both pinned as state assertions.
- Test-isolation note (2026-09-02): the Background now deletes every author term
  before `create-guest-authors`, because `reset_database_state()` does not clear
  author terms between scenarios. Without it, `wp term create author alice
  --slug=cap-alice` collides with residue from an earlier run and the unscoped
  `wp term list author` assertion is nondeterministic. See the shared
  test-environment section above.

- Re-calibrated 2026-09-02 after adversarial review: 7 scenarios, all green.
- The `$affected` counter is now exercised above 1 ("Every revision with author terms
  is counted": two tagged revisions -> `All done! 2 revisions had author terms
  removed`). The driving query (:964) has no ORDER BY, so that scenario asserts with
  `should contain:` blocks rather than one exact block.
- The comma-joined slug list for a multi-term revision is an incidental ordering:
  `cap_get_coauthor_terms_for_post()` orders by `term_order`, which is always 0 for
  this taxonomy, so every row ties and the database decides. Relaxed from an exact
  `cap-admin,cap-alice` to `STDOUT should match
  /Removing (cap-admin,cap-alice|cap-alice,cap-admin)/` (note `should match` does not
  substitute {VAR}, so the revision ID stays out of the pattern).
- Revision-ID captures now use `--posts_per_page=1 --orderby=ID --order=DESC`, and
  the first removal scenario asserts a global revision count of 1 before running the
  command. If WP trunk ever stores an extra revision, the setup now fails loudly
  instead of splicing two IDs into a `wp eval` call whose silent failure `I run`
  would swallow.
- `I run` never asserts exit status, so both `STDOUT should be empty` assertions are
  paired with `And the return code should be 0` (otherwise they also pass when the
  `wp term list` step itself errored), and rc 0 is asserted on the happy paths.

## create-author

(Calibrated against the live env 2026-09-01; all scenarios green.)

- Any omitted optional flag produces a PHP `Warning: Undefined array key "<key>"` from
  `create_guest_author()` (php/class-wp-cli.php:1156-1163), because the args array is
  built with unguarded key access. Pinned via `should contain:` in
  features/create-author.feature. Confirmed. Each warning appears TWICE in the
  combined output: once as a timestamped debug-log echo
  (`[01-Sep-2026 ...] PHP Warning:  Undefined array key ...` — the container routes
  `error_log` output back to the terminal) and once as the `display_errors` copy
  (`Warning: Undefined array key "<key>" in .../php/class-wp-cli.php on line NNNN`).
- The `avatar` key is warned about on EVERY invocation: the `create-author` synopsis has
  no `--avatar` flag (WP-CLI rejects unknown parameters), so `$author['avatar']` at
  :1163 is always undefined even when every documented flag is supplied.
- Validation failures (missing `display_name` or `user_login`) are reported via
  `WP_CLI::warning()` and the command exits 0 — callers/scripts cannot detect failure
  from the exit code. The command also prints `-- Not found; creating profile.` BEFORE
  validation, even when nothing ends up being created.
- Running with no arguments at all is accepted by the synopsis (all flags optional) and
  goes through the same warn-and-exit-0 path.
- Duplicate detection checks `user_email` postmeta first, then `user_login` (with a
  post_name fallback), so an existing profile with the same email but a different login
  is treated as "already exists" and the requested new login is silently ignored.

- Re-calibrated 2026-09-02: all of the above confirmed again on the live env
  (PHP 8.4, WP trunk). Two additions:
  - Supplying `--avatar=<n>` is rejected by WP-CLI's synopsis check with
    `Error: Parameter errors:` / ` unknown --avatar parameter` and exit code 1, so the
    `avatar` value that `create_guest_author()` reads at :1163 can never be set through
    this subcommand. Pinned as its own scenario.
  - The profile written on the happy path holds exactly `cap-display_name`,
    `cap-first_name`, `cap-last_name`, `cap-user_login`, `cap-user_email`,
    `cap-website`, `cap-description` and `_original_author_login` — there is no
    `cap-avatar` meta, and `_original_author_id` is never written from this path
    either. Pinned with an exact `wp post meta list --format=csv` assertion.

- Hardened 2026-09-02 after adversarial review: 11 scenarios, all green.
- **Silent author-term hijack (new, and the most significant finding here).** A
  `--user_login` that matches an existing WP USER is accepted and the guest author is
  created, sharing that user's author term. `create_guest_author()` only dedupes
  against guest-author posts (:1136-1141) and `Guest_Authors::create()` only rejects a
  collision when the existing co-author's `type` is `guest-author`
  (php/class-coauthors-guest-authors.php:1402-1406), while `get_author_term()` matches
  on the `cap-<nicename>` slug alone — so `update_author_term()` finds the user's term,
  REWRITES its description with the guest author's search values, and
  `wp_set_post_terms()` attaches the new profile to it. Verified live: user `jane-doe`
  (term_id 428, description `Jane Doe   jane-doe 166 jane-user@example.com`) plus
  `create-author --display_name="Jane Guest" --user_login=jane-doe` gives rc 0,
  `Success: -- Created as guest author #760`, the guest-author post carrying term_id
  428, and that term's description now reading `Jane Guest   jane-doe 760
  jane-guest@example.com`. The user's own published post therefore resolves to the
  guest author. Pinned in "A user_login that collides with an existing user reuses
  that user's author term" (term_id equality + the rewritten description). Note this
  makes the `term-creation-failed` / "The author slug may conflict with an existing
  user" error string unreachable from this path.
- **No sanitisation at all.** `create_author()` hands `$assoc_args` straight to
  `create_guest_author()`, so `--display_name="<b>Raw</b> Name"` is stored (and used as
  `post_title`) verbatim, `--user_login="Raw User!"` is stored verbatim, an unschemed
  `--website=example.com/raw` is stored as typed, and `--description="<script>x</script>bio"`
  lands in `cap-description` unfiltered. Only `post_name` is normalised, by
  `create()`'s own `sanitize_title()` (`cap-raw-user`), which is also what the term
  slug becomes. This is the exact opposite of `create-guest-authors-from-csv`, which
  sanitises every cell — a shared `build_guest_author_data()` helper during the split
  would silently change one command or the other. Pinned in "Field values are stored
  exactly as given, without sanitisation".
- The six `Undefined array key` warnings are now pinned as six separate
  `STDOUT should contain:` steps instead of one ordered `.*`-chained regex: their order
  is just the literal order of the array literal at :1156-1163, so a behaviour-preserving
  reorder must not fail the suite.
- The `--avatar` rejection now pins only `unknown --avatar parameter` (rc 1); the
  surrounding `Error: Parameter errors:` framing is WP-CLI's, not CAP's, and the wp-env
  image's WP-CLI floats. Same treatment for the CSV/WXR `missing --file parameter`.
- Background now wipes author terms (same convention as migrate-author-terms.feature)
  and the term assertion is scoped `--object_ids={GUEST_AUTHOR_ID}`; see the correction
  in the shared test-environment section above.

## create-terms-for-posts

(Calibrated against the live env, 2026-09-01; re-verified green 2026-09-02.)

- ~~When a post's `post_author` user does not exist, `update_author_term()` returns
  `false` and the command dereferences it anyway, emitting PHP warnings for `slug`
  and `user_nicename`. The post is still logged as `Added ... now has an author term
  for: ` with a trailing empty author, counted in `$affected`, and the run claims
  `Of 1 posts, 1 now have author terms.` though NO term was set.~~ **FIXED.** The
  resolved term is checked with `! $author_term instanceof WP_Term`, which covers
  both failure modes — `false` for a missing user, and a `WP_Error` if the term
  cannot be created — and the post is skipped with a warning naming the post and the
  user ID. The summary counts it honestly, so the state assertion (`0` terms) now
  agrees with the reported figure instead of contradicting it. The memo also moved
  from `! empty()` to `??`, so a failed lookup is cached too and an orphaned author
  is resolved once per run rather than once per post.
- ~~`Updating author terms with new counts` is misleading: `update_author_term()`
  only refreshes the term description; it never recalculates counts.~~ **FIXED, and
  the original diagnosis here was wrong.** Counts *are* recalculated — CAP registers
  `_update_users_posts_count` as the taxonomy's `update_count_callback`, and core
  fires it from `wp_set_object_terms()`, so setting the term on each post already
  maintains the count. The real defect was that the whole trailing pass was
  REDUNDANT: it looped over authors that had every one been through
  `update_author_term()` moments earlier in the same run, with a description derived
  from user fields that cannot have changed meanwhile, so `wp_update_term()` never
  fired. A second pass doing nothing, announced by a message describing something
  else. Both are deleted. A new scenario forces a term count to 5, runs the command,
  and asserts the count comes back to 1 — verified live, which is what confirmed the
  write path maintains it and the deletion is safe.
- The command walks every supported post type (post AND page by default), unlike
  `create-author-terms-for-posts` which defaults to `post` only. Pinned in the
  "Pages are inspected by default" scenario.
- Never-pluralised grammar: `Of 1 posts, 1 now have author terms.`
- ~~The two per-post log lines use DIFFERENT identifiers for the same term: the
  "Skipping" line prints term NAMES while the "Added" line prints the user's
  `user_nicename` — neither shows the `cap-` prefixed slug that is actually
  stored.~~ **FIXED.** Both lines now print the slug, so the log names the thing
  the command wrote and an operator can paste it straight into `wp term list`.
- ~~The "Skipping" line uses `{$posts->found_posts}` as the denominator while the
  "Added" line uses `$total_posts`. They are equal today only because
  `found_posts` is re-read from an identical query each page.~~ **FIXED.** Both use
  `$total_posts`. No output change today; it removes a latent divergence.

- Hardened 2026-09-02 after adversarial review: 9 scenarios, all green.
- Drafts, pending and private posts are INVISIBLE to this command. The WP_Query at
  :94-101 sets no `post_status`, and a CLI request has no current user, so only
  public statuses are inspected — despite the docblock's claim that it walks all
  posts, and unlike `create-author-terms-for-posts`, which exposes
  `--post-statuses`. Pinned in "Draft and private posts are never inspected": the
  command reports `Now inspecting or updating 0 total posts.` and both posts end
  with zero author terms. A "tidy-up" adding `'post_status' => 'any'` would be a
  silent scope change.
- The skip guard is "has ANY term in the author taxonomy", not "has the term for
  this post's author", so a post deliberately attributed to somebody other than
  `post_author` is skipped and never reconciled. Pinned in "A post whose existing
  author term is not its post author is left alone" (post authored by admin but
  carrying only `cap-writer`: `Skipping - ... already has these terms: writer`,
  and the stored term is still `cap-writer` afterwards). Changing the guard to
  "has the term for post_author" would overwrite such attributions.
- The per-post memoisation (`$authors[ $single_post->post_author ]` /
  `$author_terms[ ... ]`, :123-127) is now exercised with two distinct authors in
  "Each post gets an author term for its own author", so hoisting the lookup out
  of the loop can no longer pass.
- The orphan-author scenario was pinned with an end-anchored regex on the empty
  trailing nicename. That is gone with the bug: the scenario now pins the whole of
  STDOUT exactly, since a skipped post produces a short and fully predictable run.
- STILL OPEN: drafts, pending and private posts remain invisible, and the docblock
  still overclaims. The sibling `create-author-terms-for-posts` exposes
  `--post-statuses`; adding the same flag here (defaulting to `publish`, so no
  silent scope change on a command that WRITES) is the natural fix, kept separate
  from the corrections above because it adds public interface rather than fixing
  behaviour.

## create-author-terms-for-posts

(Calibrated against the live env, 2026-09-01; re-verified green 2026-09-02. All draft predictions confirmed,
including `Processing page 2.` with `--records-per-batch=1` re-selecting only
still-missing posts, and `wp post create --post_author=1` auto-assigning the
cap-admin term so a fresh run reports `Found 0 posts with missing author terms.`)

- An invalid ID range (`--above-post-id` >= `--below-post-id`) surfaces as an
  UNCAUGHT PHP exception/fatal (`Exception` thrown at php/class-wp-cli.php:1241),
  not a `WP_CLI::error()`. Re-calibrated 2026-09-02: exit code is **1**, and the
  user-facing output is a full PHP stack trace (printed twice — once as the
  timestamped debug-log copy, once as the `display_errors` copy) followed by
  WP-CLI's generic
  `Error: There has been a critical error on this website.Learn more about
  troubleshooting WordPress. There has been a critical error on this website.`
  That generic line is the ONLY thing routed to STDERR, so an operator who reads
  just STDERR never learns which parameter was wrong. Pinned as: return code 1,
  STDOUT contains `Fatal error: Uncaught Exception: The $above_post_id param must
  be less than the $below_post_id param.`, STDERR contains the critical-error
  line. (The stack trace itself is incidental and is not pinned.)
- `--above-post-id` may be given WITHOUT `--below-post-id` (and vice versa); the
  bound is then open-ended. Pinned in the "no upper bound" scenario.
- `--post-types` / `--post-statuses` accept comma separated lists; the SQL builds
  one placeholder per value. Pinned with `--post-statuses=publish,draft`.
- The skip-postmeta warning interpolates `$wpdb->users`, which is `wp_users` in
  the wp-env tests container (default prefix). Pinned exactly.
- `--specific-post-ids` takes precedence over `--above-post-id`/`--below-post-id`
  (elseif at :1235-1239), so an invalid range combined with specific IDs is
  silently ignored. Noted from source; not pinned.
- Posts with `post_author = 0` are excluded by the SQL (`post_author <> 0` at
  :1265) and are therefore invisible to this command, while
  `list-posts-without-terms` DOES list them. Pinned.
- `Updating author terms with new counts` is misleading here too:
  `update_author_term()` only refreshes descriptions; the direct
  `$wpdb->insert` into term_relationships never updates term counts either.
- Grammar: `Found 1 posts`, `1 records affected` (never pluralised).
- A post whose author is missing is counted in `Found N posts` but produces
  `0 records affected` after the skip postmeta warning; the run still ends with
  `Success: Done!`.


- Hardened 2026-09-02 after adversarial review: 14 scenarios for this command
  (20 in the file, the rest being delete-postmeta-that-skip-author-term-backfill).
- The invalid-range scenario no longer pins WP core's
  `Error: There has been a critical error on this website.` copy. That string is
  owned by core's fatal error handler and the tests env tracks WordPress trunk, so
  it is re-worded from release to release; the scenario now asserts rc 1, the
  CAP-owned `Fatal error: Uncaught Exception: The $above_post_id param must be
  less than the $below_post_id param.` on STDOUT, and STDERR not empty. The `<=`
  boundary is pinned too: `--above-post-id=5 --below-post-id=5` throws as well.
- `--below-post-id` on its own now has its own scenario (previously only
  `--above-post-id` alone and both together were covered, which the symmetric
  `array_unshift()` argument ordering at :1245-1255 could hide).
- `--specific-post-ids` precedence is now pinned, not just noted: a multi-ID CSV
  combined with an invalid range (`--above-post-id=10 --below-post-id=5`) exits 0
  and processes the named IDs, because the specific-IDs branch is an `elseif` that
  short-circuits the range validation. This also pins the CSV `explode()` and the
  `IN ( %d, %d )` placeholder generation.
- Per-post author resolution and the author-loop running percentage are pinned
  with two authors: `Success: Updated author term for author N (alpha) (50.00%).`
  then `... (beta) (100.00%).`
- Batched forward progress depends on the skip postmeta. Each batch re-runs the
  same `LIMIT n` query with NO offset, so a post that cannot be processed must
  drop out of the result set. Pinned in the `--records-per-batch=1` scenario,
  which now leads with an orphan-author post (lowest ID): the run prints
  `Processing page 2.` and `Processing page 3.` and terminates. Remove or defer
  `skip_backfill_for_post()` and this command becomes an infinite loop on any site
  with an orphaned `post_author`.
- CORRECTION to a review suggestion: the author term `count` is NOT derived from
  term relationships, so it cannot discriminate the raw `$wpdb->insert` into
  `wp_term_relationships` from `wp_set_object_terms()`. CAP registers the author
  taxonomy with `update_count_callback => _update_users_posts_count`
  (php/class-coauthors-plus.php:279), and that callback counts published posts
  where the TERM matches **or** `post_author` matches the co-author
  (php/class-coauthors-plus.php:867-883). Measured live: two published posts by
  admin give `cap-admin` count 2; `wp post term remove <id> author --all` on both
  leaves the count at 2; after the backfill it is still 2. The feature pins 2 to
  document those semantics (an author term's count survives having every one of
  its relationships removed), not as a guard against the API swap.
## delete-postmeta-that-skip-author-term-backfill

(Calibrated against the live env, 2026-09-01; re-verified green 2026-09-02.)

- Success/failure per post is reported with bare emoji (`Success: 👍` /
  `Error: 👎`). Confirmed live: both survive the wp-env output filter and pin
  as exact-match assertions (the `Error: 👎` line splits to STDERR with exit 1).
- `WP_CLI::error( '👎' )` (php/class-wp-cli.php:335) aborts the whole loop on the
  FIRST post whose meta cannot be deleted (e.g. an ID without the meta), leaving
  any later IDs in `--specific-post-ids` unprocessed, with exit code 1 — even if
  earlier deletions succeeded. Confirmed and pinned 2026-09-02 in the
  "stops at the first post that does not have it" scenario: given
  `--specific-post-ids=<no-meta-post>,<has-meta-post>`, STDOUT holds only the
  `Deleting postmeta key ... for Post ID <no-meta-post>` line, STDERR is
  `Error: 👎`, rc 1, and the second post STILL has its
  `_cap_skip_backfill` meta. A partial run is therefore indistinguishable from a
  total failure, and re-running is the only recovery.
- With no `--specific-post-ids` the lookup WP_Query uses defaults
  (`post_type=post`, `post_status=publish`), so skip metas on drafts, pages or
  other post types are never found in the no-args mode. Noted; not pinned.
- When there is nothing to delete the command prints nothing at all (no summary,
  exit 0). Pinned.


- Hardened 2026-09-02 after adversarial review: 6 scenarios for this command.
- ~~Bare (no `--specific-post-ids`) mode never sees skip metas on unpublished
  posts, nor on pages or other post types, and silently caps at
  `posts_per_page` (10 by default) with no warning and no summary. An operator
  who backfills 30 pages and then runs the bare delete gets no output, exit 0,
  and nothing deleted.~~ **FIXED.** The lookup query set only `meta_key` and
  `fields`, so it inherited `post_type=post`, `post_status=publish` and the
  site's `posts_per_page`. It now passes `post_type=any`, `post_status=any` and
  `posts_per_page=-1`, and both scenarios are re-pinned to the corrected
  behaviour.
- The "nothing to delete" scenario now asserts rc 0 and empty STDERR. On its own
  `STDOUT should be empty` is vacuous: the harness moves `Error:`/`Warning:` lines
  into STDERR whenever the exit code is non-zero, so an unregistered subcommand
  after the class split would have kept that scenario green.
## list-posts-without-terms

(Calibrated against the live env, 2026-09-01; re-verified green 2026-09-02.)

- The tests site uses pretty DATE-BASED permalinks (e.g.
  `http://localhost:<port>/2026/09/01/alpha-post/`), not plain `?p=<ID>` links
  as the draft assumed. The port comes from the git-ignored
  `.wp-env.override.json` (9893 locally), so the feature pins the permalink
  shape via regex with `localhost:\d+` rather than a literal port.

- The command only ever prints CSV-ish lines; there is no summary and no
  success message, and an empty result prints nothing (exit 0). Pinned.
- ~~Post titles go through `addslashes()`, so an apostrophe renders as `\'` in
  the output — PHP-style escaping inside CSV-style quoting.~~ **FIXED.** Every
  field is wrapped in `"` and joined with `,`, which is CSV, and CSV escapes an
  embedded quote by doubling it rather than with a backslash. A title containing
  a quote therefore produced a line no CSV parser could read back, and
  apostrophes and backslashes were mangled for no reason at all — neither needs
  escaping in CSV. `addslashes()` is replaced by doubling `"`, and it appeared
  nowhere else in the plugin. Two scenarios now pin it: an apostrophe printed
  literally, and a quoted title round-tripping as `""`.
- ~~Only `publish` posts are inspected (WP_Query default status with no logged-in
  user), so drafts without terms are silently excluded.~~ **FIXED.** The query
  now passes `post_status => 'any'`. This is the command whose entire purpose is
  finding posts that lack author terms, and drafts are exactly where they go
  missing, so the omission defeated the diagnostic. There was also no way to work
  around it: the synopsis declares only `[--post_type]`, and WP-CLI treats an
  undeclared argument as fatal, so `--post_status=draft` exited 1. `'any'` still
  excludes trashed and auto-draft posts, which a new scenario pins so the
  boundary cannot drift. No opt-in flag was added: this is a read-only
  diagnostic, so a narrow default buys no safety, and the two comparable fixes in
  this catalogue (`update-author-terms` and
  `delete-postmeta-that-skip-author-term-backfill`) both widened rather than
  adding a flag.
- ~~`$assoc_args` is merged straight into the WP_Query args, so ANY WP_Query var
  (e.g. `--year=`) is accepted, not just the documented `--post_type`.~~ **NEVER
  TRUE**, rather than fixed. The pre-split code already declared
  `@synopsis [--post_type=<ptype>]`, and WP-CLI rejects an undeclared associative
  argument as a fatal parameter error, so `--year` has never reached
  `wp_parse_args`. The entry was reasoned from reading the merge without
  accounting for WP-CLI's synopsis gate, and it was explicitly "Noted; not
  pinned" — the note's own methodology gave it away. The vestigial `'year' => ''`
  default it described has been deleted. Worth treating the other "not pinned"
  entries with the same suspicion, since they are the ones never executed.


- Hardened 2026-09-02 after adversarial review: 7 scenarios.
- The permalink assertion has been RELAXED on purpose. The date-based structure
  `/%year%/%monthnum%/%day%/%postname%/` is applied by @wordpress/env when it
  configures the environment (`wp rewrite structure ... --hard`), not by this repo
  and not by CAP, and CI installs @wordpress/env unpinned; the host and port come
  from wp-env too. The feature now pins the CAP-owned CSV shape only —
  `#^"\d+","Alpha post","[^"]+","\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}"$#m` —
  plus a `should contain:` block proving a permalink is emitted.
- Every `STDOUT should be empty` now carries `the return code should be 0` and
  `STDERR should be empty`. Without them those four scenarios pass against a
  completely broken command: `Error: '...' is not a registered subcommand` is
  moved out of STDOUT by the harness when the exit code is non-zero.
- Multi-post output is now pinned: one CSV line per qualifying post, in ascending
  post ID order (`'order' => 'ASC', 'orderby' => 'ID'` at :797-798). Note that
  `STDOUT should match` does NOT run `{VAR}` substitution
  (`WpEnvFeatureContext::stdout_should_match()` never calls `replace_variables()`),
  so the ordering regex keys on the post titles rather than on saved IDs.
- The draft scenario now asserts the draft genuinely has no author term before the
  command runs, so its empty output can only be explained by the post_status
  filter. (Since the post_status fix it asserts the draft IS listed, and the
  no-author-term check still rules out the other explanation.)
## update-author-terms

(Calibrated against the live env, 2026-09-01; re-verified green 2026-09-02.)

- ~~The `changed from A to B` message NEVER shows the corrected count, because
  the command re-read the term after `wp_cache_delete( $term_id, 'author' )` —
  the wrong cache group, since core caches terms in the `terms` group — so
  `get_term_by( 'id', ... )` returned the stale cached object and `$new_count`
  always equalled `$old_count`.~~ **FIXED.** `update_author_term_post_count()`
  writes the corrected count via a direct `$wpdb->update`, which leaves core's
  term cache stale. The command now invalidates it with
  `clean_term_cache( $term_id, $taxonomy )`, matching what `reassign-terms`
  already does. The scenario that pinned `changed from 5 to 5` now pins
  `changed from 5 to 1`, alongside the existing assertion that the DB count is 1.
  Note the fix belongs in the command rather than in
  `update_author_term_post_count()`: core's `wp_update_term_count_now()` calls
  `clean_term_cache()` after the `update_count_callback`, so the method is
  correctly invalidated on its normal path and only this direct caller was
  affected.
- `Term X (N) changed from A to B and the description was refreshed` is printed
  even when NO co-author matches the term slug: `update_author_term( false )`
  returns false and `update_author_term_post_count()` returns a silent WP_Error,
  so nothing is refreshed at all. Confirmed and pinned in the orphan-term
  scenario (`changed from 0 to 0`).
- ~~The guest author pass queries the `guest-author` CPT with WP_Query's default
  post_status (`publish`), but guest author posts created via
  `CoAuthors_Guest_Authors::create()` (and hence `create-guest-authors`) are
  DRAFTS (`wp_insert_post` default), so the pass reported
  `Now inspecting or updating 0 Guest Authors.` even when guest authors
  existed.~~ **FIXED.** The query now passes `post_status => 'any'`, so the pass
  sees drafts — which is the ordinary case, not the exception — while still
  excluding trashed and auto-draft profiles. The whole guest-author half of the
  command was previously dead on any site whose profiles were created by the CLI
  or programmatically. A new scenario covers a term being created for a *draft*
  guest author, mirroring the published one; the drafts-invisible scenario now
  pins `Now inspecting or updating 1 Guest Authors.`
- `wp term list author --field=slug` returns name-ascending order (`cap-admin`
  before `cap-ghost`/`cap-guest-one`) — confirmed, relied on by exact
  assertions.
- Author terms are created for EVERY user returned by `get_users()` regardless
  of role or post count.
- Grammar: `Now updating 1 terms`; final message is `Success: All done` (no
  full stop), unlike other subcommands' `All done!`.


- Hardened 2026-09-02 after adversarial review: 8 scenarios.
- The headline "and the description was refreshed" is now pinned as STATE, not
  just as log text. Seeding a term with `wp term create author admin
  --slug=cap-admin --description="stale description"` and running the command
  rewrites the description to `admin   admin 1 wordpress@example.com` — that is
  `implode( ' ', ... )` over `CoAuthors_Plus::$ajax_search_fields`
  (display_name, first_name, last_name, user_login, ID, user_email), hence the
  three consecutive spaces for the wp-env admin's empty first/last names. Pinned
  with `#^admin {3}admin 1 \S+@\S+$#` so the environment's admin e-mail address
  is not hard-coded, plus a `should not match /stale description/`. Dropping
  `update_author_term( $coauthor )` from the term loop now fails a scenario;
  previously the whole suite stayed green because the count correction comes from
  the separate `update_author_term_post_count()` call.
- The `get_users()` pass creating a term for EVERY user is now pinned, not just
  noted: a freshly created SUBSCRIBER who has never authored anything gets
  `cap-zsub`. This is unbounded term growth on sites with large, low-privilege
  user bases, and narrowing the query (e.g. to `who => authors`) would be a
  silent behaviour change that the previous single-admin scenarios could not
  detect.
- The two multi-line `wp term list author --field=slug` assertions now pass
  `--orderby=slug --order=asc`. They previously relied on `wp term list`'s default
  name-ascending order coinciding with slug order, which only held because the
  admin term is named "admin".
## create-guest-authors-from-csv

(Calibrated against the live env 2026-09-01; all scenarios green.)

- The predicted PHP 8.4 `Deprecated: fgetcsv(): the $escape parameter...` notice does
  NOT appear (PHP 8.4.25). `fgetcsv( $file )` is called with only the stream argument
  (php/class-wp-cli.php:1073), and the deprecation only fires when other optional
  parameters are passed without an explicit `$escape`. The assertion was dropped from
  the feature; the happy-path output is clean of warnings/deprecations because the CSV
  flow populates every array key that `create_guest_author()` reads.
- There is no validation of required CSV columns (`// TODO: bail if required fields not
  found` at :1076). A CSV without `user_login`/`user_email` columns would hit undefined
  array keys at :1097. Not pinned — the fixture supplies all columns.
- Per-author failures are warnings only; once the file is readable the command always
  exits 0.

- Re-calibrated 2026-09-02: confirmed, plus a new branch finding.
  - The first/last-name logic at :1108-1119 has a GAP: the `if` needs a space in
    `display_name` AND both name columns empty; the `elseif` needs BOTH columns
    populated. A row with a single-word `display_name` and empty `first_name`/
    `last_name` (or only one of the two populated) matches neither branch, so the keys
    are never added to `$guest_author_data` and `create_guest_author()` emits
    `Undefined array key "first_name"` / `"last_name"` at :1159-1160 — the only way the
    CSV path can produce those warnings. The created profile then has no
    `cap-first_name`/`cap-last_name` meta at all. Pinned with
    features/fixtures/guest-authors-single-name.csv (display_name `Prince`).
  - Count line does not pluralise: a one-row CSV logs `Found 1 authors in CSV`
    (:1090). Pinned.

- Hardened 2026-09-02 after adversarial review: 10 scenarios, all green.
- **The sanitisation layer is now pinned** (features/fixtures/guest-authors-dirty.csv),
  because this is the ONLY one of the three commands that sanitises and the difference
  would otherwise vanish in a refactor. Live results for the row
  `<b>Dirty</b> Name,Dirty Login!,DIRTY@Example.com,example.com/x?a=1&b=2,<script>alert(1)</script><em>Bio</em>,abc,,`:
  - `Processing author Dirty Login! (DIRTY@Example.com)` — the log echoes the RAW
    cells; sanitisation happens after it.
  - `sanitize_text_field()` strips the markup: `cap-display_name` is `Dirty Name`.
  - The first/last split reads the RAW `display_name`, so first name is `<b>Dirty</b>`
    before `sanitize_text_field()` reduces it to `Dirty`; last name `Name`.
  - `sanitize_user()` is called WITHOUT `$strict`, so `Dirty Login!` is stored intact
    (spaces and `!` and all) in `cap-user_login` and `_original_author_login`. Only
    `post_name`/term slug are normalised, to `cap-dirty-login`.
  - `sanitize_email()` does NOT lowercase: `DIRTY@Example.com` is stored as typed.
  - `esc_url_raw()` adds the missing scheme: `http://example.com/x?a=1&b=2` (the `&`
    is left raw, since the `db` context skips entity encoding).
  - `wp_filter_post_kses()` removes the `<script>` TAGS but keeps their text content
    and the allowed `<em>`: `cap-description` is `alert(1)<em>Bio</em>`. Stripping
    markup is not the same as neutralising a payload.
  - `absint( 'abc' )` is 0, so no featured image is set and (as always) no `cap-avatar`
    meta exists — `avatar` is not one of `get_guest_author_fields()`.
- **A header that omits columns still imports, noisily.** With header
  `display_name,user_login` the command's own code emits `Undefined array key` for
  `user_email` twice (:1097 in the `Processing author %s (%s)` log, then :1102) and
  once each for `website` (:1103), `description` (:1104) and `avatar` (:1105), logs
  `Processing author casey-missing ()` with an empty email, and creates the profile
  anyway. No PHP 8.4 "Passing null to parameter" deprecations appear —
  `sanitize_email( null )`, `esc_url_raw( null )`, `wp_filter_post_kses( null )` and
  `absint( null )` are all quiet on WP trunk. Pinned with
  features/fixtures/guest-authors-missing-columns.csv, whose header also ends in a
  NAMELESS column carrying a value, exercising the `if ( empty( $field_keys[ $col_num ] ) )
  { continue; }` branch (:1099-1101) — the exact meta block shows the value is dropped.
  The `// TODO: bail if required fields not found` at :1076 is still a TODO.
- **A failing row does not stop the import.** features/fixtures/guest-authors-invalid-row.csv
  (empty `display_name` on row 1, a good row 2) gives `Found 2 authors in CSV`,
  `Warning: -- Failed to create guest author: display_name is a required field`, then
  `Processing author valid-person (valid@example.com)` / `Success:` / `All done!`,
  rc 0, and exactly ONE guest author (`cap-valid-person`). So the `Found N` counter is
  unrelated to the number created, and a scripted caller cannot tell from the exit code
  that rows were dropped. Now pinned rather than merely asserted in this document.
- **The name-splitting gap now includes the half-filled case.** A row with exactly one
  of `first_name`/`last_name` populated matches neither the `if` (:1110, needs both
  empty) nor the `elseif` (:1116, needs both non-empty), so the value supplied is
  silently DISCARDED and the profile gets no name meta at all — even when the display
  name does contain a space. Verified with features/fixtures/guest-authors-half-named.csv
  (`Half Named,half-named,...,Halfy,`): `Undefined array key "first_name"` /
  `"last_name"` from :1159-1160 and a profile holding only display_name, user_login,
  user_email and `_original_author_login`. Pinned alongside the single-word case.
- `--file=<a directory>` is NOT the way to reach `WP_CLI::error( 'Failed to read file.' )`:
  `is_readable()` passes, `fopen( 'features/fixtures', 'rb' )` SUCCEEDS on Linux/PHP 8.4,
  and `fgetcsv()` immediately returns false — so the command reports
  `Found 0 authors in CSV` / `All done!` and exits 0 for an operator typo. Deliberately
  NOT pinned as a scenario: fopen-on-a-directory is platform/PHP behaviour, not CAP's.
  The `Failed to read file.` branch remains unreached by any scenario.
- Term assertions are now `--object_ids=` scoped and the Background wipes author terms;
  the previous `--slug=cap-jane-doe` assertion was satisfiable by residue from
  create-author.feature (which runs first and uses the same login). See the correction
  in the shared test-environment section.

## create-guest-authors-from-wxr

(Calibrated against the live env 2026-09-01; all scenarios green.)

- If the wordpress-importer plugin is not installed, the command dies with an uncaught
  PHP fatal (`require_once` of `wordpress-importer/parsers.php` at
  php/class-wp-cli.php:1002) instead of a clean `WP_CLI::error()`. Confirmed, with a
  twist: the exit code is 1 (not 255) because WP's fatal-error recovery handler
  catches the shutdown and WP-CLI then prints core's
  `Error: There has been a critical error on this website...` line and exits 1.
  STDOUT keeps both the timestamped `PHP Fatal error:` debug-log echo and the
  `Fatal error: Uncaught Error: Failed opening required
  '.../wordpress-importer/parsers.php'` display copy (plus the full stack trace).
  Pinned via `should contain:` on `Fatal error` / `wordpress-importer/parsers.php`.
- The clean `Error: Failed to read WXR file.` exit (php/class-wp-cli.php:1009) is
  UNREACHABLE with the wordpress-importer version that `wp plugin install` fetches
  today (0.9.6). Feeding a non-XML file does not return a `WP_Error`: `WXR_Parser`
  falls through to `WXR_Parser_XML_Processor`, which fatals with
  `Uncaught Error: Class "WordPress\DataLiberation\EntityReader\WXREntityReader"
  not found` in wordpress-importer/parsers/class-wxr-parser-xml-processor.php:357,
  because CAP `require`s only parsers.php (line 1002) without the importer's
  autoloader for the Data Liberation library. Exit code 1 via the same
  critical-error handler. The draft's clean-error scenario was rewritten as
  "Fatal error on a file that is not a WXR file" (pinned: exit 1, `Fatal error`,
  the xml-processor path, and 0 guest authors created). A valid WXR file parses
  fine (the SimpleXML parser succeeds before the fallback chain is reached).
- The WXR flow never sets `website`, `description`, or `avatar` keys, so
  `create_guest_author()` emits `Undefined array key` warnings for each of them
  (:1161-1163) for every author processed. Confirmed.
- `_original_author_id` is NEVER saved: the guard checks `isset( $author['author_id'] )`
  (:1173) but the WXR flow passes the ID under the key `ID` (:1024), so the check never
  matches. (Had it matched, it would have stored `$author['ID']` anyway.) Pinned via a
  `wp post meta list --keys=_original_author_id --format=count` = 0 assertion. Confirmed.

- Re-calibrated 2026-09-02: every point above reproduced exactly (wordpress-importer
  0.9.6, PHP 8.4). Notes for whoever runs this file next:
  - The shared wp-env tests container already had wordpress-importer installed
    (inactive), so the "not installed" scenario has to `wp plugin uninstall
    wordpress-importer --deactivate` first, and the later scenarios re-`install` it.
    The install is served from the WP-CLI download cache mounted from the host
    (`~/.wp-cli/cache/plugin/wordpress-importer-0.9.6.zip`), so it does not need the
    network once warm — but a cold CI cache does. Scenario ORDER matters: the
    uninstalling scenario is deliberately placed before the ones that install.
  - Output splitting artefact of the Behat context: on the fatal paths (`I try`,
    exit 1) the `display_errors` copy `Warning: require_once(...): Failed to open
    stream...` is moved to STDERR because it starts with `Warning:`, while the
    timestamped `[...] PHP Warning:` copy and the whole `Fatal error: Uncaught Error:`
    block stay in STDOUT. Assertions are split accordingly.
  - The non-WXR-file scenario asserts only `Fatal error: Uncaught Error:` plus a
    `/wordpress-importer/` match and `STDERR should not match /Failed to read WXR
    file/` (proving the clean-error branch is dead), rather than the exact
    class-wxr-parser-xml-processor.php:357 path, so it does not break on the next
    wordpress-importer release.

- Hardened 2026-09-02 after adversarial review: 7 scenarios, all green.
- **wordpress-importer is now pinned to 0.9.6** in the Background
  (`wp plugin install wordpress-importer --version=0.9.6`, immediately followed by
  `wp plugin get wordpress-importer --field=version` = `0.9.6`). Two reasons: the
  crash characterised below is that release's parser fallback chain, not CAP's, so an
  unpinned install would turn a green suite red on an upstream release with no change
  to this repo; and `I run` does not check exit codes, so an unpinned `I try` install
  could fail silently on a cold CI cache and surface as a confusing `require_once
  parsers.php` fatal in the happy-path scenario instead of "the importer is missing".
  If wp-env ever ships a different importer version the Background now fails first,
  with the version diff as the message. When the pin is eventually moved, expect the
  "not a WXR file" scenario to need recalibrating.
- Scenario ORDER no longer matters. The install lives in the Background, and the
  "not installed" scenario uninstalls at the start and reinstalls (with the version
  re-asserted) at the end, so running a single scenario with `--name` no longer leaves
  the container without the importer.
- The `require_once` fatal is no longer pinned with the absolute container path or
  PHP's exact wording. It is now `STDERR should match #require_once\(.*/wordpress-importer/parsers\.php\)#`
  and `STDOUT should match #Failed opening required '.*/wordpress-importer/parsers\.php'#`
  — the path is a wp-env layout detail (CAP builds it from `WP_CONTENT_DIR`) and the
  "Failed to open stream: No such file or directory" phrasing is PHP's.
- The "not a WXR file" scenario now uses its own features/fixtures/not-a-wxr.xml
  instead of borrowing the CSV group's fixture, and asserts only rc 1, loose
  `/Fatal error/` + `/wordpress-importer/` matches, `STDERR should not match /Failed to
  read WXR file/` and 0 guest authors. Same fatal as with a CSV file
  (`Class "WordPress\DataLiberation\EntityReader\WXREntityReader" not found`), so
  CAP's clean `Failed to read WXR file.` branch stays dead at the pinned version.
- A valid WXR with NO `<wp:author>` nodes (features/fixtures/no-authors.wxr) prints
  exactly `All done!`, exits 0 and creates nothing — `$import_data['authors']` is an
  empty array rather than an undefined key, so the `foreach` at :1017 is quiet. Now
  pinned as the cheapest guard on that loop.
- CORRECTION to the note above: the `_original_author_id` = 0 assertion this document
  claimed was present was NOT in the feature file; the absence was only implied by the
  exact meta block. `wp post meta list {JANE_ID} --keys=_original_author_id --format=count`
  = 0 is now an explicit step (in this file, create-author.feature and
  create-guest-authors-from-csv.feature), with a Gherkin comment naming the
  `isset( $author['author_id'] )` vs `ID` guard bug, so the intent survives any
  reshuffle of the meta block.
- The second author's profile is now pinned too (exact meta block plus an
  `--object_ids` term assertion for `wxr-bob`), so the whole flow no longer rests on
  the first author alone.
- The three `Undefined array key` warnings are now separate `STDOUT should contain:`
  steps rather than one ordered `.*`-chained regex, and the `missing --file parameter`
  error pins only the CAP-independent fragment (the `Error: Parameter errors:` framing
  belongs to WP-CLI).
