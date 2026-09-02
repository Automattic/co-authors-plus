Feature: Posts without author terms can be listed

	Background:
		Given a WP installation with the Co-Authors Plus plugin

	Scenario: No output when there are no posts
		When I run `wp co-authors-plus list-posts-without-terms`
		Then STDOUT should be empty
		And the return code should be 0
		And STDERR should be empty

	Scenario: No output when every post has an author term
		When I run `wp post create --post_title="Authored post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		"""
		When I run `wp co-authors-plus list-posts-without-terms`
		Then STDOUT should be empty
		And the return code should be 0
		And STDERR should be empty

	Scenario: A post without author terms is printed as a CSV line
		When I run `wp post create --post_title="Alpha post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp co-authors-plus list-posts-without-terms`
		Then STDOUT should contain:
		"""
		"{POST_ID}","Alpha post","
		"""
		And STDOUT should match #^"\d+","Alpha post","[^"]+","\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}"$#m
		When I run `wp term list author --object_ids={POST_ID} --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Every post without author terms is listed, in ascending post ID order
		When I run `wp post create --post_title="Alpha post" --post_status=publish --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post create --post_title="Beta post" --post_status=publish --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp co-authors-plus list-posts-without-terms`
		Then STDOUT should contain:
		"""
		"{POST_A}","Alpha post","
		"""
		And STDOUT should contain:
		"""
		"{POST_B}","Beta post","
		"""
		And STDOUT should match #"Alpha post".*\n.*"Beta post"#
		And STDOUT should not match #"Beta post".*\n.*"Alpha post"#
		And the return code should be 0

	Scenario: Pages are only listed with --post_type=page
		When I run `wp post create --post_type=page --post_title="About page" --post_status=publish --porcelain`
		And save STDOUT as {PAGE_ID}
		And I run `wp co-authors-plus list-posts-without-terms`
		Then STDOUT should be empty
		And the return code should be 0
		And STDERR should be empty
		When I run `wp co-authors-plus list-posts-without-terms --post_type=page`
		Then STDOUT should contain:
		"""
		"{PAGE_ID}","About page","
		"""

	Scenario: Post titles are passed through addslashes
		When I run `wp post create --post_title="No Man's Sky" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp co-authors-plus list-posts-without-terms`
		Then STDOUT should contain:
		"""
		"{POST_ID}","No Man\'s Sky","
		"""

	Scenario: Draft posts are not listed even though they have no author terms
		When I run `wp post create --post_title="Draft post" --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp term list author --object_ids={POST_ID} --format=count`
		Then STDOUT should be:
		"""
		0
		"""
		When I run `wp co-authors-plus list-posts-without-terms`
		Then STDOUT should be empty
		And the return code should be 0
		And STDERR should be empty
