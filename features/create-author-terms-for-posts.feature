Feature: Missing author terms can be backfilled for targeted posts

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`

	Scenario: Backfill author terms for published posts that are missing them, then find nothing on a second run
		When I run `wp post create --post_title="First post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post create --post_title="Second post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp post term remove {POST_A} author --all`
		And I run `wp post term remove {POST_B} author --all`
		And I run `wp co-authors-plus create-author-terms-for-posts`
		Then STDOUT should be:
		"""
		Found 2 posts with missing author terms.
		Processing post {POST_A} (1/2 or 50.00%)
		Success: Inserted term relationship for post {POST_A} and author 1 (admin).
		Processing post {POST_B} (2/2 or 100.00%)
		Success: Inserted term relationship for post {POST_B} and author 1 (admin).
		2 records affected
		Success: Done!
		"""
		When I run `wp term list author --object_ids={POST_A} --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		"""
		When I run `wp term list author --slug=cap-admin --field=count`
		Then STDOUT should be:
		"""
		2
		"""
		When I run `wp co-authors-plus create-author-terms-for-posts`
		Then STDOUT should be:
		"""
		Found 0 posts with missing author terms.
		0 records affected
		Success: Done!
		"""

	Scenario: Each post gets a term for its own author and every author term is updated
		When I run `wp user create alpha alpha@example.com --role=author --porcelain`
		And save STDOUT as {USER_A}
		And I run `wp user create beta beta@example.com --role=author --porcelain`
		And save STDOUT as {USER_B}
		And I run `wp post create --post_title="Alpha post" --post_status=publish --post_author={USER_A} --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post create --post_title="Beta post" --post_status=publish --post_author={USER_B} --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp post term remove {POST_A} author --all`
		And I run `wp post term remove {POST_B} author --all`
		And I run `wp co-authors-plus create-author-terms-for-posts`
		Then STDOUT should be:
		"""
		Found 2 posts with missing author terms.
		Processing post {POST_A} (1/2 or 50.00%)
		Success: Inserted term relationship for post {POST_A} and author {USER_A} (alpha).
		Processing post {POST_B} (2/2 or 100.00%)
		Success: Inserted term relationship for post {POST_B} and author {USER_B} (beta).
		2 records affected
		Success: Done!
		"""
		When I run `wp term list author --object_ids={POST_A} --field=slug`
		Then STDOUT should be:
		"""
		cap-alpha
		"""
		When I run `wp term list author --object_ids={POST_B} --field=slug`
		Then STDOUT should be:
		"""
		cap-beta
		"""

	Scenario: Draft posts are only processed when --post-statuses includes draft
		When I run `wp post create --post_title="Draft post" --post_author=1 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post term remove {POST_ID} author --all`
		And I run `wp co-authors-plus create-author-terms-for-posts`
		Then STDOUT should be:
		"""
		Found 0 posts with missing author terms.
		0 records affected
		Success: Done!
		"""
		When I run `wp co-authors-plus create-author-terms-for-posts --post-statuses=draft`
		Then STDOUT should be:
		"""
		Found 1 post with missing author terms.
		Processing post {POST_ID} (1/1 or 100.00%)
		Success: Inserted term relationship for post {POST_ID} and author 1 (admin).
		1 record affected
		Success: Done!
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		"""

	Scenario: Several statuses can be targeted at once with a comma separated --post-statuses
		When I run `wp post create --post_title="Published post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post create --post_title="Draft post" --post_author=1 --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp post term remove {POST_A} author --all`
		And I run `wp post term remove {POST_B} author --all`
		And I run `wp co-authors-plus create-author-terms-for-posts --post-statuses=publish,draft`
		Then STDOUT should be:
		"""
		Found 2 posts with missing author terms.
		Processing post {POST_A} (1/2 or 50.00%)
		Success: Inserted term relationship for post {POST_A} and author 1 (admin).
		Processing post {POST_B} (2/2 or 100.00%)
		Success: Inserted term relationship for post {POST_B} and author 1 (admin).
		2 records affected
		Success: Done!
		"""

	Scenario: Pages are only processed when --post-types includes page
		When I run `wp post create --post_type=page --post_title="About page" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {PAGE_ID}
		And I run `wp post term remove {PAGE_ID} author --all`
		And I run `wp co-authors-plus create-author-terms-for-posts`
		Then STDOUT should be:
		"""
		Found 0 posts with missing author terms.
		0 records affected
		Success: Done!
		"""
		When I run `wp co-authors-plus create-author-terms-for-posts --post-types=page`
		Then STDOUT should be:
		"""
		Found 1 post with missing author terms.
		Processing post {PAGE_ID} (1/1 or 100.00%)
		Success: Inserted term relationship for post {PAGE_ID} and author 1 (admin).
		1 record affected
		Success: Done!
		"""

	Scenario: Only the posts named in --specific-post-ids are processed
		When I run `wp post create --post_title="First post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post create --post_title="Second post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp post term remove {POST_A} author --all`
		And I run `wp post term remove {POST_B} author --all`
		And I run `wp co-authors-plus create-author-terms-for-posts --specific-post-ids={POST_A}`
		Then STDOUT should be:
		"""
		Found 1 post with missing author terms.
		Processing post {POST_A} (1/1 or 100.00%)
		Success: Inserted term relationship for post {POST_A} and author 1 (admin).
		1 record affected
		Success: Done!
		"""
		When I run `wp term list author --object_ids={POST_A} --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		"""
		When I run `wp term list author --object_ids={POST_B} --format=count`
		Then STDOUT should be:
		"""
		0
		"""
		# A valid range that would exclude both posts, to prove --specific-post-ids still
		# wins. This used an INVALID range, which now exits 1 — so re-pinning it without
		# thought would have quietly dropped the precedence coverage altogether.
		When I run `wp co-authors-plus create-author-terms-for-posts --specific-post-ids={POST_A},{POST_B} --above-post-id={POST_A} --below-post-id={POST_B}`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Warning: --above-post-id and --below-post-id are ignored when --specific-post-ids is given.
		Found 1 post with missing author terms.
		Processing post {POST_B} (1/1 or 100.00%)
		Success: Inserted term relationship for post {POST_B} and author 1 (admin).
		1 record affected
		Success: Done!
		"""
		When I run `wp term list author --object_ids={POST_B} --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		"""

	Scenario: Only posts strictly between --above-post-id and --below-post-id are processed
		When I run `wp post create --post_title="First post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post create --post_title="Second post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp post create --post_title="Third post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_C}
		And I run `wp post term remove {POST_A} author --all`
		And I run `wp post term remove {POST_B} author --all`
		And I run `wp post term remove {POST_C} author --all`
		And I run `wp co-authors-plus create-author-terms-for-posts --above-post-id={POST_A} --below-post-id={POST_C}`
		Then STDOUT should be:
		"""
		Found 1 post with missing author terms.
		Processing post {POST_B} (1/1 or 100.00%)
		Success: Inserted term relationship for post {POST_B} and author 1 (admin).
		1 record affected
		Success: Done!
		"""
		When I run `wp term list author --object_ids={POST_A} --format=count`
		Then STDOUT should be:
		"""
		0
		"""
		When I run `wp term list author --object_ids={POST_C} --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Only posts above --above-post-id are processed when no upper bound is given
		When I run `wp post create --post_title="First post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post create --post_title="Second post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp post term remove {POST_A} author --all`
		And I run `wp post term remove {POST_B} author --all`
		And I run `wp co-authors-plus create-author-terms-for-posts --above-post-id={POST_A}`
		Then STDOUT should be:
		"""
		Found 1 post with missing author terms.
		Processing post {POST_B} (1/1 or 100.00%)
		Success: Inserted term relationship for post {POST_B} and author 1 (admin).
		1 record affected
		Success: Done!
		"""
		When I run `wp term list author --object_ids={POST_A} --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Only posts below --below-post-id are processed when no lower bound is given
		When I run `wp post create --post_title="First post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post create --post_title="Second post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp post term remove {POST_A} author --all`
		And I run `wp post term remove {POST_B} author --all`
		And I run `wp co-authors-plus create-author-terms-for-posts --below-post-id={POST_B}`
		Then STDOUT should be:
		"""
		Found 1 post with missing author terms.
		Processing post {POST_A} (1/1 or 100.00%)
		Success: Inserted term relationship for post {POST_A} and author 1 (admin).
		1 record affected
		Success: Done!
		"""
		When I run `wp term list author --object_ids={POST_A} --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		"""
		When I run `wp term list author --object_ids={POST_B} --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: An invalid ID range is rejected with an error naming the parameters
		When I try `wp co-authors-plus create-author-terms-for-posts --above-post-id=10 --below-post-id=5`
		Then the return code should be 1
		And STDOUT should be empty
		And STDERR should be:
		"""
		Error: --above-post-id must be less than --below-post-id.
		"""
		When I try `wp co-authors-plus create-author-terms-for-posts --above-post-id=5 --below-post-id=5`
		Then the return code should be 1
		And STDOUT should be empty
		And STDERR should be:
		"""
		Error: --above-post-id must be less than --below-post-id.
		"""

	Scenario: A post whose author does not exist gets skip postmeta instead of a term
		When I run `wp post create --post_title="Orphan post" --post_status=publish --post_author=999 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp co-authors-plus create-author-terms-for-posts`
		Then STDOUT should be:
		"""
		Found 1 post with missing author terms.
		Processing post {POST_ID} (1/1 or 100.00%)
		Warning: Post Author ID 999 does not exist in wp_users table, inserting skip postmeta (`_cap_skip_backfill`).
		0 records affected
		Warning: 1 post was skipped and marked with `_cap_skip_backfill`.
		Success: Done!
		"""
		When I run `wp post meta get {POST_ID} _cap_skip_backfill`
		Then STDOUT should be:
		"""
		nonexistent_post_author_id
		"""
		When I run `wp co-authors-plus create-author-terms-for-posts`
		Then STDOUT should be:
		"""
		Found 0 posts with missing author terms.
		0 records affected
		Success: Done!
		"""

	# list-posts-without-terms reports these, so excluding them here made the two
	# diagnostics disagree about the same site. They now take the orphan path.
	Scenario: Posts with a post_author of zero are marked as unbackfillable
		When I run `wp post create --post_title="Nobody post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post get {POST_ID} --field=post_author`
		Then STDOUT should be:
		"""
		0
		"""
		When I run `wp term list author --object_ids={POST_ID} --format=count`
		Then STDOUT should be:
		"""
		0
		"""
		When I run `wp co-authors-plus create-author-terms-for-posts`
		Then STDOUT should be:
		"""
		Found 1 post with missing author terms.
		Processing post {POST_ID} (1/1 or 100.00%)
		Warning: Post Author ID 0 does not exist in wp_users table, inserting skip postmeta (`_cap_skip_backfill`).
		0 records affected
		Warning: 1 post was skipped and marked with `_cap_skip_backfill`.
		Success: Done!
		"""
		When I run `wp post meta get {POST_ID} _cap_skip_backfill`
		Then STDOUT should be:
		"""
		nonexistent_post_author_id
		"""

	Scenario: The skip tally is pluralised when more than one post is skipped
		When I run `wp post create --post_title="First nobody post" --post_status=publish --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post create --post_title="Second nobody post" --post_status=publish --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp co-authors-plus create-author-terms-for-posts`
		Then STDOUT should be:
		"""
		Found 2 posts with missing author terms.
		Processing post {POST_A} (1/2 or 50.00%)
		Warning: Post Author ID 0 does not exist in wp_users table, inserting skip postmeta (`_cap_skip_backfill`).
		Processing post {POST_B} (2/2 or 100.00%)
		Warning: Post Author ID 0 does not exist in wp_users table, inserting skip postmeta (`_cap_skip_backfill`).
		0 records affected
		Warning: 2 posts were skipped and marked with `_cap_skip_backfill`.
		Success: Done!
		"""
		When I run `wp post meta get {POST_A} _cap_skip_backfill`
		Then STDOUT should be:
		"""
		nonexistent_post_author_id
		"""
		When I run `wp post meta get {POST_B} _cap_skip_backfill`
		Then STDOUT should be:
		"""
		nonexistent_post_author_id
		"""

	Scenario: Batches are re-queried when --records-per-batch is smaller than the total, and a skipped post does not stall progress
		When I run `wp post create --post_title="Orphan post" --post_status=publish --post_author=999 --porcelain`
		And save STDOUT as {ORPHAN_ID}
		And I run `wp post create --post_title="First post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post create --post_title="Second post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp post term remove {POST_A} author --all`
		And I run `wp post term remove {POST_B} author --all`
		And I run `wp co-authors-plus create-author-terms-for-posts --records-per-batch=1`
		Then STDOUT should be:
		"""
		Found 3 posts with missing author terms.
		Processing post {ORPHAN_ID} (1/3 or 33.33%)
		Warning: Post Author ID 999 does not exist in wp_users table, inserting skip postmeta (`_cap_skip_backfill`).
		Processing page 2.
		Processing post {POST_A} (2/3 or 66.67%)
		Success: Inserted term relationship for post {POST_A} and author 1 (admin).
		Processing page 3.
		Processing post {POST_B} (3/3 or 100.00%)
		Success: Inserted term relationship for post {POST_B} and author 1 (admin).
		2 records affected
		Warning: 1 post was skipped and marked with `_cap_skip_backfill`.
		Success: Done!
		"""
		When I run `wp post meta get {ORPHAN_ID} _cap_skip_backfill`
		Then STDOUT should be:
		"""
		nonexistent_post_author_id
		"""

	Scenario: Process everything in a single pass with --unbatched
		When I run `wp post create --post_title="First post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post term remove {POST_ID} author --all`
		And I run `wp co-authors-plus create-author-terms-for-posts --unbatched`
		Then STDOUT should be:
		"""
		Found 1 post with missing author terms.
		Processing post {POST_ID} (1/1 or 100.00%)
		Success: Inserted term relationship for post {POST_ID} and author 1 (admin).
		1 record affected
		Success: Done!
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		"""

	Scenario: Skip postmeta can be deleted so posts are backfilled again
		When I run `wp post create --post_title="Orphan post" --post_status=publish --post_author=999 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp co-authors-plus create-author-terms-for-posts`
		And I run `wp co-authors-plus delete-postmeta-that-skip-author-term-backfill`
		Then STDOUT should be:
		"""
		Success: Deleted `_cap_skip_backfill` postmeta from post {POST_ID}.
		"""
		When I run `wp post meta list {POST_ID} --keys=_cap_skip_backfill --format=count`
		Then STDOUT should be:
		"""
		0
		"""
		When I run `wp co-authors-plus create-author-terms-for-posts`
		Then STDOUT should be:
		"""
		Found 1 post with missing author terms.
		Processing post {POST_ID} (1/1 or 100.00%)
		Warning: Post Author ID 999 does not exist in wp_users table, inserting skip postmeta (`_cap_skip_backfill`).
		0 records affected
		Warning: 1 post was skipped and marked with `_cap_skip_backfill`.
		Success: Done!
		"""

	Scenario: Deleting skip postmeta from a post that does not have it warns and carries on
		When I run `wp post create --post_title="Regular post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_ID}
		When I run `wp co-authors-plus delete-postmeta-that-skip-author-term-backfill --specific-post-ids={POST_ID}`
		Then STDOUT should be:
		"""
		Warning: No `_cap_skip_backfill` postmeta to delete on post {POST_ID}.
		"""
		And the return code should be 0
		And STDERR should be empty

	Scenario: Deleting skip postmeta continues past a post that does not have it
		When I run `wp post create --post_title="Orphan post" --post_status=publish --post_author=999 --porcelain`
		And save STDOUT as {ORPHAN_ID}
		And I run `wp co-authors-plus create-author-terms-for-posts`
		And I run `wp post create --post_title="Regular post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_ID}
		When I run `wp co-authors-plus delete-postmeta-that-skip-author-term-backfill --specific-post-ids={POST_ID},{ORPHAN_ID}`
		Then STDOUT should be:
		"""
		Warning: No `_cap_skip_backfill` postmeta to delete on post {POST_ID}.
		Success: Deleted `_cap_skip_backfill` postmeta from post {ORPHAN_ID}.
		"""
		And the return code should be 0
		# The orphan is named second on purpose: the run used to abort on the first
		# post and never reach it, so this assertion is what fails without the fix.
		When I run `wp post meta list {ORPHAN_ID} --keys=_cap_skip_backfill --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: A non-numeric entry in --specific-post-ids is ignored with a warning while valid IDs are still processed
		When I run `wp post create --post_title="Orphan post" --post_status=publish --post_author=999 --porcelain`
		And save STDOUT as {ORPHAN_ID}
		And I run `wp co-authors-plus create-author-terms-for-posts`
		When I run `wp co-authors-plus delete-postmeta-that-skip-author-term-backfill --specific-post-ids=abc,{ORPHAN_ID}`
		Then STDOUT should be:
		"""
		Warning: Ignoring non-numeric post ID `abc` in --specific-post-ids.
		Success: Deleted `_cap_skip_backfill` postmeta from post {ORPHAN_ID}.
		"""
		And the return code should be 0
		When I run `wp post meta list {ORPHAN_ID} --keys=_cap_skip_backfill --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Deleting skip postmeta with nothing to delete produces no output
		When I run `wp co-authors-plus delete-postmeta-that-skip-author-term-backfill`
		Then STDOUT should be empty
		And the return code should be 0
		And STDERR should be empty

	Scenario: The bare delete removes skip postmeta from unpublished posts
		When I run `wp post create --post_title="Orphan draft" --post_author=999 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp co-authors-plus create-author-terms-for-posts --post-statuses=draft`
		Then STDOUT should contain:
		"""
		Warning: Post Author ID 999 does not exist in wp_users table, inserting skip postmeta (`_cap_skip_backfill`).
		"""
		When I run `wp co-authors-plus delete-postmeta-that-skip-author-term-backfill`
		Then STDOUT should contain:
		"""
		Success: Deleted `_cap_skip_backfill` postmeta from post {POST_ID}.
		"""
		And the return code should be 0
		When I run `wp post meta list {POST_ID} --keys=_cap_skip_backfill --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: The bare delete reaches past the first page of results
		When I run `wp eval 'for ( $i = 0; $i < 11; $i++ ) { $id = wp_insert_post( array( "post_title" => "Skipped $i", "post_status" => "publish", "post_author" => 1 ) ); add_post_meta( $id, "_cap_skip_backfill", "nonexistent_post_author_id", true ); }'`
		And I run `wp post list --meta_key=_cap_skip_backfill --post_status=any --format=count`
		Then STDOUT should be:
		"""
		11
		"""
		When I run `wp co-authors-plus delete-postmeta-that-skip-author-term-backfill`
		Then the return code should be 0
		And STDOUT should match #(Success: Deleted[\s\S]*?){11}#
		When I run `wp post list --meta_key=_cap_skip_backfill --post_status=any --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	# The range check used to sit inside the SQL builder, in a branch only reached
	# when no --specific-post-ids were given, so this combination was accepted.
	Scenario: An invalid ID range is rejected even when --specific-post-ids is given
		When I run `wp post create --post_title="First post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post term remove {POST_ID} author --all`
		When I try `wp co-authors-plus create-author-terms-for-posts --specific-post-ids={POST_ID} --above-post-id=10 --below-post-id=5`
		Then the return code should be 1
		And STDOUT should be empty
		And STDERR should be:
		"""
		Error: --above-post-id must be less than --below-post-id.
		"""
		When I run `wp term list author --object_ids={POST_ID} --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	# Guards the removal of the "Updating author terms with new counts" pass and the
	# deferred counting: the count is recalculated when counting resumes at the end
	# of the run, not by a second pass over the authors. The raw insert this command
	# used to do bypassed the count callback entirely, so this fails against it.
	Scenario: Backfilling recalculates the term count
		When I run `wp post create --post_title="Counted post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post term remove {POST_ID} author --all`
		And I run `wp eval '$t = get_term_by( "slug", "cap-admin", "author" ); global $wpdb; $wpdb->update( $wpdb->term_taxonomy, array( "count" => 5 ), array( "term_taxonomy_id" => $t->term_taxonomy_id ) );'`
		And I run `wp term list author --field=count`
		Then STDOUT should be:
		"""
		5
		"""
		When I run `wp co-authors-plus create-author-terms-for-posts`
		Then the return code should be 0
		When I run `wp term list author --field=count`
		Then STDOUT should be:
		"""
		1
		"""

	# The cache half of this fix cannot be shown in wp-env, which has no persistent
	# object cache, so this pins the mechanism instead: wp_set_object_terms() fires
	# the set_object_terms action, which CAP hooks to clear its coauthors_post_<id>
	# cache. The raw insert this command used to do fired no action at all, so this
	# scenario fails against it. The hook is planted through a --require file, as
	# testing.feature does; it sits in wp-content because /tmp does not survive a
	# fresh container. (A term_order assertion was tried first and abandoned: core
	# only writes it when its mid-function term query is not served from cache, so
	# it is not deterministic enough to pin.)
	Scenario: Backfilling fires the action that clears the co-author cache
		When I run `wp post create --post_title="Hooked post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post term remove {POST_ID} author --all`
		And I run `eval 'file_put_contents( "/var/www/html/wp-content/cap-log-set-object-terms.php", base64_decode( "PD9waHAKV1BfQ0xJOjphZGRfd3BfaG9vaygKCSdzZXRfb2JqZWN0X3Rlcm1zJywKCWZ1bmN0aW9uICggJG9iamVjdF9pZCwgJHRlcm1zLCAkdHRfaWRzLCAkdGF4b25vbXkgKSB7CgkJaWYgKCAnYXV0aG9yJyA9PT0gJHRheG9ub215ICkgewoJCQlXUF9DTEk6OmxvZyggInNldF9vYmplY3RfdGVybXMgZmlyZWQgZm9yIHBvc3QgeyRvYmplY3RfaWR9IiApOwoJCX0KCX0sCgkxMCwKCTQKKTsK" ) );'`
		And I run `--require=/var/www/html/wp-content/cap-log-set-object-terms.php co-authors-plus create-author-terms-for-posts`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		set_object_terms fired for post {POST_ID}
		"""
		When I run `eval 'unlink( "/var/www/html/wp-content/cap-log-set-object-terms.php" );'`
		Then the return code should be 0
