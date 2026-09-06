Feature: Guest authors and their post assignments can be imported

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`

	Scenario: Error when no file is given
		When I try `wp co-authors-plus import-coauthors`
		Then STDERR should be:
		"""
		Error: Parameter errors:
		 missing --file parameter (The file to read, as written by export-coauthors.)
		"""
		And the return code should be 1

	Scenario: Error on a file that is not JSON
		When I run `wp eval 'file_put_contents( "/tmp/cap-not-json.txt", "not json {{" );'`
		And I try `wp co-authors-plus import-coauthors --file=/tmp/cap-not-json.txt`
		Then STDERR should be:
		"""
		Error: /tmp/cap-not-json.txt is not valid JSON.
		"""
		And the return code should be 1

	Scenario: Error on JSON that is not an export
		When I run `wp eval 'file_put_contents( "/tmp/cap-no-list.json", wp_json_encode( array( "version" => "4.1.1" ) ) );'`
		And I try `wp co-authors-plus import-coauthors --file=/tmp/cap-no-list.json`
		Then STDERR should be:
		"""
		Error: Export is missing a "guest_authors" list. Was the file produced by export-coauthors?
		"""
		And the return code should be 1

	Scenario: Point out a file written by a different version
		When I run `wp eval 'file_put_contents( "/tmp/cap-old.json", wp_json_encode( array( "version" => "1.0.0", "guest_authors" => array() ) ) );'`
		And I run `wp co-authors-plus import-coauthors --file=/tmp/cap-old.json`
		Then STDOUT should contain:
		"""
		This file was written by Co-Authors Plus 1.0.0
		"""
		And the return code should be 0

	# The login here differs in case and punctuation from the nicename the byline
	# is keyed on, which is the case a naive implementation gets wrong.
	Scenario: Restore a profile and put it back on its post
		When I run `wp post create --post_status=publish --post_title="Hello" --post_name=hello --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp eval 'file_put_contents( "/tmp/cap-in.json", wp_json_encode( array( "version" => "4.1.1", "guest_authors" => array( array( "profile" => array( "display_name" => "Jane Doe", "user_login" => "Jane.Doe" ), "post_refs" => array( array( "post_slug" => "hello", "post_type" => "post", "position" => 0 ) ) ) ) ) ) );'`
		When I run `wp co-authors-plus import-coauthors --file=/tmp/cap-in.json`
		Then STDOUT should be:
		"""
		Success: Created 1 profiles and linked 1 posts.
		"""
		And the return code should be 0
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-jane-doe
		"""

		# Running it again must not duplicate the byline.
		When I run `wp co-authors-plus import-coauthors --file=/tmp/cap-in.json`
		Then STDOUT should be:
		"""
		Success: Created 0 profiles and linked 0 posts. 1 profiles already existed.
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-jane-doe
		"""

	# The byline order here is deliberately neither alphabetical nor the order
	# the profiles were created in, so only the recorded positions can restore
	# it. The probe reads term_order the same way the plugin's own read path
	# does.
	Scenario: A multi-author byline comes back in its original order
		When I run `wp eval 'echo $GLOBALS["coauthors_plus"]->guest_authors->create( array( "display_name" => "Alice Smith", "user_login" => "alice-smith" ) );'`
		And I run `wp eval 'echo $GLOBALS["coauthors_plus"]->guest_authors->create( array( "display_name" => "Bob Jones", "user_login" => "bob-jones" ) );'`
		And I run `wp eval 'echo $GLOBALS["coauthors_plus"]->guest_authors->create( array( "display_name" => "Carol King", "user_login" => "carol-king" ) );'`
		And I run `wp post create --post_status=publish --post_title="Hello" --post_name=hello --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp eval 'wp_set_post_terms( {POST_ID}, array( "cap-carol-king", "cap-alice-smith", "cap-bob-jones" ), "author" );'`
		When I run `wp eval 'echo implode( ",", wp_list_pluck( wp_get_object_terms( {POST_ID}, "author", array( "orderby" => "term_order" ) ), "slug" ) );'`
		Then STDOUT should be:
		"""
		cap-carol-king,cap-alice-smith,cap-bob-jones
		"""

		When I run `wp co-authors-plus export-coauthors --file=/tmp/cap-order.json`
		Then STDOUT should be:
		"""
		Success: Exported 3 guest authors to /tmp/cap-order.json
		"""

		# Wipe the site clean: profiles, author terms and the post itself.
		When I run `wp eval 'foreach ( get_posts( array( "post_type" => "guest-author", "post_status" => "any", "numberposts" => -1 ) ) as $p ) { wp_delete_post( $p->ID, true ); }'`
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`
		And I run `wp post delete {POST_ID} --force`
		And I run `wp post create --post_status=publish --post_title="Hello" --post_name=hello --porcelain`
		And save STDOUT as {NEW_POST_ID}

		When I run `wp co-authors-plus import-coauthors --file=/tmp/cap-order.json`
		Then STDOUT should be:
		"""
		Success: Created 3 profiles and linked 3 posts.
		"""
		And the return code should be 0
		When I run `wp eval 'echo implode( ",", wp_list_pluck( wp_get_object_terms( {NEW_POST_ID}, "author", array( "orderby" => "term_order" ) ), "slug" ) );'`
		Then STDOUT should be:
		"""
		cap-carol-king,cap-alice-smith,cap-bob-jones
		"""

	Scenario: A dry run reports what it would do and writes nothing
		When I run `wp post create --post_status=publish --post_title="Hello" --post_name=hello --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp eval 'file_put_contents( "/tmp/cap-dry.json", wp_json_encode( array( "version" => "4.1.1", "guest_authors" => array( array( "profile" => array( "display_name" => "Jane Doe", "user_login" => "jane-doe" ), "post_refs" => array( array( "post_slug" => "hello", "post_type" => "post", "position" => 0 ) ) ) ) ) ) );'`
		When I run `wp co-authors-plus import-coauthors --file=/tmp/cap-dry.json --dry-run`
		Then STDOUT should be:
		"""
		Dry run: nothing will be written.
		Success: Would create 1 profiles and linked 1 posts.
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		0
		"""
		When I run `wp term list author --object_ids={POST_ID} --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: --skip-create leaves a missing profile uncreated
		When I run `wp post create --post_status=publish --post_title="Hello" --post_name=hello --porcelain`
		And I run `wp eval 'file_put_contents( "/tmp/cap-skip.json", wp_json_encode( array( "version" => "4.1.1", "guest_authors" => array( array( "profile" => array( "display_name" => "Jane Doe", "user_login" => "jane-doe" ), "post_refs" => array( array( "post_slug" => "hello", "post_type" => "post", "position" => 0 ) ) ) ) ) ) );'`
		When I run `wp co-authors-plus import-coauthors --file=/tmp/cap-skip.json --skip-create`
		Then STDOUT should contain:
		"""
		Success: Created 0 profiles and linked 0 posts.
		"""
		And STDOUT should contain:
		"""
		No profile for "jane-doe" and --skip-create was given.
		"""
		When I run `wp post list --post_type=guest-author --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Report an assignment whose post is not on this site
		When I run `wp eval 'file_put_contents( "/tmp/cap-missing.json", wp_json_encode( array( "version" => "4.1.1", "guest_authors" => array( array( "profile" => array( "display_name" => "Jane Doe", "user_login" => "jane-doe" ), "post_refs" => array( array( "post_slug" => "not-here", "post_type" => "post", "position" => 0 ) ) ) ) ) ) );'`
		When I run `wp co-authors-plus import-coauthors --file=/tmp/cap-missing.json`
		Then STDOUT should contain:
		"""
		1 assignments had no matching post on this site.
		"""
		And STDOUT should contain:
		"""
		No post found with slug "not-here".
		"""
		And the return code should be 0
