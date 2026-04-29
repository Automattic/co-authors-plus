# Changelog for Co-Authors Plus

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.0.2] - 2026-04-29

### Changed

* `coauthors_links_single()` now reads `display_name`, `website`, and `user_url` directly from the passed `$author` object rather than via `get_the_author()` / `get_the_author_meta()`. This fixes the long-standing bug where every byline rendered as the first author's name and where guest-author websites never linked, but it does mean `the_author`, `the_author_meta`, and `author_meta` filters no longer apply when the function is called. Plugins that previously hooked those filters to amend bylines will need to filter at a different layer by @faisalahammad in https://github.com/Automattic/Co-Authors-Plus/pull/1255

### Fixed

* Stop the editor dropping the current user when a co-author term can't be resolved, and never persist a termless post on REST save by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1253
* Render each guest author's own name and website on bylines emitted via `coauthors_links()` / `coauthors_links_single()` (was duplicating the first author and dropping guest-author website links), fixing #1131 by @faisalahammad in https://github.com/Automattic/Co-Authors-Plus/pull/1255
* Extend author SQL filters to support `author__in` and comma-separated author IDs by @faisalahammad in https://github.com/Automattic/Co-Authors-Plus/pull/1249
* Clear conflicting `WP_Query` flags on guest author pages to prevent PHP warnings and category-query conflicts by @faisalahammad in https://github.com/Automattic/Co-Authors-Plus/pull/1250

### Maintenance

* Extract shared taxonomy clause helpers in `posts_where_filter()` to deduplicate the multi-author query path by @faisalahammad in https://github.com/Automattic/Co-Authors-Plus/pull/1251
* Tear down `CoAuthors_Template_Filters` registrations between test cases to stop them leaking into other suites by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1256
* Exclude `AGENTS.md` and `CONTRIBUTING.md` from the distribution ZIP by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1246

### Documentation

* Use an absolute GitHub link for the changelog URL in the README by @faisalahammad in https://github.com/Automattic/Co-Authors-Plus/pull/1247
* Correct the plugin ZIP filename in the installation guide by @faisalahammad in https://github.com/Automattic/Co-Authors-Plus/pull/1248

## [4.0.1] - 2026-04-24

### Fixed

* Remove dynamic term backfill on the REST API read path, which could clear co-author assignments on posts whose author terms had not yet been materialised by @claudiulodro in https://github.com/Automattic/Co-Authors-Plus/pull/1242
* Restore guest-author avatars on admin screens, threading `user_type` through the `/coauthors` REST endpoint so the correct avatar is resolved by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1240

### Security

* Verify nonce and capability before overriding `post_author` in `coauthors_set_post_author_field`, closing an authorship-falsification path usable by Author-level users by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1243
* Require the `edit_post` capability when saving guest author post data and meta fields by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1243
* Escape the Co-Author Image block `href` with `esc_url` to strip unsafe URL schemes that could otherwise be surfaced via the `rest_prepare_coauthor` filter by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1243

### Maintenance

* Exclude `/docs/` from the distribution ZIP by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1238
* Silence a WPVIP users-table sniff in the avatar-collision test helper by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1240

### Documentation

* Split the developer reference from user guides into `/docs/` by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1238

## [4.0.0] - 2026-04-22

**Breaking changes:**

* **Block editor data store replaced.** The custom `cap/authors` Redux store has been removed. Third-party JavaScript that calls `select( 'cap/authors' )` must migrate to WordPress's core entity store (`getEditedPostAttribute( 'coauthors' )` / `editPost( { coauthors: [...] } )`). This change is what makes Co-Authors Plus compatible with real-time collaboration.
* **Deprecated filter hooks.** `coauthors_post_list_pluck_field` and `coauthors_post_get_coauthor_by_field` are deprecated when saving via the REST API. Use the `set_object_terms` action for the author taxonomy instead. Both filters still fire for now but emit a `_deprecated_hook` notice and will be removed in a future release.
* **Guest-author post type is now private.** The `guest-author` CPT is now registered with `public => false` (with `show_ui => true` to preserve the admin editor). Any front-end queries for `?post_type=guest-author` will stop resolving. This was never a supported path, but the change makes the intent explicit and closes a long-standing leak into plugins like Yoast SEO that scan for public post types.
* **`COAUTHORS_PLUS_PATH` and `COAUTHORS_PLUS_URL` constants removed.** The long-dead `deprecated.php` file has been deleted. These constants were moved there in 2013 and have been unreferenced ever since. Any third-party code still relying on them must switch to WordPress's `plugin_dir_path()` and `plugin_dir_url()`.
* **Minimum WordPress version is now 6.4** (previously 5.9).

### Added

* Real-time collaboration compatibility via the core entity store by @smithjw1 in https://github.com/Automattic/Co-Authors-Plus/pull/1217
* New `authors-by-term-ids` REST endpoint for resolving term IDs to rich author data by @smithjw1 in https://github.com/Automattic/Co-Authors-Plus/pull/1217
* Jetpack Subscriber Emails integration for co-authors by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1183
* WordPress Playground blueprint for Live Preview by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1184
* Show user count before creating guest authors by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1195

