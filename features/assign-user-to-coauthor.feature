Feature: Posts can be assigned from a user to a co-author

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		And I run `wp co-authors-plus create-guest-authors`

	Scenario: Error when neither --user_login nor --user_id is supplied
		When I try `wp co-authors-plus assign-user-to-coauthor --coauthor=admin`
		Then STDERR should be:
		"""
		Error: Please specify exactly one of --user_login or --user_id.
		"""

	Scenario: Error when both --user_login and --user_id are supplied
		When I try `wp co-authors-plus assign-user-to-coauthor --user_login=admin --user_id=1 --coauthor=admin`
		Then STDERR should be:
		"""
		Error: Please specify exactly one of --user_login or --user_id.
		"""

	Scenario: Error on a non-existent --user_login
		When I try `wp co-authors-plus assign-user-to-coauthor --user_login=not-a-user --coauthor=admin`
		Then STDERR should be:
		"""
		Error: Please specify a valid user_login.
		"""

	Scenario: Error on a non-positive --user_id
		When I try `wp co-authors-plus assign-user-to-coauthor --user_id=0 --coauthor=admin`
		Then STDERR should be:
		"""
		Error: Please specify a positive integer for user_id.
		"""

	Scenario: Error on a non-numeric --user_id
		When I try `wp co-authors-plus assign-user-to-coauthor --user_id=abc --coauthor=admin`
		Then STDERR should be:
		"""
		Error: Please specify a positive integer for user_id.
		"""

	Scenario: Error on a missing co-author
		When I try `wp co-authors-plus assign-user-to-coauthor --user_id=1 --coauthor=not-a-coauthor`
		Then STDERR should be:
		"""
		Error: Please specify a valid co-author login
		"""

	Scenario: Assign orphaned posts to a co-author by --user_id
		When I run `wp post create --post_author=999 --post_status=publish --post_title="Orphan post" --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp co-authors-plus assign-user-to-coauthor --user_id=999 --coauthor=admin`
		Then STDOUT should contain:
		"""
		Updating - Adding admin's byline to post #{POST_ID}
		"""
		And STDOUT should contain:
		"""
		Success: All done!
		"""

	Scenario: Succeed cleanly when the user has no posts
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And I run `wp co-authors-plus assign-user-to-coauthor --user_login=author1 --coauthor=admin`
		Then STDOUT should contain:
		"""
		Success: All done! 0 posts were affected.
		"""

	Scenario: Skip a post that already has co-authors when --append_coauthors is not set
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="A post" --post_status=publish --porcelain`
		And I run `wp co-authors-plus assign-user-to-coauthor --user_login=author1 --coauthor=admin`
		Then STDOUT should contain:
		"""
		Skipping - Post #
		"""
		And STDOUT should contain:
		"""
		Success: All done! 0 posts were affected.
		"""

	Scenario: Append a co-author to a post that already has co-authors when --append_coauthors is set
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		And I run `wp post create --post_author={AUTHOR1_ID} --post_title="A post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp co-authors-plus assign-user-to-coauthor --user_login=author1 --coauthor=admin --append_coauthors`
		Then STDOUT should contain:
		"""
		Updating - Adding admin's byline to post #{POST_ID}
		"""
		And STDOUT should contain:
		"""
		Success: All done! 1 post was affected.
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should contain:
		"""
		author1
		"""
		And STDOUT should contain:
		"""
		admin
		"""
