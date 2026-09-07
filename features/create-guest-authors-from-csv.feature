Feature: Guest authors can be created from a CSV file

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		# Author terms survive the Behat reset, so wipe them: a leftover `cap-*` term
		# would otherwise satisfy the term assertions below without the command
		# creating anything, and would be silently reused by CoAuthors_Plus::get_author_term().
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`

	Scenario: Error on a missing required --file parameter
		When I try `wp co-authors-plus create-guest-authors-from-csv`
		Then the return code should be 1
		And STDERR should be:
		"""
		Error: Parameter errors:
		 missing --file parameter (Path to the CSV file.)
		"""

	Scenario: Error on a file that cannot be read
		When I try `wp co-authors-plus create-guest-authors-from-csv --file=no-such-file.csv`
		Then the return code should be 1
		And STDERR should be:
		"""
		Error: Please specify a valid CSV file with the --file arg.
		"""

	Scenario: Create guest authors from a CSV file
		When I run `wp co-authors-plus create-guest-authors-from-csv --file=features/fixtures/guest-authors.csv`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		Found 2 authors in CSV
		Processing author jane-doe (jane@example.com)
		Success: -- Created as guest author #
		"""
		And STDOUT should contain:
		"""
		Processing author bob-builder (bob@example.com)
		Success: -- Created as guest author #
		"""
		And STDOUT should contain:
		"""
		All done!
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		2
		"""
		When I run `wp post list --post_type=guest-author --field=post_name --order=asc --orderby=ID`
		Then STDOUT should be:
		"""
		cap-jane-doe
		cap-bob-builder
		"""
		# Scoped to the profile just created, not to the slug: a term-slug lookup would
		# pass on residue from an earlier feature or an earlier run.
		When I run `wp post list --post_type=guest-author --meta_key=cap-user_login --meta_value=jane-doe --format=ids`
		And save STDOUT as {JANE_ID}
		And STDOUT should not be empty
		And I run `wp term list author --object_ids={JANE_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-jane-doe
		"""

	Scenario: First and last names are split from the display name when both name columns are empty
		Given I run `wp co-authors-plus create-guest-authors-from-csv --file=features/fixtures/guest-authors.csv`
		When I run `wp post list --post_type=guest-author --meta_key=cap-user_login --meta_value=jane-doe --format=ids`
		And save STDOUT as {JANE_ID}
		And I run `wp post meta list {JANE_ID} --format=csv`
		Then STDOUT should be:
		"""
		post_id,meta_key,meta_value
		{JANE_ID},cap-display_name,"Jane Doe"
		{JANE_ID},cap-first_name,Jane
		{JANE_ID},cap-last_name,Doe
		{JANE_ID},cap-user_login,jane-doe
		{JANE_ID},cap-user_email,jane@example.com
		{JANE_ID},cap-website,https://example.com/jane
		{JANE_ID},cap-description,"Jane writes about testing"
		{JANE_ID},_original_author_login,jane-doe
		"""
		# Stated explicitly as well as implied by the block above: this command never
		# writes `_original_author_id` (the guard reads an `author_id` key it never sets).
		When I run `wp post meta list {JANE_ID} --keys=_original_author_id --format=count`
		Then STDOUT should be:
		"""
		0
		"""
		When I run `wp post list --post_type=guest-author --meta_key=cap-user_login --meta_value=bob-builder --format=ids`
		And save STDOUT as {BOB_ID}
		And I run `wp post meta get {BOB_ID} cap-first_name`
		Then STDOUT should be:
		"""
		Robert
		"""
		When I run `wp post meta get {BOB_ID} cap-last_name`
		Then STDOUT should be:
		"""
		Builder
		"""

	# A display_name with no space and no name columns still yields no name meta,
	# which is correct — there is nothing to split and nothing supplied.
	Scenario: A single-word display name yields no first or last name
		When I run `wp co-authors-plus create-guest-authors-from-csv --file=features/fixtures/guest-authors-single-name.csv`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		Found 1 author in CSV
		"""
		And STDOUT should contain:
		"""
		Success: -- Created as guest author #
		"""
		And STDOUT should not match /Undefined array key/
		When I run `wp post list --post_type=guest-author --meta_key=cap-user_login --meta_value=prince --format=ids`
		And save STDOUT as {PRINCE_ID}
		And I run `wp post meta list {PRINCE_ID} --format=csv`
		Then STDOUT should be:
		"""
		post_id,meta_key,meta_value
		{PRINCE_ID},cap-display_name,Prince
		{PRINCE_ID},cap-user_login,prince
		{PRINCE_ID},cap-user_email,prince@example.com
		{PRINCE_ID},cap-website,https://example.com/prince
		{PRINCE_ID},cap-description,"Single name artist"
		{PRINCE_ID},_original_author_login,prince
		"""
		# A row supplying exactly ONE of first_name/last_name used to match neither
		# branch, so the supplied "Halfy" was discarded. Whichever column is filled is
		# now kept, and the empty one simply writes no meta.
		When I run `wp co-authors-plus create-guest-authors-from-csv --file=features/fixtures/guest-authors-half-named.csv`
		Then the return code should be 0
		And STDOUT should not match /Undefined array key/
		When I run `wp post list --post_type=guest-author --meta_key=cap-user_login --meta_value=half-named --format=ids`
		And save STDOUT as {HALF_ID}
		And I run `wp post meta list {HALF_ID} --format=csv`
		Then STDOUT should be:
		"""
		post_id,meta_key,meta_value
		{HALF_ID},cap-display_name,"Half Named"
		{HALF_ID},cap-first_name,Halfy
		{HALF_ID},cap-user_login,half-named
		{HALF_ID},cap-user_email,half@example.com
		{HALF_ID},_original_author_login,half-named
		"""

	Scenario: Every cell is sanitised before the profile is written
		When I run `wp co-authors-plus create-guest-authors-from-csv --file=features/fixtures/guest-authors-dirty.csv`
		Then the return code should be 0
		# The log line echoes the raw cells, before any sanitisation.
		And STDOUT should contain:
		"""
		Processing author Dirty Login! (DIRTY@Example.com)
		"""
		And STDOUT should contain:
		"""
		Success: -- Created as guest author #
		"""
		When I run `wp post list --post_type=guest-author --format=ids`
		And save STDOUT as {DIRTY_ID}
		And I run `wp post meta list {DIRTY_ID} --format=csv`
		Then STDOUT should be:
		"""
		post_id,meta_key,meta_value
		{DIRTY_ID},cap-display_name,"Dirty Name"
		{DIRTY_ID},cap-first_name,Dirty
		{DIRTY_ID},cap-last_name,Name
		{DIRTY_ID},cap-user_login,"Dirty Login!"
		{DIRTY_ID},cap-user_email,DIRTY@Example.com
		{DIRTY_ID},cap-website,http://example.com/x?a=1&b=2
		{DIRTY_ID},cap-description,alert(1)<em>Bio</em>
		{DIRTY_ID},_original_author_login,"Dirty Login!"
		"""
		When I run `wp post get {DIRTY_ID} --field=post_name`
		Then STDOUT should be:
		"""
		cap-dirty-login
		"""

	Scenario: A CSV header that omits columns warns for every missing key but still imports
		# The fixture's header also ends in a nameless column whose row cell holds a
		# value; the exact meta block below shows that cell is dropped rather than
		# stored under an empty key.
		When I run `wp co-authors-plus create-guest-authors-from-csv --file=features/fixtures/guest-authors-missing-columns.csv`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		Found 1 author in CSV
		"""
		And STDOUT should contain:
		"""
		Undefined array key "user_email"
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
		Processing author casey-missing ()
		"""
		And STDOUT should contain:
		"""
		Success: -- Created as guest author #
		"""
		When I run `wp post list --post_type=guest-author --format=ids`
		And save STDOUT as {CASEY_ID}
		And I run `wp post meta list {CASEY_ID} --format=csv`
		Then STDOUT should be:
		"""
		post_id,meta_key,meta_value
		{CASEY_ID},cap-display_name,"Casey Missing"
		{CASEY_ID},cap-first_name,Casey
		{CASEY_ID},cap-last_name,Missing
		{CASEY_ID},cap-user_login,casey-missing
		{CASEY_ID},_original_author_login,casey-missing
		"""

	Scenario: A row that fails validation is warned about and the import carries on
		When I run `wp co-authors-plus create-guest-authors-from-csv --file=features/fixtures/guest-authors-invalid-row.csv`
		# The bulk importers deliberately exit 0 when some rows fail: the drop is
		# reported through the tally warning below, not as an error.
		Then the return code should be 0
		And STDOUT should contain:
		"""
		Found 2 authors in CSV
		"""
		And STDOUT should contain:
		"""
		Processing author no-display-name (nodisplay@example.com)
		"""
		And STDOUT should contain:
		"""
		Warning: -- Failed to create guest author: display_name is a required field
		"""
		And STDOUT should contain:
		"""
		Processing author valid-person (valid@example.com)
		"""
		# "1 of 2" also pins the sprintf argument order: failed count first, total second.
		And STDOUT should contain:
		"""
		Warning: 1 of 2 authors could not be created.
		"""
		And STDOUT should contain:
		"""
		All done!
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		1
		"""
		When I run `wp post list --post_type=guest-author --field=post_name`
		Then STDOUT should be:
		"""
		cap-valid-person
		"""
		# A one-row file whose only row fails, so the tally's singular branch runs.
		When I run `wp co-authors-plus create-guest-authors-from-csv --file=features/fixtures/guest-authors-invalid-single.csv`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		Found 1 author in CSV
		"""
		And STDOUT should contain:
		"""
		Warning: 1 of 1 author could not be created.
		"""

	Scenario: A CSV file with only a header row creates nothing
		When I run `wp co-authors-plus create-guest-authors-from-csv --file=features/fixtures/guest-authors-header-only.csv`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Found 0 authors in CSV
		All done!
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Importing the same CSV file twice skips the existing guest authors
		Given I run `wp co-authors-plus create-guest-authors-from-csv --file=features/fixtures/guest-authors.csv`
		And I run `wp post list --post_type=guest-author --meta_key=cap-user_login --meta_value=jane-doe --format=ids`
		And save STDOUT as {JANE_ID}
		And STDOUT should not be empty
		And I run `wp post list --post_type=guest-author --meta_key=cap-user_login --meta_value=bob-builder --format=ids`
		And save STDOUT as {BOB_ID}
		And STDOUT should not be empty
		When I run `wp co-authors-plus create-guest-authors-from-csv --file=features/fixtures/guest-authors.csv`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Found 2 authors in CSV
		Processing author jane-doe (jane@example.com)
		Warning: -- Author already exists (ID #{JANE_ID}, user_login jane-doe); skipping.
		Processing author bob-builder (bob@example.com)
		Warning: -- Author already exists (ID #{BOB_ID}, user_login bob-builder); skipping.
		All done!
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		2
		"""
