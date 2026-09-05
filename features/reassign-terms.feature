Feature: Author terms can be reassigned between co-authors

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`

	Scenario: Rename an old term when the new term does not exist yet
		When I run `wp user create olduser olduser@example.com --role=author`
		And I run `wp co-authors-plus create-guest-authors`
		And I run `wp co-authors-plus reassign-terms --old-term=olduser --new-term=newuser`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Success: Converted 'olduser' term to 'newuser'
		Reassignment complete. Here are your results:
		- 1 authors were successfully reassigned terms
		- 0 authors had their old term merged to their new term
		- 0 authors were missing old terms
		"""
		When I run `wp term list author --fields=name,slug --format=csv`
		Then STDOUT should be:
		"""
		name,slug
		admin,cap-admin
		newuser,cap-newuser
		"""
		When I run `wp post list --post_type=guest-author --orderby=ID --order=asc --field=post_name`
		Then STDOUT should be:
		"""
		cap-admin
		cap-olduser
		"""
		When I run `wp co-authors-plus reassign-terms --old-term=olduser --new-term=newuser`
		Then STDOUT should be:
		"""
		Error: Term 'olduser' doesn't exist, skipping
		Reassignment complete. Here are your results:
		- 0 authors were successfully reassigned terms
		- 0 authors had their old term merged to their new term
		- 1 authors were missing old terms
		"""
		When I run `wp term list author --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		cap-newuser
		"""

	Scenario: Merge into an existing term and reassign its posts
		When I run `wp user create olduser olduser@example.com --role=author`
		And I run `wp user create newuser newuser@example.com --role=author`
		And I run `wp co-authors-plus create-guest-authors`
		And I run `wp post create --post_title="Shared post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post term add {POST_ID} author cap-olduser`
		And I run `wp co-authors-plus reassign-terms --old-term=olduser --new-term=newuser`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Success: There's already a 'newuser' term for 'olduser'. Reassigning 1 posts and then deleting the term
		Reassignment complete. Here are your results:
		- 0 authors were successfully reassigned terms
		- 1 authors had their old term merged to their new term
		- 0 authors were missing old terms
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-newuser
		"""
		When I run `wp term list author --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		cap-newuser
		"""
		When I run `wp post list --post_type=guest-author --orderby=ID --order=asc --field=post_name`
		Then STDOUT should be:
		"""
		cap-admin
		cap-newuser
		cap-olduser
		"""

	Scenario: Reassigning a term to itself is skipped
		When I run `wp user create olduser olduser@example.com --role=author`
		And I run `wp co-authors-plus create-guest-authors`
		And I run `wp post create --post_title="Owned post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post term add {POST_ID} author cap-olduser`
		And I run `wp co-authors-plus reassign-terms --old-term=olduser --new-term=olduser`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Warning: Term 'olduser' is already 'olduser', skipping
		Reassignment complete. Here are your results:
		- 0 authors were successfully reassigned terms
		- 0 authors had their old term merged to their new term
		- 0 authors were missing old terms
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-olduser
		"""
		And the return code should be 0
		When I run `wp term list author --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		cap-olduser
		"""

	Scenario: A numeric --new-term is resolved to that user's login
		When I run `wp user create olduser olduser@example.com --role=author`
		And I run `wp co-authors-plus create-guest-authors`
		And I run `wp user create newuser newuser@example.com --role=author --porcelain`
		And save STDOUT as {NEWUSER_ID}
		And I run `wp co-authors-plus reassign-terms --old-term=olduser --new-term={NEWUSER_ID}`
		Then STDOUT should be:
		"""
		Success: Converted 'olduser' term to 'newuser'
		Reassignment complete. Here are your results:
		- 1 authors were successfully reassigned terms
		- 0 authors had their old term merged to their new term
		- 0 authors were missing old terms
		"""
		When I run `wp term list author --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		cap-newuser
		"""

	Scenario: A numeric --new-term for an unknown user id is skipped
		When I run `wp user create olduser olduser@example.com --role=author`
		And I run `wp co-authors-plus create-guest-authors`
		And I try `wp co-authors-plus reassign-terms --old-term=olduser --new-term=999999`
		Then the return code should be 0
		And STDERR should contain:
		"""
		Warning: No user has the ID 999999, skipping 'olduser'
		"""
		And STDOUT should contain:
		"""
		Reassignment complete. Here are your results:
		"""
		When I run `wp term list author --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		cap-olduser
		"""

	Scenario: A target slug already taken by another term is reported, not counted
		When I run `wp user create olduser olduser@example.com --role=author`
		And I run `wp co-authors-plus create-guest-authors`
		And I run `wp term create author newuser --slug=cap-newuser --porcelain`
		And save STDOUT as {EXISTING_ID}
		And I run `wp co-authors-plus reassign-terms --old-term=olduser --new-term=newuser`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		Warning: Could not convert 'olduser' term to 'newuser':
		"""
		And STDOUT should contain:
		"""
		Reassignment complete. Here are your results:
		- 0 authors were successfully reassigned terms
		- 0 authors had their old term merged to their new term
		- 0 authors were missing old terms
		"""
		When I run `wp term list author --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		cap-newuser
		cap-olduser
		"""

	Scenario: A missing old term is reported and skipped without an error exit
		When I run `wp co-authors-plus reassign-terms --old-term=ghost --new-term=whatever`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Error: Term 'ghost' doesn't exist, skipping
		Reassignment complete. Here are your results:
		- 0 authors were successfully reassigned terms
		- 0 authors had their old term merged to their new term
		- 1 authors were missing old terms
		"""
		When I run `wp term create author orphan --porcelain`
		And save STDOUT as {ORPHAN_ID}
		And I run `wp co-authors-plus reassign-terms --old-term=orphan --new-term=whatever`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Error: Term 'orphan' doesn't exist, skipping
		Reassignment complete. Here are your results:
		- 0 authors were successfully reassigned terms
		- 0 authors had their old term merged to their new term
		- 1 authors were missing old terms
		"""
		When I run `wp term list author --fields=term_id,slug --format=csv`
		Then STDOUT should be:
		"""
		term_id,slug
		{ORPHAN_ID},orphan
		"""

	Scenario: Require either a mapping file or both term arguments
		When I try `wp co-authors-plus reassign-terms`
		Then STDERR should be:
		"""
		Error: Please specify either --author-mapping, or both --old-term and --new-term.
		"""
		And STDOUT should be empty
		And the return code should be 1
		When I try `wp co-authors-plus reassign-terms --old-term=olduser`
		Then STDERR should be:
		"""
		Error: Please specify either --author-mapping, or both --old-term and --new-term.
		"""
		And the return code should be 1

	Scenario: Reassign terms from an --author-mapping file
		When I run `wp user create olduser olduser@example.com --role=author`
		And I run `wp co-authors-plus create-guest-authors`
		And I run `wp term list author --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		cap-olduser
		"""
		When I run `wp co-authors-plus reassign-terms --author-mapping=features/fixtures/reassign-author-mapping.php`
		Then STDOUT should be:
		"""
		Success: Converted 'olduser' term to 'newuser'
		Reassignment complete. Here are your results:
		- 1 authors were successfully reassigned terms
		- 0 authors had their old term merged to their new term
		- 0 authors were missing old terms
		"""
		And the return code should be 0
		When I run `wp term list author --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		cap-newuser
		"""

	Scenario: Report a missing --author-mapping file
		When I try `wp co-authors-plus reassign-terms --author-mapping=features/fixtures/no-such-file.php`
		Then STDERR should be:
		"""
		Error: --author-mapping file doesn't exist: features/fixtures/no-such-file.php
		"""
		And STDOUT should be empty
		And the return code should be 1

	Scenario: Accept the deprecated --old_term and --new_term flags with a notice
		When I run `wp user create olduser olduser@example.com --role=author`
		And I run `wp co-authors-plus create-guest-authors`
		And I run `wp co-authors-plus reassign-terms --old_term=olduser --new_term=newuser`
		Then STDOUT should be:
		"""
		Warning: The --old_term flag is deprecated; use --old-term instead.
		Warning: The --new_term flag is deprecated; use --new-term instead.
		Success: Converted 'olduser' term to 'newuser'
		Reassignment complete. Here are your results:
		- 1 authors were successfully reassigned terms
		- 0 authors had their old term merged to their new term
		- 0 authors were missing old terms
		"""
		And the return code should be 0
		When I run `wp term list author --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		cap-newuser
		"""

	Scenario: The underscore variant --author_mapping is rejected as an unknown parameter
		When I try `wp co-authors-plus reassign-terms --author_mapping=features/fixtures/reassign-author-mapping.php`
		Then the return code should be 1
		And STDERR should contain:
		"""
		unknown --author_mapping parameter
		"""
