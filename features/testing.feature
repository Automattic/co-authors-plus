  Feature: The Behat tests are configured correctly

  Scenario: WP-CLI recognises Co-Authors Plus commands when the plugin is loaded
    Given a WP installation with the Co-Authors Plus plugin

    When I run `wp co-authors-plus --help`
    Then STDOUT should contain:
      """
      Manage co-authors and guest authors.
      """

  # Add every new subcommand here. This guard is what fails first if one stops
  # being registered, so a command missing from the list is silently unguarded.
  Scenario: All expected subcommands remain registered
    Given a WP installation with the Co-Authors Plus plugin

    When I run `wp co-authors-plus --help`
    Then STDOUT should contain:
      """
      assign-coauthors
      """
    And STDOUT should contain:
      """
      assign-user-to-coauthor
      """
    And STDOUT should contain:
      """
      create-author
      """
    And STDOUT should contain:
      """
      create-author-terms-for-posts
      """
    And STDOUT should contain:
      """
      create-guest-authors
      """
    And STDOUT should contain:
      """
      create-guest-authors-from-csv
      """
    And STDOUT should contain:
      """
      create-guest-authors-from-wxr
      """
    And STDOUT should contain:
      """
      create-terms-for-posts
      """
    And STDOUT should contain:
      """
      delete-postmeta-that-skip-author-term-backfill
      """
    And STDOUT should contain:
      """
      list-authors
      """
    And STDOUT should contain:
      """
      list-posts-without-terms
      """
    And STDOUT should contain:
      """
      migrate-author-terms
      """
    And STDOUT should contain:
      """
      reassign-terms
      """
    And STDOUT should contain:
      """
      remove-terms-from-revisions
      """
    And STDOUT should contain:
      """
      rename-coauthor
      """
    And STDOUT should contain:
      """
      swap-coauthors
      """
    And STDOUT should contain:
      """
      update-author-terms
      """