### Changed

* Make guest-author CPT private by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1232
* Replace `suggest` library with jQuery UI autocomplete by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1187
* Raise minimum supported WordPress version to 6.4 by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1152

### Deprecated

* Filters `coauthors_post_list_pluck_field` and `coauthors_post_get_coauthor_by_field` when saving via REST — use the `set_object_terms` action for the author taxonomy instead by @smithjw1 in https://github.com/Automattic/Co-Authors-Plus/pull/1217

### Removed

* `cap/authors` custom Redux store by @smithjw1 in https://github.com/Automattic/Co-Authors-Plus/pull/1217
* Long-dead `deprecated.php` file and its unused `COAUTHORS_PLUS_PATH` / `COAUTHORS_PLUS_URL` constants by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1233

### Fixed

* Fix SQL syntax error in CLI command when migrating users to guest authors by @NateWr in https://github.com/Automattic/Co-Authors-Plus/pull/1162
* Prevent PHP warning when deleting users without co-author terms by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1166
* Fix PHP warning when post has no author by @shakyjake in https://github.com/Automattic/Co-Authors-Plus/pull/1133
* Simplify guest author lookup in `delete_user_action()` to prevent warnings by @faisalahammad in https://github.com/Automattic/Co-Authors-Plus/pull/1209
* Prevent duplicate guest-authors during import by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1181
* Support custom post types in `count_user_posts` filter by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1182
* Remove deprecated jQuery `ready()` call on element selector by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1186
* Prevent fatal error when guest author slug conflicts with existing user by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1190
* `create-guest-authors` CLI command now accepts required arguments by @NateWr in https://github.com/Automattic/Co-Authors-Plus/pull/1164
* Fix `ComboboxControl` deprecation warnings by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1193
* Prevent fatal error when `author_name` is an array by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1196
* Fix avatar collision on profile, post and edit screens by @katag9k in https://github.com/Automattic/Co-Authors-Plus/pull/996
* Adjust import of `PluginDocumentSettingPanel` to resolve deprecation notice by @kadamwhite in https://github.com/Automattic/Co-Authors-Plus/pull/1108
* Allow guest author creation on WordPress 7.0 by @smithjw1 in https://github.com/Automattic/Co-Authors-Plus/pull/1206

### Security

* Gate `CoAuthors_Controller` REST endpoints on post visibility by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1234

### Maintenance

* Add wp-env configuration for local development by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1151
* Update composer.json for consistency by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1153
* Migrate integration tests to use wp-env by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1155
* Replace undefined `WP_TESTS_DOMAIN` constant with `home_url()` by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1158
* Migrate Behat tests to wp-env and modernise CI workflows by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1167, https://github.com/Automattic/Co-Authors-Plus/pull/1168, and https://github.com/Automattic/Co-Authors-Plus/pull/1169
* Migrate dependabot reviewers to CODEOWNERS by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1172
* Standardise test matrix and update readme by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1173
* Add JS build and lint workflows for npm updates by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1175
* Enable WordPress-Extra and WordPress-Docs PHPCS rulesets with test exclusions by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1176
* Automate deployment with build step and security hardening by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1177
* Exclude build directory from git tracking by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1180
* Use real WordPress image path in feed image test by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1189
* Guard guest-author CPT visibility properties with an integration test by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1232
* Bump wp-env dev PHP to 8.2 to restore local development by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1235
* Keep multiple npm and GitHub Actions dependencies up to date via Dependabot

### Documentation

* Reduce readme tags to WordPress.org limit of 5 by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1188
* Add AGENTS.md and Claude Code config by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1207

## [3.7.0] - 2025-10-20

### Added

* Add bulk editing support for Co-Authors in the admin interface by @mattradford-sage in https://github.com/Automattic/Co-Authors-Plus/pull/1120
* Filter author label during selection by @Ritesh-patel in https://github.com/Automattic/Co-Authors-Plus/pull/1121

### Fixed

* fix: avoid posts without authors on CLI and REST by @leogermani in https://github.com/Automattic/Co-Authors-Plus/pull/1137

### Maintenance

* refactor: Update create_post method to allow nullable WP_User parameter by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1122

### Documentation

* Update README.md by @GaryJones in https://github.com/Automattic/Co-Authors-Plus/pull/1130

## [3.6.6] - 2025-05-27

### Fixed
* Prevent the wrong title on author archives on Yoast's Title_Presenter by @justinmaurerdotdev and eddiesshop https://github.com/Automattic/Co-Authors-Plus/pull/1116

## [3.6.5] - 2025-04-15

### Changed
* Add id and user type to store formatted author data by @miguelpeixe in https://github.com/Automattic/Co-Authors-Plus/pull/1110

## [3.6.4] - 2025-03-25

### Changed
* Use WordPress 6.8 filter to skip heavy, unnecessary query by @ethitter in https://github.com/Automattic/Co-Authors-Plus/pull/1098

### Fixed
* Protect against pattern edits by @NickGreen in https://github.com/Automattic/Co-Authors-Plus/pull/1103

