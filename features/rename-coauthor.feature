Feature: Co-authors can be renamed

	Background:
		Given a WP installation with the Co-Authors Plus plugin

	Scenario: Error on a missing required --from parameter
		When I try `wp co-authors-plus rename-coauthor --to="still-not-a-user"`
		Then STDERR should be:
          """
	      Error: Parameter errors:
           missing --from parameter
          """

	Scenario: Error on a missing required --to parameter
		When I try `wp co-authors-plus rename-coauthor --from="not-a-user"`
		Then STDERR should be:
          """
	      Error: Parameter errors:
           missing --to parameter
          """

	Scenario: Error on an invalid co-author
		When I try `wp co-authors-plus rename-coauthor --from="not-a-user" --to="still-not-a-user"`
		Then STDERR should be:
          """
	      Error: No co-author found for not-a-user
          """

	Scenario: Try to rename co-author to the same name
		When I run `wp co-authors-plus create-guest-authors`
		When I try `wp co-authors-plus rename-coauthor --from="admin" --to="admin"`
		Then STDERR should be:
          """
	      Error: New user_login value conflicts with existing co-author
          """

	Scenario: Rename co-author
		When I run `wp co-authors-plus create-guest-authors`
		When I run `wp co-authors-plus rename-coauthor --from="admin" --to="renamed-admin"`
		Then STDOUT should be:
          """
	      Renaming admin to renamed-admin
	      Updated guest author profile value too
	      Success: All done!
          """
