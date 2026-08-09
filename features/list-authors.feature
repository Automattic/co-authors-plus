Feature: Co-authors of a post can be listed

	Background:
		Given a WP installation with the Co-Authors Plus plugin

	Scenario: Error on an invalid post id
		When I try `wp co-authors-plus list-authors 0`
		Then STDERR should be:
      """
      Error: Please specify a valid post_id.
      """

	Scenario: No co-authors are found for a post
		When I run `wp post create --post_author=0 --post_status=publish --post_title="A post" --porcelain`
		And save STDOUT as {POST_ID}
		When I run `wp co-authors-plus list-authors {POST_ID}`
		Then STDOUT should contain:
      """
      No co-authors found for post #{POST_ID}
      """

	Scenario: List the single WP user author of a post
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And save STDOUT as {AUTHOR1_ID}
		When I run `wp post create --post_author={AUTHOR1_ID} --post_status=publish --post_title="A post" --porcelain`
		And save STDOUT as {POST_ID}
		When I run `wp co-authors-plus list-authors {POST_ID}`
		Then STDOUT should contain:
      """
      author1
      """

	Scenario: List multiple co-authors assigned to a post
		When I run `wp co-authors-plus create-guest-authors`
		When I run `wp user create author1 author1@example.com --role=author --porcelain`
		And I run `wp co-authors-plus create-guest-authors`
		And I run `wp post create --post_status=publish --post_title="A post" --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post term add {POST_ID} author admin`
		And I run `wp post term add {POST_ID} author author1`
		When I run `wp co-authors-plus list-authors {POST_ID}`
		Then STDOUT should contain:
      """
      admin
      """
		And STDOUT should contain:
      """
      author1
      """
