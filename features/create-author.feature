Feature: A single guest author can be created

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		# Author terms survive the Behat reset, so wipe them: a leftover `cap-*` term
		# would otherwise satisfy the term assertions below without the command
		# creating anything, and would be silently reused by CoAuthors_Plus::get_author_term().
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`

	Scenario: Create a guest author with every supported field
		When I run `wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe --user_email=jane@example.com --first_name=Jane --last_name=Doe --website=https://example.com/jane --description="Jane writes about testing"`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		-- Not found; creating profile.
		"""
		And STDOUT should contain:
		"""
		Undefined array key "avatar"
		"""
		And STDOUT should contain:
		"""
		Success: -- Created as guest author #
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		1
		"""
		When I run `wp post list --post_type=guest-author --field=post_name`
		Then STDOUT should be:
		"""
		cap-jane-doe
		"""
		When I run `wp post list --post_type=guest-author --format=ids`
		And save STDOUT as {GUEST_AUTHOR_ID}
		And I run `wp post meta list {GUEST_AUTHOR_ID} --format=csv`
		Then STDOUT should be:
		"""
		post_id,meta_key,meta_value
		{GUEST_AUTHOR_ID},cap-display_name,"Jane Doe"
		{GUEST_AUTHOR_ID},cap-first_name,Jane
		{GUEST_AUTHOR_ID},cap-last_name,Doe
		{GUEST_AUTHOR_ID},cap-user_login,jane-doe
		{GUEST_AUTHOR_ID},cap-user_email,jane@example.com
		{GUEST_AUTHOR_ID},cap-website,https://example.com/jane
		{GUEST_AUTHOR_ID},cap-description,"Jane writes about testing"
		{GUEST_AUTHOR_ID},_original_author_login,jane-doe
		"""
		# Stated explicitly as well as implied by the block above: this command never
		# writes `_original_author_id` (the guard reads an `author_id` key it never sets).
		When I run `wp post meta list {GUEST_AUTHOR_ID} --keys=_original_author_id --format=count`
		Then STDOUT should be:
		"""
		0
		"""
		# Scoped to the profile just created, not to the slug: a term-slug lookup would
		# pass on residue from an earlier feature or an earlier run.
		When I run `wp term list author --object_ids={GUEST_AUTHOR_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-jane-doe
		"""

	Scenario: Omitted optional fields raise undefined array key warnings but still create the profile
		When I run `wp co-authors-plus create-author --display_name=Minimal --user_login=minimal`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		-- Not found; creating profile.
		"""
		And STDOUT should contain:
		"""
		Undefined array key "user_email"
		"""
		And STDOUT should contain:
		"""
		Undefined array key "first_name"
		"""
		And STDOUT should contain:
		"""
		Undefined array key "last_name"
		"""
		And STDOUT should contain:
		"""
		Undefined array key "website"
		"""
		And STDOUT should contain:
		"""
		Undefined array key "description"
		"""
		And STDOUT should contain:
		"""
		Undefined array key "avatar"
		"""
		And STDOUT should contain:
		"""
		Success: -- Created as guest author #
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		1
		"""
		When I run `wp post list --post_type=guest-author --format=ids`
		And save STDOUT as {GUEST_AUTHOR_ID}
		And I run `wp post meta get {GUEST_AUTHOR_ID} cap-user_login`
		Then STDOUT should be:
		"""
		minimal
		"""
		When I run `wp post meta list {GUEST_AUTHOR_ID} --keys=cap-user_email --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Field values are stored exactly as given, without sanitisation
		When I run `wp co-authors-plus create-author --display_name="<b>Raw</b> Name" --user_login="Raw User!" --user_email=raw@example.com --website=example.com/raw --description="<script>x</script>bio"`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		Success: -- Created as guest author #
		"""
		When I run `wp post list --post_type=guest-author --format=ids`
		And save STDOUT as {RAW_ID}
		And I run `wp post meta list {RAW_ID} --format=csv`
		Then STDOUT should be:
		"""
		post_id,meta_key,meta_value
		{RAW_ID},cap-display_name,"<b>Raw</b> Name"
		{RAW_ID},cap-user_login,"Raw User!"
		{RAW_ID},cap-user_email,raw@example.com
		{RAW_ID},cap-website,example.com/raw
		{RAW_ID},cap-description,<script>x</script>bio
		{RAW_ID},_original_author_login,"Raw User!"
		"""
		When I run `wp post get {RAW_ID} --field=post_title`
		Then STDOUT should be:
		"""
		<b>Raw</b> Name
		"""
		# Only the post_name (and hence the term slug) is sanitised, by
		# CoAuthors_Guest_Authors::create() itself. Contrast
		# create-guest-authors-from-csv, which sanitises every cell before storing it.
		When I run `wp post get {RAW_ID} --field=post_name`
		Then STDOUT should be:
		"""
		cap-raw-user
		"""
		When I run `wp term list author --object_ids={RAW_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-raw-user
		"""

	Scenario: A user_login that collides with an existing user reuses that user's author term
		Given I run `wp user create jane-doe jane-user@example.com --display_name="Jane Doe" --role=author --porcelain`
		And save STDOUT as {USER_ID}
		And I run `wp post create --post_title="Post by Jane" --post_author={USER_ID} --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp term list author --object_ids={POST_ID} --field=term_id`
		And save STDOUT as {USER_TERM_ID}
		And STDOUT should not be empty
		When I run `wp co-authors-plus create-author --display_name="Jane Guest" --user_login=jane-doe --user_email=jane-guest@example.com`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		Success: -- Created as guest author #
		"""
		# The duplicate guards only look at guest authors, and get_author_term() matches
		# on the `cap-<nicename>` slug alone, so the new profile ADOPTS the existing
		# user's term rather than getting one of its own.
		When I run `wp post list --post_type=guest-author --format=ids`
		And save STDOUT as {GUEST_AUTHOR_ID}
		And I run `wp term list author --object_ids={GUEST_AUTHOR_ID} --field=term_id`
		Then STDOUT should be:
		"""
		{USER_TERM_ID}
		"""
		# The shared term's description (what CAP searches on) is rewritten with the
		# guest author's values, so the real user's post now points at the guest author.
		When I run `wp term list author --object_ids={POST_ID} --field=description`
		Then STDOUT should contain:
		"""
		Jane Guest
		"""

	Scenario: The avatar field used when creating a profile is not exposed as a parameter
		When I try `wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe --avatar=5`
		Then the return code should be 1
		And STDERR should contain:
		"""
		unknown --avatar parameter
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: A guest author is not created without a display_name
		When I run `wp co-authors-plus create-author --user_login=no-display-name`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		-- Not found; creating profile.
		"""
		And STDOUT should contain:
		"""
		Undefined array key "display_name"
		"""
		And STDOUT should contain:
		"""
		Warning: -- Failed to create guest author: display_name is a required field
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: A guest author is not created without a user_login
		When I run `wp co-authors-plus create-author --display_name="No Login"`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		-- Not found; creating profile.
		"""
		And STDOUT should contain:
		"""
		Undefined array key "user_login"
		"""
		And STDOUT should contain:
		"""
		Warning: -- Failed to create guest author: user_login is a required field
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Running without any parameters still attempts creation and warns
		When I run `wp co-authors-plus create-author`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		-- Not found; creating profile.
		"""
		And STDOUT should contain:
		"""
		Warning: -- Failed to create guest author: display_name is a required field
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Creating the same author twice skips the existing profile
		Given I run `wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe --user_email=jane@example.com`
		And I run `wp post list --post_type=guest-author --format=ids`
		And save STDOUT as {GUEST_AUTHOR_ID}
		And STDOUT should not be empty
		When I run `wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe --user_email=jane@example.com`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Warning: -- Author already exists (ID #{GUEST_AUTHOR_ID}); skipping.
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		1
		"""

	Scenario: An existing user_email is matched even when the user_login differs
		Given I run `wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe --user_email=jane@example.com`
		And I run `wp post list --post_type=guest-author --format=ids`
		And save STDOUT as {GUEST_AUTHOR_ID}
		And STDOUT should not be empty
		When I run `wp co-authors-plus create-author --display_name="Janet Other" --user_login=janet-other --user_email=jane@example.com`
		Then STDOUT should be:
		"""
		Warning: -- Author already exists (ID #{GUEST_AUTHOR_ID}); skipping.
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		1
		"""

	Scenario: An existing user_login is matched even when the user_email differs
		Given I run `wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe --user_email=jane@example.com`
		And I run `wp post list --post_type=guest-author --format=ids`
		And save STDOUT as {GUEST_AUTHOR_ID}
		And STDOUT should not be empty
		When I run `wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe --user_email=different@example.com`
		Then STDOUT should be:
		"""
		Warning: -- Author already exists (ID #{GUEST_AUTHOR_ID}); skipping.
		"""
		When I run `wp post meta get {GUEST_AUTHOR_ID} cap-user_email`
		Then STDOUT should be:
		"""
		jane@example.com
		"""