### Maintenance
* Increase composer/installers version by @NickGreen in https://github.com/Automattic/Co-Authors-Plus/pull/1104
* Add SVN install to integrate workflow by @NickGreen in https://github.com/Automattic/Co-Authors-Plus/pull/1105
* Update GitHub action to install SVN before deploy to WordPress.org by @raamdev in https://github.com/Automattic/Co-Authors-Plus/pull/1077
* Update WP_CLI::line() to WP_CLI::log() by @raamdev in https://github.com/Automattic/Co-Authors-Plus/pull/1089

## [3.6.3] - 2024-10-25

### Fixed

* Prevent terms backfill CLI command to enter an infinite loop when a post is assigned to a user that no longer exists #1065

## [3.6.2] - 2024-10-10

### Added

* Support for Yoast Name variable #1046
* New CLI cmd to backfill missing author terms for posts #1060

### Fixed

* Correctly populates the post_author column for linked authors #988

### Maintenance
* Increase minimum supported WordPress version to 5.9 #1035

## [3.6.1] - 2024-04-25

### Fixed

* Fix missing author column in wp-admin Posts table #1038

## [3.6.0] - 2024-04-22

### Added
* Blocks for Co-Authors #997

### Fixed
* Improve alignment in author-selection component #990
* Fix admin notice positions #1002
* UI: Add capability check to Add New button #1003
* i18n: set script translations and refresh language files #1007

### Maintenance
* Docs: Consolidate and refresh README.md #992
* Refresh package.json #993
* NPM: Add version-bump-prompt #994
* Fix/general code tidy #995
* Composer: Use 4-space tab indentation #999
* Improve tests structure #1000
* Extract iterator class from template-tags.php #1005
* Add supported_post_types() method #1006
* Tests improvements #1008
* Bump postcss from 8.4.19 to 8.4.31 #1009
* Create co-authors-plus-da_DK.po #1013
* Increase minimum supported PHP version to 7.4 #954
* Increase minimum supported WordPress version to 5.7 #955

## [3.5.15] - 2023-08-28

### Fixed
* Incorrect Contributor name
* Incorrect change log date

## [3.5.14] - 2023-08-28

### Fixed
* Check `get_current_screen()` exists #973
* Fix Ajax-suggested user and guest author ID collision #974
* Fix PHP Warnings on accessing `$coauthor` instance #975
* i18n: Add text domain header #984

### Maintenance
* CI: Update integration tests environments #979
* CLI: Add Behat functional tests #980
* Create LICENSE file #985
* Refresh plugin headers #986

## [3.5.13] - 2023-07-31

### Fixed
* Reverted "Fix Co-Authors Plus & Elementor Archive Pages conflict" #970

## [3.5.12] - 2023-07-30

### Fixed

* Author archive: ensure queried object ID is an int
 #966
* Ensure 'nickname' is created to prevent is_author() PHP notice with Guest Author #939
* Refreshing meta box nonce after re-authenticating following a wp_auth_check() #670
* Fix Co-Authors Plus & Elementor Archive Pages conflict #926
* Fix broken link in readme #957
* Unable to change avatar image sizes #959
* Fix avatar id collision between user id and guest-author post type #960
* Add i18n string for PluginDocumentSettingPanel title ##961
* Improve I18n strings #963
* Fix notification to post co-authors #964
* UI: Fix admin headings #965

### Added
* Add: List Co Authors Plus authors in the Slack social share #953

### Maintenance
* Add .gitattributes file #951.
* Tests: Add previously deleted test back in #967
* Refresh screenshots, add banner and icon images #956


### Removed
* Removed deprecated social media fields #830

## [3.5.11] - 2023-07-24

* Author select improvements #924
* Show author taxonomy in REST API, hide description based on capabilities #931
  * The author taxonomy was first added to the REST API in CAP 3.5.0, then removed in 3.5.2 over concerns of author email addresses being visible in the description. This adds it back but with the description hidden by default (filterable).
* Guest Authors: Add labels property #942
* Define dynamic guest_authors property #947
* Bump NPM dependencies #927 #933 #943 #944
* Workflows: Fix runner image and matrix #946
* CI: Split out CS and update integration tests workflow #948
* Improve tests setup #949

## [3.5.10] - 2023-01-24

* Fix: Authors not being updated properly in the editor #920

## [3.5.9] - 2023-01-19

* Fix: Avoid Fatal errors reported in the Nav menu filters for Guest Authors #917

## [3.5.8] - 2023-01-18

* Make CoAuthors_Plus::get_coauthor_by() compatible with unicode author usernames #909
* Yoast: Prevent no-indexing of guest authors' archive pages by @adekbadek #912
* Refactors Gutenberg Feature #870
* Fixes guest author menu item to display accurate URL #913
* Only update authors on Gutenberg post save #914

## [3.5.7] - 2023-01-05

* Remove Composer autoloader dependency #898
* Fixes Yoast deprecated warning for get_article_type_options_values() #901

## [3.5.6] - 2022-11-16

* CI: Add new deployment workflow #890
* Dependency update #891

## [3.5.5] - 2022-11-16

* Add Yoast integration #877
* CI: Add WP 6.1 #886 #887

