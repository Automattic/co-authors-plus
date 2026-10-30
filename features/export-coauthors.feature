Feature: Guest authors and their post assignments can be exported

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`

	Scenario: Warn when there is nothing to export
		When I run `wp co-authors-plus export-coauthors --file=/tmp/cap-export-empty.json`
		Then STDOUT should be:
		"""
		Warning: No guest authors found, so there is nothing to export.
		"""
		And the return code should be 0

	Scenario: Export a guest author and the post it is assigned to
		When I run `wp eval 'echo $GLOBALS["coauthors_plus"]->guest_authors->create( array( "display_name" => "Jane Doe", "user_login" => "jane-doe" ) );'`
		And I run `wp post create --post_status=publish --post_title="Hello" --post_name=hello --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp eval 'wp_set_post_terms( {POST_ID}, array( "cap-jane-doe" ), "author" );'`
		When I run `wp co-authors-plus export-coauthors --file=/tmp/cap-export.json`
		Then STDOUT should be:
		"""
		Success: Exported 1 guest author to /tmp/cap-export.json
		"""
		And the return code should be 0

		# Assignments are keyed on slug and post type, never on post ID, because
		# IDs do not survive a WordPress export and import cycle.
		When I run `wp eval 'echo wp_json_encode( json_decode( file_get_contents( "/tmp/cap-export.json" ), true )["guest_authors"][0]["post_refs"] );'`
		Then STDOUT should be:
		"""
		[{"post_slug":"hello","post_type":"post","position":0}]
		"""

		When I run `wp eval 'echo json_decode( file_get_contents( "/tmp/cap-export.json" ), true )["guest_authors"][0]["profile"]["user_login"];'`
		Then STDOUT should be:
		"""
		jane-doe
		"""

	Scenario: Export a guest author that is not assigned to anything
		When I run `wp eval 'echo $GLOBALS["coauthors_plus"]->guest_authors->create( array( "display_name" => "Nobody", "user_login" => "nobody" ) );'`
		And I run `wp co-authors-plus export-coauthors --file=/tmp/cap-export-lonely.json`
		Then STDOUT should be:
		"""
		Success: Exported 1 guest author to /tmp/cap-export-lonely.json
		"""
		When I run `wp eval 'echo count( json_decode( file_get_contents( "/tmp/cap-export-lonely.json" ), true )["guest_authors"][0]["post_refs"] );'`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Restrict the recorded assignments to given post types
		When I run `wp eval 'echo $GLOBALS["coauthors_plus"]->guest_authors->create( array( "display_name" => "Jane Doe", "user_login" => "jane-doe" ) );'`
		And I run `wp post create --post_status=publish --post_title="A page" --post_name=a-page --post_type=page --porcelain`
		And save STDOUT as {PAGE_ID}
		And I run `wp eval 'wp_set_post_terms( {PAGE_ID}, array( "cap-jane-doe" ), "author" );'`
		When I run `wp co-authors-plus export-coauthors --file=/tmp/cap-export-types.json --post-types=post`
		Then the return code should be 0
		When I run `wp eval 'echo count( json_decode( file_get_contents( "/tmp/cap-export-types.json" ), true )["guest_authors"][0]["post_refs"] );'`
		Then STDOUT should be:
		"""
		0
		"""
