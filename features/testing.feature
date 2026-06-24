  Feature: The Behat tests are configured correctly

  Scenario: WP-CLI recognises Co-Authors Plus commands when the plugin is loaded
    Given a WP installation with the Co-Authors Plus plugin

    When I run `wp co-authors-plus --help`
    Then STDOUT should contain:
      """
      Manage co-authors and guest authors.
      """
