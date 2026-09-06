Feature: Guest authors can be created

	Background:
		Given a WP installation with the Co-Authors Plus plugin

	Scenario: Create a guest author
		When I run `wp co-authors-plus create-guest-authors`
		Then STDOUT should be:
      """
      Attempting to create a guest author profile for 1 user.
      All done! Here are your results:
      - 1 guest author profile was created
      - 0 users already had guest author profiles
      """

	Scenario: Try to create a guest authors a second time
		When I run `wp co-authors-plus create-guest-authors`
		Then I run the previous command again
		Then STDOUT should be:
      """
      Attempting to create a guest author profile for 1 user.
      All done! Here are your results:
      - 0 guest author profiles were created
      - 1 user already had a guest author profile
      """

	Scenario: Process a chunk of users with --offset and --number
		Given I run `wp user create alice alice@example.com --role=author --porcelain`
		And I run `wp user create bob bob@example.com --role=author --porcelain`
		And I run `wp user create carol carol@example.com --role=author --porcelain`
		When I run `wp co-authors-plus create-guest-authors --offset=1 --number=2`
		Then STDOUT should be:
      """
      Attempting to create guest author profiles for 2 users.
      All done! Here are your results:
      - 2 guest author profiles were created
      - 0 users already had guest author profiles
      """

	Scenario: Resume an interrupted import by re-running the command
		Given I run `wp user create alice alice@example.com --role=author --porcelain`
		And I run `wp user create bob bob@example.com --role=author --porcelain`
		And I run `wp co-authors-plus create-guest-authors --number=2`
		When I run `wp co-authors-plus create-guest-authors`
		Then STDOUT should be:
      """
      Attempting to create guest author profiles for 3 users.
      All done! Here are your results:
      - 1 guest author profile was created
      - 2 users already had guest author profiles
      """