## [3.5.4] - 2022-11-04

* Handle `WP_Error` on failed guest author creation for method `create_guest_author()` #879
* Only use `pre_handle_404` filter in non-paged result #874
* Add CLI create-author command #880
* Add template tag `co_authors_get_users()` #862
* Change permission callback for authors and search endpoint and improve `current_user_can_set_authors()` #883
* Fix tests and add IDE files #882
* Bump dependencies #866 #865 #864

## [3.5.3] - 2022-10-24

* Add author taxonomy labels #860
* Add check for empty author in Jetpack Open Graph tags #861
* Improve performance of search_authors() #872
* Update WP versions in tests and grant permission to composer-installer packages #863

## [3.5.2] - 2022-06-01

* Remove the `/wp/v2/coauthors` endpoint #851
* Remove CSS hack that hides the add author box from core in the block editor #847

## [3.5.1] - 2022-04-25

* Fix error for undefined `use_block_editor_for_post` #843

## [3.5] - 2022-04-21

* Add Block Editor integration #790
* Fix issues with cache key `id` not being deleted #832
* Fix broken SQL query caused by wpdb->prepare() escaping quotes #831
* Fix `_update_users_posts_count` function to recount Guest Author's shadow taxonomy term count #829
* Update dependencies #833 #834 #835 #836 #837 #838

**3.4.92 (Oct 14, 2021)**
* Fix admin posts view for contributors #823

**3.4.91 (Aug 12, 2021)**
* Revert change with #686 regex change #816

**3.4.9 (Aug 11, 2021)**
* Fix clash on updating guest author with same slug as a WP_User #748
* Resolve warning from fix_author_page() when called too early #801
* Adjust regex on posts_where_filter to be less greedy #686
* Update deprecated GitHub hosted runner #808
* Replace deprecated jQuery shorthand methods #812
* Switch guest author meta box to aside context #746

**3.4.8 (June 15, 2021)**
* Fix AJAX author autocomplete when no author to post in author selector #803

**3.4.7 (May 31, 2021)**
* Fix accidental escaping introduced to `coauthors_posts_links()` #797

**3.4.6 (May 28, 2021)**
* Gravatars for guest authors now display in author select box #767
* Fix non-functional filter button on Guest Author listing page #768
* Added Hebrew translation strings #772
* Improve code style via PHPCBF #771 and .editorconfig #773
* Fix bug where guest author with pipe character in name is not rendered in author select box #775
* Fix `get_coauthor_by()` for correct return of users prefixed with "Cap" in name #778
* Correct failing unit tests under WP 5.7 #774
* Linked accounts display more accurate counts #776
* Switch to GitHub Actions from TravisCI #784
* Update .gitignore to exclude composer lock file and vendor dir #787
* Hide author section in Gutenberg for WP 5.7+ #793
* Delete correct CAP associated term on user delete #792
* Move changelog out of readme #768

**3.4.5 (Mar. 28, 2021)**
* Fixed bug where guest authors with spaces in names were not showing when queried in AJAX suggest box #764

**3.4.4 (Mar. 26, 2021)**
* Fixed bug where author with slug beginning with "cap" does not appear in bylines AJAX suggest box when queried #744
* Travis for Xenial build no longer fails #751
* Unit tests no longer fail #752
* Fixed REST permissions bug where co-author cannot view post assigned to them in Gutenberg #757

**3.4.3 (Apr. 20, 2020)**
* Added author support to CPT instructions in readme FAQ #720
* Added object check for user in `coauthors_set_post_author_field()` #721
* Fix inefficient user query in avatar url hook #724
* Fix operand typo in `get_guest_author_thumbnail()` for adding custom classes #725
* Remove hardcoded default avatar and use default option #728

**3.4.2 (Dec. 16, 2019)**
* Fix incorrect user avatar being displayed from featured post image #706
* Add check for `filter_get_avatar_url` to ensure valid second parameter #707
* `add_coauthors()` accepts ID parameter now #685 and ensures valid term slug used #708
* `filter_count_user_posts` checks that user ID returns valid user object #714
* Added post count instructions in readme FAQ for CPTs #713

**3.4.1 (Aug. 19, 2019)**
* Fix an issue that may arise in bulk edit #700

