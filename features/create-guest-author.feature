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

	Scenario: A user whose profile cannot be created is reported as a failure
		# An unlinked guest author already holds the login jdoe's profile would get
		# from their display name, so create() refuses it with duplicate-field.
		Given I run `wp co-authors-plus create-author --display_name="Jane Guest" --user_login=jane-doe --user_email=jane-guest@example.com`
		And I run `wp user create jdoe jdoe@example.com --role=author --display_name="Jane Doe" --porcelain`
		# Only jdoe, so the tally's singular branch runs.
		When I run `wp co-authors-plus create-guest-authors --offset=1 --number=1`
		# Like the CSV and WXR importers, one failed user does not abort the run.
		Then the return code should be 0
		And STDOUT should contain:
      """
      Warning: Could not create a guest author profile for user jdoe: user_login cannot duplicate existing guest author or mapped user
      """
		And STDOUT should contain:
      """
      - 0 guest author profiles were created
      - 0 users already had guest author profiles
      """
		And STDOUT should contain:
      """
      Warning: 1 of 1 user could not be given a guest author profile.
      """
		When I run `wp co-authors-plus create-guest-authors`
		Then the return code should be 0
		And STDOUT should contain:
      """
      Warning: Could not create a guest author profile for user jdoe: user_login cannot duplicate existing guest author or mapped user
      """
		And STDOUT should contain:
      """
      - 1 guest author profile was created
      - 0 users already had guest author profiles
      """
		# "1 of 2" also pins the sprintf argument order: failed count first, total second.
		And STDOUT should contain:
      """
      Warning: 1 of 2 users could not be given a guest author profile.
      """
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
      """
      2
      """

	Scenario: A user whose display name changed since their profile was created still has one
		Given I run `wp user create alice alice@example.com --role=author --porcelain`
		And I run `wp co-authors-plus create-guest-authors`
		# The profile's login is derived from the display name, so a second create
		# would no longer collide with the first and would add a duplicate profile.
		And I run `wp user update alice --display_name="Alice Smith"`
		When I run `wp co-authors-plus create-guest-authors`
		Then STDOUT should be:
      """
      Attempting to create guest author profiles for 2 users.
      All done! Here are your results:
      - 0 guest author profiles were created
      - 2 users already had guest author profiles
      """
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
      """
      2
      """
