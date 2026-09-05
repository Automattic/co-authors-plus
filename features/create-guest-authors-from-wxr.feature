Feature: Guest authors can be created from the author nodes of a WXR file

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		# Author terms survive the Behat reset, so wipe them: a leftover `cap-*` term
		# would otherwise satisfy the term assertions below without the command
		# creating anything, and would be silently reused by CoAuthors_Plus::get_author_term().
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`
		# The command `require_once`s wordpress-importer's parsers.php directly, so that
		# plugin is a hard dependency of every scenario below. It is pinned to 0.9.6
		# because the crash characterised further down belongs to that release's parser
		# fallback chain; the assertion makes a failed download fail here, legibly,
		# rather than as a confusing fatal inside the command under test.
		And I run `wp plugin install wordpress-importer --version=0.9.6`
		And I run `wp plugin get wordpress-importer --field=version`
		And STDOUT should be:
		"""
		0.9.6
		"""

	Scenario: Error on a missing required --file parameter
		When I try `wp co-authors-plus create-guest-authors-from-wxr`
		Then the return code should be 1
		And STDERR should contain:
		"""
		missing --file parameter
		"""

	Scenario: Error on a file that cannot be read
		When I try `wp co-authors-plus create-guest-authors-from-wxr --file=no-such-file.wxr`
		Then the return code should be 1
		And STDERR should be:
		"""
		Error: Please specify a valid WXR file with the --file arg.
		"""

	Scenario: Fatal error when the wordpress-importer plugin is not installed
		Given I run `wp plugin uninstall wordpress-importer --deactivate`
		When I try `wp co-authors-plus create-guest-authors-from-wxr --file=features/fixtures/guest-authors.wxr`
		Then the return code should be 1
		# The container path and PHP's exact wording belong to the runtime, not to CAP,
		# so only the require_once of the importer's parsers.php is pinned.
		And STDERR should match #require_once\(.*/wordpress-importer/parsers\.php\)#
		And STDOUT should match #Failed opening required '.*/wordpress-importer/parsers\.php'#
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		0
		"""
		# Put the pinned importer back so that scenario order in this file is not
		# load-bearing: the reset between scenarios does not restore plugins.
		When I run `wp plugin install wordpress-importer --version=0.9.6`
		And I run `wp plugin get wordpress-importer --field=version`
		Then STDOUT should be:
		"""
		0.9.6
		"""

	Scenario: Create guest authors from the author nodes of a WXR file
		When I run `wp co-authors-plus create-guest-authors-from-wxr --file=features/fixtures/guest-authors.wxr`
		Then the return code should be 0
		And STDOUT should not match /Undefined array key/
		And STDOUT should contain:
		"""
		Processing author wxr-jane (wxr-jane@example.com)
		"""
		And STDOUT should contain:
		"""
		Processing author wxr-bob (wxr-bob@example.com)
		"""
		And STDOUT should contain:
		"""
		Success: -- Created as guest author #
		"""
		And STDOUT should contain:
		"""
		All done!
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		2
		"""
		When I run `wp post list --post_type=guest-author --field=post_name --order=asc --orderby=ID`
		Then STDOUT should be:
		"""
		cap-wxr-jane
		cap-wxr-bob
		"""
		When I run `wp post list --post_type=guest-author --meta_key=cap-user_login --meta_value=wxr-jane --format=ids`
		And save STDOUT as {JANE_ID}
		And STDOUT should not be empty
		And I run `wp post meta list {JANE_ID} --format=csv`
		Then STDOUT should be:
		"""
		post_id,meta_key,meta_value
		{JANE_ID},cap-display_name,"WXR Jane"
		{JANE_ID},cap-first_name,WXR
		{JANE_ID},cap-last_name,Jane
		{JANE_ID},cap-user_login,wxr-jane
		{JANE_ID},cap-user_email,wxr-jane@example.com
		{JANE_ID},_original_author_id,101
		{JANE_ID},_original_author_login,wxr-jane
		"""
		# The importers pass the source author ID under the `ID` key. The guard used to
		# test `author_id`, which no caller sets, so this meta was never written and the
		# documented provenance was lost.
		When I run `wp post meta get {JANE_ID} _original_author_id`
		Then STDOUT should be:
		"""
		101
		"""
		# Scoped to the profile just created, not to the slug: a term-slug lookup would
		# pass on residue from an earlier feature or an earlier run.
		When I run `wp term list author --object_ids={JANE_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-wxr-jane
		"""
		# The second author gets exactly the same treatment.
		When I run `wp post list --post_type=guest-author --meta_key=cap-user_login --meta_value=wxr-bob --format=ids`
		And save STDOUT as {BOB_ID}
		And STDOUT should not be empty
		And I run `wp post meta list {BOB_ID} --format=csv`
		Then STDOUT should be:
		"""
		post_id,meta_key,meta_value
		{BOB_ID},cap-display_name,"WXR Bob"
		{BOB_ID},cap-first_name,WXR
		{BOB_ID},cap-last_name,Bob
		{BOB_ID},cap-user_login,wxr-bob
		{BOB_ID},cap-user_email,wxr-bob@example.com
		{BOB_ID},_original_author_id,102
		{BOB_ID},_original_author_login,wxr-bob
		"""
		When I run `wp term list author --object_ids={BOB_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-wxr-bob
		"""

	Scenario: A WXR file with no author nodes creates nothing
		When I run `wp co-authors-plus create-guest-authors-from-wxr --file=features/fixtures/no-authors.wxr`
		Then the return code should be 0
		And STDOUT should be:
		"""
		All done!
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Importing the same WXR file twice skips the existing guest authors
		Given I run `wp co-authors-plus create-guest-authors-from-wxr --file=features/fixtures/guest-authors.wxr`
		And I run `wp post list --post_type=guest-author --meta_key=cap-user_login --meta_value=wxr-jane --format=ids`
		And save STDOUT as {JANE_ID}
		And STDOUT should not be empty
		And I run `wp post list --post_type=guest-author --meta_key=cap-user_login --meta_value=wxr-bob --format=ids`
		And save STDOUT as {BOB_ID}
		And STDOUT should not be empty
		When I run `wp co-authors-plus create-guest-authors-from-wxr --file=features/fixtures/guest-authors.wxr`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Processing author wxr-jane (wxr-jane@example.com)
		Warning: -- Author already exists (ID #{JANE_ID}, user_login wxr-jane); skipping.
		Processing author wxr-bob (wxr-bob@example.com)
		Warning: -- Author already exists (ID #{BOB_ID}, user_login wxr-bob); skipping.
		All done!
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		2
		"""

	Scenario: Fatal error in the importer when the file is not a WXR file
		When I try `wp co-authors-plus create-guest-authors-from-wxr --file=features/fixtures/not-a-wxr.xml`
		Then the return code should be 1
		# The crash comes from wordpress-importer 0.9.6's parser fallback chain, so only
		# the fact that CAP dies inside that plugin is pinned, not the class or file.
		And STDOUT should match /Fatal error/
		And STDOUT should match /wordpress-importer/
		# CAP's own `Failed to read WXR file.` branch is unreachable: the parser fatals
		# rather than returning a WP_Error.
		And STDERR should not match /Failed to read WXR file/
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		0
		"""
