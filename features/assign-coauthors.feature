Feature: Co-authors can be assigned to posts from a post meta value

	Background:
		Given a WP installation with the Co-Authors Plus plugin

	Scenario: Report every outcome in one run and ignore posts the query cannot reach
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp post create --post_title="Fresh" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_1}
		And I run `wp post meta update {POST_ID_1} _original_import_author author1`
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Already" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_2}
		And I run `wp post meta update {POST_ID_2} _original_import_author author1`
		And I run `wp post create --post_title="Ghost" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_3}
		And I run `wp post meta update {POST_ID_3} _original_import_author ghostwriter`
		And I run `wp post create --post_title="No meta" --post_status=publish --porcelain`
		And save STDOUT as {NO_META_ID}
		And I run `wp post create --post_title="Draft import" --post_status=draft --porcelain`
		And save STDOUT as {DRAFT_ID}
		And I run `wp post meta update {DRAFT_ID} _original_import_author author1`
		And I run `wp co-authors-plus assign-coauthors`
		Then STDOUT should be:
		"""
		1: Post #{POST_ID_1} has been assigned "author1" as the author
		2: Post #{POST_ID_2} already has "author1" associated as a co-author
		3: Post #{POST_ID_3} does not have "ghostwriter" associated as a co-author but there is not a co-author profile
		All done! Here are your results:
		- 1 posts already had the co-author assigned
		- 1 posts reference co-authors that don't exist. These are:
		  ghostwriter
		- 1 posts now have the proper co-author
		"""
		When I run `wp term list author --object_ids={POST_ID_1} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		"""
		When I run `wp term list author --object_ids={NO_META_ID} --format=count`
		Then STDOUT should be:
		"""
		0
		"""
		When I run `wp term list author --object_ids={DRAFT_ID} --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Assign a co-author from the default meta key and re-run safely
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp post create --post_title="Imported post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post meta update {POST_ID} _original_import_author author1`
		And I run `wp co-authors-plus assign-coauthors`
		Then STDOUT should be:
		"""
		1: Post #{POST_ID} has been assigned "author1" as the author
		All done! Here are your results:
		- 1 posts now have the proper co-author
		"""
		When I run the previous command again
		Then STDOUT should be:
		"""
		1: Post #{POST_ID} already has "author1" associated as a co-author
		All done! Here are your results:
		- 1 posts already had the co-author assigned
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		"""
		When I run `wp post get {POST_ID} --field=post_author`
		Then STDOUT should be:
		"""
		{AUTHOR1_ID}
		"""

	Scenario: Assign a co-author from a custom --meta_key
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And I run `wp post create --post_title="Custom key post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post meta update {POST_ID} author author1`
		And I run `wp co-authors-plus assign-coauthors --meta_key=author`
		Then STDOUT should be:
		"""
		1: Post #{POST_ID} has been assigned "author1" as the author
		All done! Here are your results:
		- 1 posts now have the proper co-author
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		"""

	# get_coauthors() falls back to the post_author user when a post has no author
	# terms. Treating that as already associated left the post with no term at all,
	# invisible to every term-driven query — this plugin's own included.
	Scenario: Backfill the author term for a post reachable only through post_author
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Own post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		"""
		When I run `wp post term remove {POST_ID} author cap-author1 --by=slug`
		And I run `wp post meta update {POST_ID} _original_import_author author1`
		And I run `wp co-authors-plus assign-coauthors`
		Then STDOUT should be:
		"""
		1: Post #{POST_ID} has been assigned "author1" as the author
		All done! Here are your results:
		- 1 posts now have the proper co-author
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		"""

	Scenario: Report missing co-author profiles once each and skip empty meta values
		When I run `wp post create --post_title="Ghost post one" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_1}
		And I run `wp post meta update {POST_ID_1} _original_import_author ghostwriter`
		And I run `wp post create --post_title="Ghost post two" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_2}
		And I run `wp post meta update {POST_ID_2} _original_import_author ghostwriter`
		And I run `wp post create --post_title="Phantom post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_3}
		And I run `wp post meta update {POST_ID_3} _original_import_author phantom`
		And I run `wp post create --post_title="Blank import" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID_4}
		And I run `wp eval 'update_post_meta( {POST_ID_4}, "_original_import_author", "" );'`
		And I run `wp co-authors-plus assign-coauthors`
		Then STDOUT should be:
		"""
		1: Post #{POST_ID_1} does not have "ghostwriter" associated as a co-author but there is not a co-author profile
		2: Post #{POST_ID_2} does not have "ghostwriter" associated as a co-author but there is not a co-author profile
		3: Post #{POST_ID_3} does not have "phantom" associated as a co-author but there is not a co-author profile
		4: Post #{POST_ID_4} has an empty _original_import_author value
		All done! Here are your results:
		- 3 posts reference co-authors that don't exist. These are:
		  ghostwriter, phantom
		- 1 post has an empty _original_import_author value
		"""
		When I run `wp term list author --object_ids={POST_ID_1},{POST_ID_2},{POST_ID_3},{POST_ID_4} --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Replace existing co-authors by default
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp user create author2 author2@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR2_ID}
		And I run `wp post create --post_title="Imported post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post meta update {POST_ID} _original_import_author author1`
		And I run `wp co-authors-plus assign-coauthors`
		And I run `wp post meta update {POST_ID} _original_import_author author2`
		And I run `wp co-authors-plus assign-coauthors`
		Then STDOUT should be:
		"""
		1: Post #{POST_ID} has been assigned "author2" as the author
		All done! Here are your results:
		- 1 posts now have the proper co-author
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author2
		"""
		When I run `wp post get {POST_ID} --field=post_author`
		Then STDOUT should be:
		"""
		{AUTHOR2_ID}
		"""

	Scenario: Append to existing co-authors with --append_coauthors
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp user create author2 author2@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR2_ID}
		And I run `wp post create --post_title="Imported post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post meta update {POST_ID} _original_import_author author1`
		And I run `wp co-authors-plus assign-coauthors`
		And I run `wp post meta update {POST_ID} _original_import_author author2`
		And I run `wp co-authors-plus assign-coauthors --append_coauthors`
		Then STDOUT should be:
		"""
		1: Post #{POST_ID} has been assigned "author2" as the author
		All done! Here are your results:
		- 1 posts now have the proper co-author
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		cap-author2
		"""
		When I run `wp post get {POST_ID} --field=post_author`
		Then STDOUT should be:
		"""
		{AUTHOR1_ID}
		"""
		When I run `wp user create author3 author3@example.com --role=author --porcelain`
		And I run `wp post meta update {POST_ID} _original_import_author author3`
		And I run `wp co-authors-plus assign-coauthors --append_coauthors=false`
		Then STDOUT should be:
		"""
		1: Post #{POST_ID} has been assigned "author3" as the author
		All done! Here are your results:
		- 1 posts now have the proper co-author
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		cap-author2
		cap-author3
		"""

	Scenario: Process only the given --post_type
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And I run `wp post create --post_type=page --post_title="A page" --post_status=publish --porcelain`
		And save STDOUT as {PAGE_ID}
		And I run `wp post meta update {PAGE_ID} _original_import_author author1`
		And I run `wp co-authors-plus assign-coauthors`
		Then STDOUT should be:
		"""
		No posts found with the "_original_import_author" meta key.
		"""
		When I run `wp co-authors-plus assign-coauthors --post_type=page`
		Then STDOUT should be:
		"""
		1: Post #{PAGE_ID} has been assigned "author1" as the author
		All done! Here are your results:
		- 1 posts now have the proper co-author
		"""
		When I run `wp term list author --object_ids={PAGE_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		"""

	Scenario: Fall back to a sanitised meta value and re-run safely
		When I run `wp user create author-one author-one@example.com --role=author --porcelain`
		And I run `wp post create --post_title="Display name import" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post meta update {POST_ID} _original_import_author "Author One"`
		And I run `wp co-authors-plus assign-coauthors`
		Then STDOUT should be:
		"""
		1: Post #{POST_ID} has been assigned "author-one" as the author
		All done! Here are your results:
		- 1 posts now have the proper co-author
		"""
		# The raw meta value is "Author One"; the resolved login is "author-one". The
		# comparison used to be against the raw value, so it never matched what had just
		# been assigned and every run re-wrote the byline afresh.
		When I run the previous command again
		Then STDOUT should be:
		"""
		1: Post #{POST_ID} already has "author-one" associated as a co-author
		All done! Here are your results:
		- 1 posts already had the co-author assigned
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author-one
		"""

	# The byline is written, but post_author still names the previous user, because
	# a guest author with no linked account cannot be put in that column. The
	# summary has to say so: anything reading post_author directly, such as the
	# admin author column, still shows the old user.
	Scenario: Assign an unlinked guest author from the meta value
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe --user_email=jane@example.com --first_name=Jane --last_name=Doe --website=https://example.com/jane --description="Jane writes about testing"`
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Imported post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post meta update {POST_ID} _original_import_author jane-doe`
		And I run `wp co-authors-plus assign-coauthors`
		Then STDOUT should be:
		"""
		1: Post #{POST_ID} has been assigned "jane-doe" as the author
		All done! Here are your results:
		- 1 posts now have the proper co-author
		- 1 post kept its original post_author, because no co-author assigned to it has a WordPress account
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

	# Two posts, so the plural form of the summary line is exercised as well as the
	# singular above. Without this the _n() plural branch is never run.
	Scenario: Several posts keeping their original post_author are counted together
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe --user_email=jane@example.com`
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="First import" --post_status=publish --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post meta update {POST_A} _original_import_author jane-doe`
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="Second import" --post_status=publish --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp post meta update {POST_B} _original_import_author jane-doe`
		And I run `wp co-authors-plus assign-coauthors`
		Then STDOUT should be:
		"""
		1: Post #{POST_A} has been assigned "jane-doe" as the author
		2: Post #{POST_B} has been assigned "jane-doe" as the author
		All done! Here are your results:
		- 2 posts now have the proper co-author
		- 2 posts kept their original post_author, because no co-author assigned to them has a WordPress account
		"""
		And the return code should be 0

	# Drafts carrying the meta key are outside the default scope, because this command
	# rewrites a byline per post. --post-statuses is the opt-in, as on
	# create-terms-for-posts.
	Scenario: Cover drafts only when asked with --post-statuses
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And I run `wp post create --post_title="Draft import" --post_status=draft --porcelain`
		And save STDOUT as {DRAFT_ID}
		And I run `wp post meta update {DRAFT_ID} _original_import_author author1`
		And I run `wp post create --post_title="Pending import" --post_status=pending --porcelain`
		And save STDOUT as {PENDING_ID}
		And I run `wp post meta update {PENDING_ID} _original_import_author author1`
		And I run `wp co-authors-plus assign-coauthors`
		Then STDOUT should be:
		"""
		No posts found with the "_original_import_author" meta key.
		"""
		# Comma-separated, so the explode() is exercised rather than just a single value.
		When I run `wp co-authors-plus assign-coauthors --post-statuses=draft,pending`
		Then STDOUT should be:
		"""
		1: Post #{DRAFT_ID} has been assigned "author1" as the author
		2: Post #{PENDING_ID} has been assigned "author1" as the author
		All done! Here are your results:
		- 2 posts now have the proper co-author
		"""
		When I run `wp term list author --object_ids={DRAFT_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-author1
		"""

	# Two empty values, so the plural form of the summary line is exercised as well as
	# the singular above. Without this the _n() plural branch never runs.
	Scenario: Several empty meta values are counted together
		When I run `wp post create --post_title="First" --post_status=publish --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp eval 'update_post_meta( {POST_A}, "_original_import_author", "" );'`
		And I run `wp post create --post_title="Second" --post_status=publish --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp eval 'update_post_meta( {POST_B}, "_original_import_author", "" );'`
		And I run `wp co-authors-plus assign-coauthors`
		Then STDOUT should be:
		"""
		1: Post #{POST_A} has an empty _original_import_author value
		2: Post #{POST_B} has an empty _original_import_author value
		All done! Here are your results:
		- 2 posts have an empty _original_import_author value
		"""
		And the return code should be 0
