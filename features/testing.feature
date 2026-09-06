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
  # A name that is a substring of a longer command name (create-author inside
  # create-author-terms-for-posts, say) must use an anchored regex, or its
  # entry passes vacuously while the command is missing.
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
    And STDOUT should match /^\s+create-author\s/m
    And STDOUT should contain:
      """
      create-author-terms-for-posts
      """
    And STDOUT should match /^\s+create-guest-authors\s/m
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
      export-coauthors
      """
    And STDOUT should contain:
      """
      import-coauthors
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

  # Guest authors can be switched off with the coauthors_guest_authors_enabled
  # filter. Only the commands that read or write guest author profiles should
  # disappear with them; the term-based commands must stay registered. The
  # filter is planted through a --require file, which WP-CLI loads before
  # WordPress, so it is in place when the plugin decides what to register. The
  # file sits in wp-content because /tmp does not survive a fresh container.
  # Anchored regexes, because several names are substrings of longer ones.
  Scenario: Term-based commands stay registered when guest authors are disabled
    Given a WP installation with the Co-Authors Plus plugin

    When I run `eval 'file_put_contents( "/var/www/html/wp-content/cap-disable-guest-authors.php", base64_decode( "PD9waHAKV1BfQ0xJOjphZGRfd3BfaG9vayggJ2NvYXV0aG9yc19ndWVzdF9hdXRob3JzX2VuYWJsZWQnLCAnX19yZXR1cm5fZmFsc2UnICk7Cg==" ) );'`
    And I run `--require=/var/www/html/wp-content/cap-disable-guest-authors.php co-authors-plus --help`
    Then STDOUT should contain:
      """
      Manage co-authors and guest authors.
      """
    And STDOUT should match /^\s+assign-coauthors\s/m
    And STDOUT should match /^\s+assign-user-to-coauthor\s/m
    And STDOUT should match /^\s+create-author-terms-for-posts\s/m
    And STDOUT should match /^\s+create-terms-for-posts\s/m
    And STDOUT should match /^\s+delete-postmeta-that-skip-author-term-backfill\s/m
    And STDOUT should match /^\s+list-authors\s/m
    And STDOUT should match /^\s+list-posts-without-terms\s/m
    And STDOUT should match /^\s+migrate-author-terms\s/m
    And STDOUT should match /^\s+reassign-terms\s/m
    And STDOUT should match /^\s+remove-terms-from-revisions\s/m
    And STDOUT should match /^\s+rename-coauthor\s/m
    And STDOUT should match /^\s+swap-coauthors\s/m
    And STDOUT should match /^\s+update-author-terms\s/m
    And STDOUT should not match /^\s+create-author\s/m
    And STDOUT should not match /^\s+create-guest-authors\s/m
    And STDOUT should not match /^\s+create-guest-authors-from-csv\s/m
    And STDOUT should not match /^\s+create-guest-authors-from-wxr\s/m
    And STDOUT should not match /^\s+export-coauthors\s/m
    And STDOUT should not match /^\s+import-coauthors\s/m

    When I run `eval 'unlink( "/var/www/html/wp-content/cap-disable-guest-authors.php" );'`
    And I run `co-authors-plus --help`
    Then STDOUT should match /^\s+create-guest-authors\s/m
