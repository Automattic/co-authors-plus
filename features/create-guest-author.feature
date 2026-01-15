Feature: Guest authors can be created

	Background:
		Given a WP installation with the Co-Authors Plus plugin

	Scenario: Create a guest author
		When I run `wp co-authors-plus create-guest-authors`
		Then STDOUT should be:
      """
      Attempting to create guest author profiles for 1 users.
      All done! Here are your results:
      - 1 guest author profiles were created
      - 0 users already had guest author profiles
      """

	Scenario: Try to create a guest authors a second time
		When I run `wp co-authors-plus create-guest-authors`
		Then I run the previous command again
		Then STDOUT should be:
      """
      Attempting to create guest author profiles for 1 users.
      All done! Here are your results:
      - 0 guest author profiles were created
      - 1 users already had guest author profiles
      """