**3.4 (May 6, 2019)**
* New filter `get_coauthors` for modifying coauthor data returned in `get_coauthors()` #646
* New filter `coauthors_guest_authors_exported_extra_data` to allow guest author to export data as regular author #528
* New filter `get_avatar_url()` to show avatar in JS selection #621
* New parameter in `coauthors_wp_list_authors()` to only query authors with posts #496
* Add internationalization support to title and name in author archives #516
* Add safe list to skip irrelevant capabilities during permission checks #543
* Add helper function `get_guest_author_post_count()` #605
* Add parameter for outputting HTML classes in `coauthors_get_avatar()` template tag #610
* Add `--append_coauthors` flag to synopsis of CLI `assign-coauthors` #600
* Adjust CLI command `create-guest-authors-from-csv` to import website, avatar and description (#603 and #619)
* Post type of "any" can be used in filters #617
* Remove unnecessary `is_array()` check #471
* Remove unnecessary `action_pre_user_query()` #531
* Use correct args in `search_authors()` #519
* Have `filter_author_archive_title()` run on author archives only #535
* Improve tests coverage (#529, #540, #546, #576 and #569)
* Change `posts_selection` to action from filter #563
* Fix number of args expected for `get_the_archive_title` callback #657
* Fix spelling, update FAQ for disabling guest authors and credits in readme (#656, #523 and #501)
* Output `coauthors_links_single()` template tag correctly when guest author has no website #504
* Number by "Mine" link shows correct listing of posts #663
* Linked guest authors show accurate post counts #674
* Can no longer add co-author more than once #532
* No more overwriting posts with current user in `add_coauthors()` #545
* Accurate post count for user when using different login #558
* No more double post count for users with linked accounts #567
* Fix SQL error (#593 and #628)
* Fix "Mine" link href for Pages #547
* Can delete users when guest authors functionality disabled #602
* Fix incompatibility issue with Yoast of missing posts in author pages #624
* Resolve undefined index warnings on author archives #521
* Resolve warnings when current user has no term assigned #517

Props: [TheCrowned](https://github.com/TheCrowned), [shantanu2704](https://github.com/shantanu2704), [WPprodigy](https://github.com/WPprodigy), [blunce24](https://github.com/blunce24), [rebeccahum](https://github.com/rebeccahum), [andrewfleming](https://github.com/andrewfleming), [justnorris](https://github.com/justnorris), [sboisvert](https://github.com/sboisvert), [jasonbahl](https://github.com/jasonbahl), [mariovalney](https://github.com/mariovalney), [RoyTheRoyalBoy](https://github.com/RoyTheRoyalBoy), [jacobarriola](https://github.com/jacobarriola), [smistephen](https://github.com/smistephen), [manzoorwanijk](https://github.com/manzoorwanijk), [kodemonster](https://github.com/kodemonster), [westonruter](https://github.com/westonruter), [binodkalathil](https://github.com/binodkalathil), [scofennell](https://github.com/scofennell), [hyperionjrw](https://github.com/hyperionjrw), [pdemarte](https://github.com/pdemarte), [mostafaabd](https://github.com/mostafaabd), [paulschreiber](https://github.com/paulschreiber)

**3.3.1 "Gutentag" (Dec. 7, 2018)**
* 5.0 Compat: Hide core author inputs when using the Block Editor to limit confusion (h/t jonathanstegall).

**3.3.0 "Rebecca" (Apr. 16, 2018)**
* Fix private post viewing on front-end #386
* Reduce amount of sleep #400
* Author search UX issues #407
* Remove associated guest user when mapped user id deleted. #414
* Removed double left join on posts_join_filter #419
* Fixed WP CLI create-terms-for-posts if no co-authors found #420
* Pages archive now displays co-authors and quick edit works #422
* Terminology updated throughout #423
* Replace hardcoded 'author' with $this->$coauthor_taxonomy #426
* Move parenthesis to fix esc_html and sprintf #430
* Added progress to create-guest-authors so users have an idea of how long it will take #431
* Deleting guest authors is less confusing #432
* Guest author's featured image is avatar now #433
* Removed extra image sizing #434
* Remove duplicated byline #435
* coauthors_wp_list_authors() has option to list only guest authors now #436
* remove duplicates from linked accounts on coauthors_wp_list_authors() #437
* Accurate Guest Author post count on linked accounts #438
* New README.md #439
* Filter author archive #441
* Fix coauthors_links_single() #444
* Added guest author hooks for create/delete #446
* Fixes logic for DOING_AUTOSAVE check #450
* user_login spaces problem when using add_coauthors #453
* Adding details of filter for slow performance #456
* Remove redundant test for 404 on Author Archive #457
* Guest Author Counts are more accurate #461
* Set $coauthors_loading #468
* Fix the issue where guest authors with non-ASCII characters can't be used as co-authors #473
* Fix the issue where incompatibility when `coauthors_auto_apply_template_tags` set to true #474
* Unit tests/Fix warnings for template tags #475
* Review and improve test coverage #476
* Update class-wp-cli.php #480
* Update .travis.yml file for PHPUnit tests #482
* Changes to resolve issue #332 about missing coauthor meta #484

Props to the many people who helped make this release possible: [catchmyfame](https://github.com/catchmyfame), [danielbachhuber](https://github.com/danielbachhuber), [david-binda](https://github.com/david-binda), [douglas-johnson](https://github.com/douglas-johnson), [castlehouse](https://github.com/castlehouse), [frankar](https://github.com/frankar), [haleeben](https://github.com/haleeben), [jjeaton](https://github.com/jjeaton), [johnbillion](https://github.com/johnbillion), [kevinlisota](https://github.com/kevinlisota), [mattoperry](https://github.com/mattoperry), [mdbitz](https://github.com/mdbitz), [mdchiragpatel](https://github.com/mdchiragpatel), [megfh](https://github.com/megfh), [mjangda](https://github.com/mjangda), [mslinnea](https://github.com/mslinnea), [natebot](https://github.com/natebot), [nickdaugherty](https://github.com/nickdaugherty), [nilzari](https://github.com/nilzari), [philipjohn](https://github.com/philipjohn), [pkevan](https://github.com/pkevan), [rebeccahum](https://github.com/rebeccahum), [ryanmarkel](https://github.com/ryanmarkel), [sanketio](https://github.com/sanketio), [sboisvert](https://github.com/sboisvert), [Spongsta](https://github.com/Spongsta), [srguglielmo](https://github.com/srguglielmo), [timburden](https://github.com/timburden), [trepmal](https://github.com/trepmal), [TylerDurdon](https://github.com/TylerDurdon)

**3.2.2 (Apr. 3, 2017)**
* Fix broken author ordering in 4.7+ (props mslinnea)
* Fix no moderation e-mail bug (props RobjS)
* Cached functions in CLI commands (props jasonbahl)
* Fix missing echos (props trepmal)
* Add `coauthors_guest_author_query_args` filter (props trepmal)

**3.2.1 (May 16, 2016)**
* Hotfix for broken Guest Author bio metabox (props JS Morisset)

**3.2 (May 12, 2016)**
* Various minor bug and security fixes

**3.1.2 (Aug. 31, 2015)**
* Minor bug fixes and coding standards changes.
* The author's display name is now filtered through `the_author` in `coauthors_posts_links_single()`
* New Russian and Ukrainian translations, courtesy of [Jurko Chervony](http://skinik.name/).

**3.1.1 (Mar. 20, 2014)**
* Bug fix: Co-authors selection UI should appear when creating a new post too.

**3.1 (Mar. 17, 2014)**
* Manage co-authors from Quick Edit. Props [mpatek](https://github.com/mpatek).
* Updated Spanish translation, courtesy of [sergiomajluf](https://github.com/sergiomajluf).
* Now matches core behavior when displaying author archive on multisite: user of the blog, or previously published author on the blog.
* Breaking change: "Create Profile" link is no longer shown by default on the Manage Users screen. Instead, it can be enabled with the `coauthors_show_create_profile_user_link` filter.
* Guest authors work properly with Jetpack Open Graph tags. Props [hibernation](https://github.com/hibernation).
* Guest author profile editor now supports a few different fields. Props [alpha1](https://github.com/alpha1).
* New `coauthors_count_published_post_types` filter for specifying the post type(s) used when calculating the user's number of published posts.
* Bug fix: Ensure `post_author` is set to one of the co-authors assigned to a post.
* Bug fix: Filter author feed link for guest authors on the author page. Props [hibernation](https://github.com/hibernation).
* Packages a composer.json file for those using Composer.
* Beginnings of unit test coverage for core features. Increased minimum required WordPress version to 3.7 because WordPress.org unit testing framework doesn't work reliabilty below that.

**3.0.7 (Jan. 27, 2014)**
* Better support for installing Co-Authors Plus as a symlinked directory. [Follow these instructions](http://kaspars.net/blog/wordpress/plugins-via-symlinks) to filter `plugins_url`.
* Links to authors' posts pages to comply to hCard microformat, which Google depends on.
* New `coauthors_emails()` template tag to list email addresses of the co-authors. Props [benlk](https://github.com/benlk).
* Bug fix: Remove extraneous space between last two co-authors output. Props [johnciacia](https://github.com/johnciacia).
* Updated French translation, courtesy of Jojaba (via email).

**3.0.6 (Dec. 9, 2013)**
* New Swedish translation, courtesy of [alundstroem](https://github.com/alundstroem)
* Updated German translation, courtesy of [krafit](https://github.com/krafit).
* New Dutch translation, courtesy of [kardotim](https://github.com/kardotim)
* New filter for specifying the default author assigned to a post. Props [tannerm](https://github.com/tannerm)
* Bug fix: When filtering a user's published post count, use the value of their guest author profile if one is mapped.
* Added support for checkboxes in Guest Author profiles
* Fix Strict warnings from CPT's that don't define all capabilities
* New swap-coauthors CLI command for replacing one co-author with another

**3.0.5 (Feb. 18, 2013)**
* New filter `coauthors_search_authors_get_terms_args` allows you to increase the number of matches returned with AJAX co-author selection
* Bug fix: If there isn't an author term yet for a co-author, avoid an erroneous join that caused duplicate posts to appear.

**3.0.4 (Jan. 6, 2013)** =
* Support for automatically adding co-authors to your feeds. Props [cfg](https://github.com/cfg).
* Bug fix: No Co-Authors Plus on attachments. For now.
* Bug fix: Better support for co-authors with non-standard user_nicenames. Props [STRML](https://github.com/STRML).

**3.0.3 (Dec. 3, 2012)**
* Bug fix: The default order for the 'author' taxonomy should be `term_order`, in order for the author positions to stick. Props [lgedeon](https://github.com/lgedeon)

**3.0.2 (Nov. 23, 2012)**
* Bug fix: Fall back to non-pretty permalinks when the author permastruct is empty, so that `coauthors_posts_links()` doesn't link to the homepage

**3.0.1 (Nov. 21, 2012)**
* Add your own custom columns to the guest authors table using filters. Props [cfg](https://github.com/cfg)
* A new wp-cli subcommand for renaming co-authors and another for removing author terms mistakenly assigned to revisions
* Bug fix: Using a featured image for a guest author avatar didn't work. Now it does.
* Bug fix: Don't assign author terms to revisions to avoid unnecessary database bloat
* Bug fix: Make the `coauthors_wp_list_authors()` template tag work again
* Bug fix: Improve capability filtering by properly handling super admin access and situations where `user_id = 0`
* Minor UI enhancements for guest authors

**3.0 (Nov. 12, 2012)**
* Create guest author profiles for bylines you'd like to assign without creating WordPress user accounts. Guest authors can have all the same fields as normal users including display name, biography, and avatars.
* Support for non-Latin characters in usernames and guest author names
* wp-cli subcommands for creating, assigning, and reassigning co-authors
* For themes using core template tags like `the_author()` or `the_author_posts_link()`, you enable Co-Authors Plus support with a simple filter
* New author terms are now prefixed with `cap-` to avoid collisions with global scope
* Bug fix: Apply query filters to only `post_types` registered with the taxonomy. Props [Tom Ransom](https://github.com/1bigidea)
* Filter `coauthors_posts_link_single()` with `coauthors_posts_link`. Also adds `rel="author"`. Props [Amit Sannad](https://github.com/asannad) and [Gabriel Koen](https://github.com/mintindeed)
* Filter for the context and priorities of the Co-Authors meta boxes. Props [Tomáš Kapler](https://github.com/tkapler)
* Renamed the post meta box for selecting authors, so it applies to many post types. Props [John Blackbourn](https://github.com/johnbillion)

**2.6.4 (May 7, 2012)**
* Bug fix: Properly filter the user query so users can AJAX search against the display name field again
* If https is used for the admin, also use the secure Gravatar URL. Props [rmcfrazier](https://github.com/rmcfrazier)

**2.6.3 (Apr. 30, 2012)**
* AJAX user search is back to searching against user login, display name, email address and user ID. The method introduced in v2.6.2 didn't scale well
* French translation courtesy of Sylvain Bérubé
* Spanish translation courtesy of Alejandro Arcos
* Bug fix: Resolved incorrect caps check against user editing an already published post. [See forum thread](http://wordpress.org/support/topic/multiple-authors-cant-edit-pages?replies=17#post-2741243)

**2.6.2 (Mar. 6, 2012)**
* AJAX user search matches against first name, last name, and nickname fields too, in addition to display name, user login, and email address
* Comment moderation and approved notifications are properly sent to all co-authors with the correct caps
* Filter required capability for user to be returned in an AJAX search with `coauthors_edit_author_cap`
* Filter out administrators and other non-authors from AJAX search with `coauthors_edit_ignored_authors`
* Automatically adds co-authors to Edit Flow's story budget and calendar views
* Bug fix: Don't set post_author value to current user when quick editing a post. This doesn't appear in the UI anywhere, but adds the post to the current user's list of posts
* Bug fix: Properly cc other co-authors on new comment email notifications
* Bug fix: If a user has already been added as an author to a post, don't show them in the AJAX search again
* Bug fix: Allow output constants to be defined in a theme's functions.php file and include filters you can use instead

**2.6.1 (Dec. 30, 2011)**
* Fix mangled usernames because of sanitize_key http://wordpress.org/support/topic/plugin-co-authors-plus-26-not-working-with-wp-33

**2.6 (Dec. 22, 2011)**
* Sortable authors: Drag and drop the order of the authors as you'd like them to appear ([props kingkool68](http://profiles.wordpress.org/users/kingkool68/))
* Search for authors by display name (instead of nicename which was essentially the same as user_login)
* Option to remove the first author when there are two or more, so it's less confusing
* Bumped requirements to WordPress 3.1
* Bug fix: Update the published post count for each user more reliably

**2.5.3 (Aug. 14, 2011)**
* Bug fix: Removed extra comma when only two authors were listed. If you used the `COAUTHORS_DEFAULT_BETWEEN_LAST` constant, double-check what you have

**2.5.2 (Apr. 23, 2011)**
* Bug: Couldn't query terms and authors at the same time (props nbaxley)
* Bug: Authors with empty fields (e.g. first name) were displaying blank in some cases
* Bug: authors with spaces in usernames not getting saved (props MLmsw, Ruben S. and others!)
* Bug: revisions getting wrong user attached (props cliquenoir!)

**2.5.1 (Mar. 26, 2011)**
* Fix with author post count (throwing errors)

**2.5 (Mar. 26, 2011)**
* Custom Post Type Support
* Compatibility with WP 3.0 and 3.1
* Gravatars
* Lots and lots and lots of bug fixes
* Thanks to everyone who submitted bugs, fixes, and suggestions! And for your patience!

**2.1.1 (Oct. 16, 2009)**
* Fix for co-authors not being added if their username is different from display name
* Fixes to readme.txt (fixes for textual and punctuation errors, language clarification, minor formatting changes) courtesy of [Waldo Jaquith](http://www.vqronline.org)

**2.1 (Oct. 11, 2009)**
* Fixed issues related to localization. Thanks to Jan Zombik <zombik@students.uni-mainz.de> for the fixes.
* Added `set_time_limit` to update function to get around timeout issues when upgrading plugin

**2.0 (Oct. 11, 2009)**
* Plugin mostly rewritten to make use of taxonomy instead of `post_meta`
* Can now see all authors of a post under the author column from Edit Posts page
* All authors of a post are now notified on a new comment
* Various javascript enhancements
* New option to allow subscribers to be added as authors
* All Authors can edit the posts of which they are co-authors
* FIX: Issues with `wp_coauthors_list` function
* FIX: Issues with coauthored posts not showing up on author archives

**1.2.0 (Jun. 16, 2012)**
* FIX: Added compatibility for WordPress 2.8
* FIX: Added new template tags (`get_the_coauthor_meta` & `the_coauthor_meta`) to fix issues related to displaying author info on author archive pages. See [Other Notes](http://wordpress.org/extend/plugins/co-authors-plus/other_notes/) for details.
* FIX: Plugin should now work for plugins not using the `wp_` DB prefix
* FIX: Coauthors should no longer be alphabetically reordered when the post is updated
* FIX: Plugin now used WordPress native AJAX calls to tighten security
* DOCS: Added details about the new template tags

**1.1.5 (Apr. 26, 2009)**
* FIX: Not searching Updated SQL query for autosuggest to search through first name, last name, and nickname
* FIX: When editing an author, and clicking on a suggested author, the original author was not be removed
* DOCS: Added code comments to javascript; more still to be added
* DOCS: Updated readme information

**1.1.4 (Apr. 25, 2009)**
* Disabled "New Author" output in suggest box, for now
* Hopefully fixed SVN issue (if you're having trouble with the plugin, please delete the plugin and reinstall)

**1.1.3 (Apr. 23, 2009)**
* Add blur event to disable input box
* Limit only one edit at a time.
* Checked basic cross-browser compatibility (Firefox 3 OS X, Safari 3 OS X, IE7 Vista).
* Add suggest javascript plugin to Edit Page.

**1.1.2 (Apr. 19, 2009)**
* Disabled form submit when enter pressed.

**1.1.1 (Apr. 15, 2009)**
* Changed SQL query to return only contributor-level and above users.

**1.1.0 (Apr. 14, 2009)**
* Initial beta release.

[4.0.2]: https://github.com/automattic/co-authors-plus/compare/4.0.1...4.0.2
[4.0.1]: https://github.com/automattic/co-authors-plus/compare/4.0.0...4.0.1
[4.0.0]: https://github.com/automattic/co-authors-plus/compare/3.7.0...4.0.0
[3.7.0]: https://github.com/automattic/co-authors-plus/compare/3.6.6...3.7.0
[3.6.6]: https://github.com/automattic/co-authors-plus/compare/3.6.5...3.6.6
[3.6.5]: https://github.com/automattic/co-authors-plus/compare/3.6.4...3.6.5
[3.6.4]: https://github.com/automattic/co-authors-plus/compare/3.6.3...3.6.4
[3.6.3]: https://github.com/automattic/co-authors-plus/compare/3.6.2...3.6.3
[3.6.2]: https://github.com/automattic/co-authors-plus/compare/3.6.1...3.6.2
[3.6.1]: https://github.com/automattic/co-authors-plus/compare/3.6.0...3.6.1
[3.6.0]: https://github.com/automattic/co-authors-plus/compare/3.5.15...3.6.0
[3.5.15]: https://github.com/automattic/co-authors-plus/compare/3.5.14...3.5.15
[3.5.14]: https://github.com/automattic/co-authors-plus/compare/3.5.13...3.5.14
[3.5.13]: https://github.com/automattic/co-authors-plus/compare/3.5.12...3.5.13
[3.5.12]: https://github.com/automattic/co-authors-plus/compare/3.5.11...3.5.12
[3.5.11]: https://github.com/automattic/co-authors-plus/compare/3.5.10...3.5.11
[3.5.10]: https://github.com/automattic/co-authors-plus/compare/3.5.9...3.5.10
[3.5.9]: https://github.com/automattic/co-authors-plus/compare/3.5.8...3.5.9
[3.5.8]: https://github.com/automattic/co-authors-plus/compare/3.5.7...3.5.8
[3.5.7]: https://github.com/automattic/co-authors-plus/compare/3.5.6...3.5.7
[3.5.6]: https://github.com/automattic/co-authors-plus/compare/3.5.5...3.5.6
[3.5.5]: https://github.com/automattic/co-authors-plus/compare/3.5.4...3.5.5
[3.5.4]: https://github.com/automattic/co-authors-plus/compare/3.5.3...3.5.4
[3.5.3]: https://github.com/automattic/co-authors-plus/compare/3.5.2...3.5.3
[3.5.2]: https://github.com/automattic/co-authors-plus/compare/3.5.1...3.5.2
[3.5.1]: https://github.com/automattic/co-authors-plus/compare/3.5...3.5.1
[3.5]: https://github.com/automattic/co-authors-plus/compare/3.4.92...3.5
