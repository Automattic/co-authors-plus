Feature: Author terms can be removed from post revisions

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`
		And I run `wp co-authors-plus create-guest-authors`

	Scenario: Report when there are no revisions at all
		When I run `wp co-authors-plus remove-terms-from-revisions`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Found 0 revisions to look through
		All done! 0 revisions had author terms removed
		"""

	Scenario: Leave a revision without author terms untouched
		When I run `wp post create --post_title="Plain post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post update {POST_ID} --post_content="Updated content"`
		And I run `wp co-authors-plus remove-terms-from-revisions`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Found 1 revision to look through
		All done! 0 revisions had author terms removed
		"""

	Scenario: Remove author terms from a revision while the parent post keeps its terms
		When I run `wp post create --post_title="First post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post term add {POST_ID} author cap-admin`
		And I run `wp post update {POST_ID} --post_content="Updated content"`
		And I run `wp post list --post_type=revision --post_status=inherit --post_parent={POST_ID} --posts_per_page=1 --orderby=ID --order=DESC --field=ID`
		And save STDOUT as {REVISION_ID}
		And I run `wp eval 'wp_set_post_terms( {REVISION_ID}, array( "cap-admin" ), "author" );'`
		And I run `wp post list --post_type=revision --post_status=inherit --format=count`
		Then STDOUT should be:
		"""
		1
		"""
		When I run `wp co-authors-plus remove-terms-from-revisions`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Found 1 revision to look through
		#{REVISION_ID}: Removing cap-admin
		All done! 1 revision had author terms removed
		"""
		When I run `wp term list author --object_ids={REVISION_ID} --field=slug`
		Then STDOUT should be empty
		And the return code should be 0
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		"""
		When I run `wp post list --post_type=revision --post_status=inherit --post_parent={POST_ID} --field=ID`
		Then STDOUT should be:
		"""
		{REVISION_ID}
		"""

	Scenario: List every removed slug for a revision with multiple author terms
		When I run `wp term create author alice --slug=cap-alice`
		And I run `wp post create --post_title="Multi author post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post update {POST_ID} --post_content="Updated content"`
		And I run `wp post list --post_type=revision --post_status=inherit --post_parent={POST_ID} --posts_per_page=1 --orderby=ID --order=DESC --field=ID`
		And save STDOUT as {REVISION_ID}
		And I run `wp eval 'wp_set_post_terms( {REVISION_ID}, array( "cap-admin", "cap-alice" ), "author" );'`
		And I run `wp co-authors-plus remove-terms-from-revisions`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		Found 1 revision to look through
		"""
		And STDOUT should match /Removing (cap-admin,cap-alice|cap-alice,cap-admin)/
		And STDOUT should contain:
		"""
		All done! 1 revision had author terms removed
		"""
		When I run `wp term list author --object_ids={REVISION_ID} --field=slug`
		Then STDOUT should be empty
		And the return code should be 0
		When I run `wp term list author --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		cap-alice
		"""

	Scenario: Only revisions with author terms count as affected
		When I run `wp post create --post_title="Untouched post" --post_status=publish --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post update {POST_A} --post_content="Updated content A"`
		And I run `wp post create --post_title="Tagged post" --post_status=publish --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp post update {POST_B} --post_content="Updated content B"`
		And I run `wp post list --post_type=revision --post_status=inherit --post_parent={POST_B} --posts_per_page=1 --orderby=ID --order=DESC --field=ID`
		And save STDOUT as {REV_B}
		And I run `wp eval 'wp_set_post_terms( {REV_B}, array( "cap-admin" ), "author" );'`
		And I run `wp co-authors-plus remove-terms-from-revisions`
		Then STDOUT should be:
		"""
		Found 2 revisions to look through
		#{REV_B}: Removing cap-admin
		All done! 1 revision had author terms removed
		"""

	Scenario: Running the command twice reports nothing left to remove
		When I run `wp post create --post_title="First post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post update {POST_ID} --post_content="Updated content"`
		And I run `wp post list --post_type=revision --post_status=inherit --post_parent={POST_ID} --posts_per_page=1 --orderby=ID --order=DESC --field=ID`
		And save STDOUT as {REVISION_ID}
		And I run `wp eval 'wp_set_post_terms( {REVISION_ID}, array( "cap-admin" ), "author" );'`
		And I run `wp co-authors-plus remove-terms-from-revisions`
		And I run the previous command again
		Then STDOUT should be:
		"""
		Found 1 revision to look through
		All done! 0 revisions had author terms removed
		"""

	Scenario: Every revision with author terms is counted
		When I run `wp post create --post_title="Post A" --post_status=publish --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post update {POST_A} --post_content="Update A"`
		And I run `wp post list --post_type=revision --post_status=inherit --post_parent={POST_A} --posts_per_page=1 --orderby=ID --order=DESC --field=ID`
		And save STDOUT as {REV_A}
		And I run `wp post create --post_title="Post B" --post_status=publish --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp post update {POST_B} --post_content="Update B"`
		And I run `wp post list --post_type=revision --post_status=inherit --post_parent={POST_B} --posts_per_page=1 --orderby=ID --order=DESC --field=ID`
		And save STDOUT as {REV_B}
		And I run `wp eval 'wp_set_post_terms( {REV_A}, array( "cap-admin" ), "author" ); wp_set_post_terms( {REV_B}, array( "cap-admin" ), "author" );'`
		And I run `wp co-authors-plus remove-terms-from-revisions`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		Found 2 revisions to look through
		"""
		And STDOUT should contain:
		"""
		#{REV_A}: Removing cap-admin
		"""
		And STDOUT should contain:
		"""
		#{REV_B}: Removing cap-admin
		"""
		And STDOUT should contain:
		"""
		All done! 2 revisions had author terms removed
		"""
		When I run `wp term list author --object_ids={REV_A},{REV_B} --field=slug`
		Then STDOUT should be empty
		And the return code should be 0
