Feature: One co-author can be swapped with another on their posts

	# Outside preview mode the command makes progress only by removing the
	# cap-<from> term from each matched post and re-running the SAME WP_Query,
	# as it advances `paged` only when previewing. Any input leaving that term
	# in place would once loop forever; the two known causes are now rejected up
	# front and a no-progress guard aborts anything else, so the scenarios below
	# are safe to run. See docs/cli-behaviour-notes.md.

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`

	Scenario: Error on a missing required --from parameter
		When I try `wp co-authors-plus swap-coauthors --to=admin`
		Then STDERR should be:
		"""
		Error: Parameter errors:
		 missing --from parameter (The co-author to swap out.)
		"""
		And the return code should be 1

	Scenario: Error on a non-existent --from co-author
		When I try `wp co-authors-plus swap-coauthors --from=not-a-user --to=also-not-a-user`
		Then STDERR should be:
		"""
		Error: No co-author found for not-a-user
		"""
		And STDOUT should be empty
		And the return code should be 1

	Scenario: Error on a non-existent --to co-author
		When I try `wp co-authors-plus swap-coauthors --from=admin --to=not-a-user`
		Then STDERR should be:
		"""
		Error: No co-author found for not-a-user
		"""
		And STDOUT should be empty
		And the return code should be 1

	Scenario: Error on an empty --to value
		When I try `wp co-authors-plus swap-coauthors --from=admin --to=`
		Then STDERR should be:
		"""
		Error: --to param must not be empty
		"""
		And STDOUT should be empty
		And the return code should be 1

	Scenario: Validate --from before an empty --to
		When I try `wp co-authors-plus swap-coauthors --from=not-a-user --to=`
		Then STDERR should be:
		"""
		Error: No co-author found for not-a-user
		"""
		And STDOUT should be empty
		And the return code should be 1

	Scenario: Swap every matching post and number the log lines
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp user create author2 author2@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR2_ID}
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Post one" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_1}
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Post two" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_2}
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Post three" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_3}
		And I run `wp co-authors-plus swap-coauthors --from=author1 --to=author2`
		Then STDOUT should be:
		"""
		Swapping authorship from author1 to author2
		Found 3 posts to update.
		1: Post #{POST_ID_1} has been assigned "author2" as a co-author
		2: Post #{POST_ID_2} has been assigned "author2" as a co-author
		3: Post #{POST_ID_3} has been assigned "author2" as a co-author
		Success: All done!
		"""
		When I run the previous command again
		Then STDOUT should be:
		"""
		Swapping authorship from author1 to author2
		Found 0 posts to update.
		Success: All done!
		"""
		When I run `wp term list author --object_ids={POST_ID_1},{POST_ID_2},{POST_ID_3} --field=slug`
		Then STDOUT should be:
		"""
		cap-author2
		"""
		When I run `wp post get {POST_ID_1} --field=post_author`
		Then STDOUT should be:
		"""
		{AUTHOR2_ID}
		"""
		When I run `wp term list author --slug=cap-author1 --format=count`
		Then STDOUT should be:
		"""
		1
		"""

	Scenario: Preserve other co-authors when swapping
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp user create author2 author2@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR2_ID}
		And I run `wp user create author3 author3@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR3_ID}
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Shared post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		# Deliberate cross-command dependency: assign-user-to-coauthor is the only CLI
		# route to a second co-author that goes through add_coauthors(), which is what
		# swap-coauthors then has to preserve. `wp post term add` would attach the term
		# without touching post_author, so it would not exercise the same state.
		And I run `wp co-authors-plus assign-user-to-coauthor --user_login=author1 --coauthor=author3 --append_coauthors`
		And I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		cap-author3
		"""
		When I run `wp co-authors-plus swap-coauthors --from=author1 --to=author2`
		Then STDOUT should be:
		"""
		Swapping authorship from author1 to author2
		Found 1 posts to update.
		1: Post #{POST_ID} has been assigned "author2" as a co-author
		Success: All done!
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author2
		cap-author3
		"""
		When I run `wp post get {POST_ID} --field=post_author`
		Then STDOUT should be:
		"""
		{AUTHOR3_ID}
		"""

	Scenario: Preview a swap with --dry-run and change nothing
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp user create author2 author2@example.com --role=author --porcelain`
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Post one" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_1}
		When I run `wp co-authors-plus swap-coauthors --from=author1 --to=author2 --dry-run`
		Then STDOUT should be:
		"""
		Swapping authorship from author1 to author2
		Found 1 posts to update.
		1: Post #{POST_ID_1} will be assigned "author2" as a co-author
		Success: All done!
		"""
		When I run `wp term list author --object_ids={POST_ID_1} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		"""
		When I run `wp post get {POST_ID_1} --field=post_author`
		Then STDOUT should be:
		"""
		{AUTHOR1_ID}
		"""
		When I run `wp term list author --slug=cap-author2 --format=count`
		Then STDOUT should be:
		"""
		1
		"""

	Scenario: Accept the deprecated --dry flag as a preview, with a notice
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp user create author2 author2@example.com --role=author --porcelain`
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Post one" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_1}
		When I run `wp co-authors-plus swap-coauthors --from=author1 --to=author2 --dry`
		Then STDOUT should be:
		"""
		Warning: The --dry flag is deprecated; use --dry-run instead.
		Swapping authorship from author1 to author2
		Found 1 posts to update.
		1: Post #{POST_ID_1} will be assigned "author2" as a co-author
		Success: All done!
		"""
		When I run `wp term list author --object_ids={POST_ID_1} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		"""

	# WP-CLI treats a flag's value with PHP truthiness and documents --no-<flag>
	# as the way to switch one off, so both forms below are real swaps. Pinned so
	# the distinction stays deliberate rather than becoming a surprise.
	Scenario: Treat --dry-run=0 and --no-dry-run as a real swap
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp user create author2 author2@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR2_ID}
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Post one" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_1}
		When I run `wp co-authors-plus swap-coauthors --from=author1 --to=author2 --dry-run=0`
		Then STDOUT should be:
		"""
		Swapping authorship from author1 to author2
		Found 1 posts to update.
		1: Post #{POST_ID_1} has been assigned "author2" as a co-author
		Success: All done!
		"""
		When I run `wp term list author --object_ids={POST_ID_1} --field=slug`
		Then STDOUT should be:
		"""
		cap-author2
		"""
		When I run `wp post create --post_author={AUTHOR1_ID} --post_title="Post two" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_2}
		And I run `wp co-authors-plus swap-coauthors --from=author1 --to=author2 --no-dry-run`
		Then STDOUT should be:
		"""
		Swapping authorship from author1 to author2
		Found 1 posts to update.
		1: Post #{POST_ID_2} has been assigned "author2" as a co-author
		Success: All done!
		"""
		When I run `wp term list author --object_ids={POST_ID_2} --field=slug`
		Then STDOUT should be:
		"""
		cap-author2
		"""

	Scenario: Refuse to swap a co-author with themselves
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Post one" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_1}
		When I try `wp co-authors-plus swap-coauthors --from=author1 --to=author1`
		Then STDERR should be:
		"""
		Error: --from and --to must be different co-authors
		"""
		And STDOUT should be empty
		And the return code should be 1
		When I run `wp term list author --object_ids={POST_ID_1} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		"""

	Scenario: Resolve a --from whose case differs from the stored user_login
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp user create author2 author2@example.com --role=author --porcelain`
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Post one" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_1}
		When I run `wp co-authors-plus swap-coauthors --from=AUTHOR1 --to=author2`
		Then STDOUT should be:
		"""
		Swapping authorship from author1 to author2
		Found 1 posts to update.
		1: Post #{POST_ID_1} has been assigned "author2" as a co-author
		Success: All done!
		"""
		When I run `wp term list author --object_ids={POST_ID_1} --field=slug`
		Then STDOUT should be:
		"""
		cap-author2
		"""

	Scenario: Swap only within the given --post_type
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp user create author2 author2@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR2_ID}
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="A post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post create --post_type=page --post_author={AUTHOR1_ID} --post_title="A page" --post_status=publish --porcelain`
		And save STDOUT as {PAGE_ID}
		And I run `wp term list author --object_ids={PAGE_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		"""
		When I run `wp co-authors-plus swap-coauthors --from=author1 --to=author2`
		Then STDOUT should be:
		"""
		Swapping authorship from author1 to author2
		Found 1 posts to update.
		1: Post #{POST_ID} has been assigned "author2" as a co-author
		Success: All done!
		"""
		When I run `wp term list author --object_ids={PAGE_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		"""
		When I run `wp co-authors-plus swap-coauthors --from=author1 --to=author2 --post_type=page`
		Then STDOUT should be:
		"""
		Swapping authorship from author1 to author2
		Found 1 posts to update.
		1: Post #{PAGE_ID} has been assigned "author2" as a co-author
		Success: All done!
		"""
		When I run `wp term list author --object_ids={PAGE_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author2
		"""
		When I run `wp post get {PAGE_ID} --field=post_author`
		Then STDOUT should be:
		"""
		{AUTHOR2_ID}
		"""

	Scenario: Ignore posts the swap query cannot reach
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp user create author2 author2@example.com --role=author --porcelain`
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Draft post" --post_status=draft --porcelain`
		And save STDOUT as {DRAFT_ID}
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Termless post" --post_status=publish --porcelain`
		And save STDOUT as {TERMLESS_ID}
		And I run `wp post term remove {TERMLESS_ID} author cap-author1 --by=slug`
		And I run `wp term list author --object_ids={DRAFT_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		"""
		When I run `wp term list author --object_ids={TERMLESS_ID} --format=count`
		Then STDOUT should be:
		"""
		0
		"""
		When I run `wp co-authors-plus swap-coauthors --from=author1 --to=author2`
		Then STDOUT should be:
		"""
		Swapping authorship from author1 to author2
		Found 0 posts to update.
		Success: All done!
		"""
		When I run `wp term list author --object_ids={DRAFT_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		"""
		When I run `wp post get {DRAFT_ID} --field=post_author`
		Then STDOUT should be:
		"""
		{AUTHOR1_ID}
		"""
		When I run `wp term list author --object_ids={TERMLESS_ID} --format=count`
		Then STDOUT should be:
		"""
		0
		"""
		When I run `wp post get {TERMLESS_ID} --field=post_author`
		Then STDOUT should be:
		"""
		{AUTHOR1_ID}
		"""

	Scenario: Swap to a guest author with no linked account
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe --user_email=jane@example.com --first_name=Jane --last_name=Doe --website=https://example.com/jane --description="Jane writes about testing"`
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Post one" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp co-authors-plus swap-coauthors --from=author1 --to=jane-doe`
		Then STDOUT should be:
		"""
		Swapping authorship from author1 to jane-doe
		Found 1 posts to update.
		1: Post #{POST_ID} has been assigned "jane-doe" as a co-author
		Success: All done!
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-jane-doe
		"""
		When I run `wp post get {POST_ID} --field=post_author`
		Then STDOUT should be:
		"""
		{AUTHOR1_ID}
		"""
