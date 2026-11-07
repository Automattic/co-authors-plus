Feature: Guest authors with no usable author term can be reported

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`

	Scenario: A site with only healthy guest authors has nothing to report
		When I run `wp co-authors-plus create-author --display_name="Healthy Guest" --user_login=healthy-guest`
		And I run `wp co-authors-plus list-unclaimed-guest-terms`
		Then STDOUT should be:
		"""
		No guest authors to report.
		"""
		When I run `wp co-authors-plus list-unclaimed-guest-terms --format=count`
		Then STDOUT should be:
		"""
		0
		"""
		When I run `wp co-authors-plus list-unclaimed-guest-terms --format=json`
		Then STDOUT should be:
		"""
		[]
		"""

	Scenario: An affected guest author is reported in every format
		When I run `wp eval-file features/fixtures/guest-with-prefixed-login.php jane-doe "jane doe"`
		And save STDOUT as {GUEST_ID}
		And I run `wp post meta update {GUEST_ID} cap-display_name "Jane & Doe, Reporters"`

		When I run `wp co-authors-plus list-unclaimed-guest-terms`
		Then STDOUT should match /^\d+\s+cap-jane-doe\s+no-term\s+cap-jane-doe\s+0\s+0$/m
		And STDOUT should contain:
		"""
		This is a report. Nothing was changed, and a row does not mean a login is wrong.
		"""

		When I run `wp co-authors-plus list-unclaimed-guest-terms --format=csv`
		Then STDOUT should be:
		"""
		ID,user_login,status,linked_account,resolved_term,matching_term,matching_term_owner,matching_term_count,doubled_term,doubled_term_count
		{GUEST_ID},cap-jane-doe,no-term,,,cap-jane-doe,,0,,0
		"""

		When I run `wp co-authors-plus list-unclaimed-guest-terms --format=csv --fields=ID,display_name,matching_term_name`
		Then STDOUT should be:
		"""
		ID,display_name,matching_term_name
		{GUEST_ID},"Jane & Doe, Reporters","jane doe"
		"""

		When I run `wp co-authors-plus list-unclaimed-guest-terms --format=json --fields=ID,user_login,display_name,status`
		Then STDOUT should be JSON containing:
		"""
		[{"ID":{GUEST_ID},"user_login":"cap-jane-doe","display_name":"Jane & Doe, Reporters","status":"no-term"}]
		"""

		When I run `wp co-authors-plus list-unclaimed-guest-terms --format=yaml --fields=ID,user_login,status`
		Then STDOUT should be:
		"""
		---
		-
		  ID: {GUEST_ID}
		  user_login: cap-jane-doe
		  status: no-term
		"""

		When I run `wp co-authors-plus list-unclaimed-guest-terms --format=count`
		Then STDOUT should be:
		"""
		1
		"""

		When I run `wp co-authors-plus list-unclaimed-guest-terms --field=user_login`
		Then STDOUT should be:
		"""
		cap-jane-doe
		"""

	Scenario: A linked guest author shows the link instead of looking healthy
		When I run `wp user create linked-user linked@example.com --role=author --porcelain`
		And I run `wp eval-file features/fixtures/guest-with-prefixed-login.php link-me "link me"`
		And save STDOUT as {GUEST_ID}
		And I run `wp post meta update {GUEST_ID} cap-linked_account linked-user`
		And I run `wp co-authors-plus list-unclaimed-guest-terms --format=csv --fields=ID,status,linked_account,resolved_term`
		Then STDOUT should be:
		"""
		ID,status,linked_account,resolved_term
		{GUEST_ID},no-term,linked-user,
		"""

	Scenario: A healthy linked guest author isn't reported
		When I run `wp user create linked-user linked@example.com --role=author --porcelain`
		And I run `wp co-authors-plus create-guest-authors`
		And I run `wp co-authors-plus list-unclaimed-guest-terms --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: A guest author with a doubled term is reported with the posts on both terms
		When I run `wp eval-file features/fixtures/guest-with-prefixed-login.php jane-doe "jane doe"`
		And save STDOUT as {GUEST_ID}
		And I run `wp post create --post_title="An old post" --post_status=publish --porcelain`
		And save STDOUT as {OLD_POST}
		And I run `wp eval 'wp_set_object_terms( {OLD_POST}, array( "cap-jane-doe" ), "author" );'`
		And I run `wp eval 'global $coauthors_plus; $coauthors_plus->update_author_term( $coauthors_plus->guest_authors->get_guest_author_by( "ID", {GUEST_ID} ) );'`
		And I run `wp post create --post_title="A new post" --post_status=publish --porcelain`
		And save STDOUT as {NEW_POST}
		And I run `wp eval 'wp_set_object_terms( {NEW_POST}, array( "cap-cap-jane-doe" ), "author" );'`
		And I run `wp co-authors-plus list-unclaimed-guest-terms --format=csv --fields=ID,status,resolved_term,matching_term,matching_term_count,doubled_term,doubled_term_count`
		Then STDOUT should be:
		"""
		ID,status,resolved_term,matching_term,matching_term_count,doubled_term,doubled_term_count
		{GUEST_ID},split,cap-cap-jane-doe,cap-jane-doe,1,cap-cap-jane-doe,1
		"""

	Scenario: A term that belongs to a user is named as theirs
		When I run `wp eval-file features/fixtures/guest-with-prefixed-login.php jane-doe "jane doe"`
		And save STDOUT as {GUEST_ID}
		And I run `wp user create jane-doe jane@example.com --role=author --porcelain`
		And I run `wp co-authors-plus list-unclaimed-guest-terms --format=csv --fields=ID,status,matching_term_owner`
		Then STDOUT should be:
		"""
		ID,status,matching_term_owner
		{GUEST_ID},term-owned-by-other,user:jane-doe
		"""

	Scenario: A legitimate login starting with cap isn't reported
		When I run `wp co-authors-plus create-author --display_name="Cap Ri" --user_login="Cap Ri"`
		And I run `wp co-authors-plus list-unclaimed-guest-terms --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Every batch is walked
		When I run `wp eval-file features/fixtures/guest-with-prefixed-login.php first "first name"`
		And I run `wp co-authors-plus create-author --display_name="Healthy Guest" --user_login=healthy-guest`
		And I run `wp eval-file features/fixtures/guest-with-prefixed-login.php second "second name"`
		And I run `wp co-authors-plus list-unclaimed-guest-terms --records-per-batch=1 --field=user_login`
		Then STDOUT should be:
		"""
		cap-first
		cap-second
		"""

	Scenario: A batch size that isn't a positive integer is refused
		When I try `wp co-authors-plus list-unclaimed-guest-terms --records-per-batch=0`
		Then STDERR should be:
		"""
		Error: --records-per-batch must be a positive integer.
		"""
		And the return code should be 1

	Scenario: The report changes nothing
		When I run `wp eval-file features/fixtures/guest-with-prefixed-login.php no-term "no term"`
		And I run `wp eval-file features/fixtures/guest-with-prefixed-login.php owned "owned name"`
		And I run `wp user create owned owned@example.com --role=author --porcelain`
		And I run `wp eval-file features/fixtures/guest-with-prefixed-login.php split "split name"`
		And save STDOUT as {SPLIT_ID}
		And I run `wp post create --post_title="A post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp eval 'wp_set_object_terms( {POST_ID}, array( "cap-split" ), "author" );'`
		And I run `wp eval 'global $coauthors_plus; $coauthors_plus->update_author_term( $coauthors_plus->guest_authors->get_guest_author_by( "ID", {SPLIT_ID} ) );'`
		And I run `wp eval-file features/fixtures/database-checksum.php`
		And save STDOUT as {BEFORE}

		When I run `wp co-authors-plus list-unclaimed-guest-terms --format=count`
		Then STDOUT should be:
		"""
		3
		"""

		When I run `wp co-authors-plus list-unclaimed-guest-terms --records-per-batch=1`
		And I run `wp eval-file features/fixtures/database-checksum.php`
		Then STDOUT should be:
		"""
		{BEFORE}
		"""
