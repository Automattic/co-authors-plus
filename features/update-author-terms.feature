Feature: Author terms can be recounted and recreated

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`

	Scenario: An author term is created for a user without one
		When I run `wp co-authors-plus update-author-terms`
		Then STDOUT should be:
		"""
		Now updating 0 terms
		Created author term for admin
		Now inspecting or updating 0 Guest Authors.
		Success: All done
		"""
		When I run `wp term list author --field=slug --orderby=slug --order=asc`
		Then STDOUT should be:
		"""
		cap-admin
		"""

	Scenario: An existing term is recounted and refreshed
		When I run `wp post create --post_title="A post" --post_status=publish --post_author=1 --porcelain`
		And I run `wp term list author --slug=cap-admin --field=term_id`
		And save STDOUT as {TERM_ID}
		When I run `wp co-authors-plus update-author-terms`
		Then STDOUT should be:
		"""
		Now updating 1 terms
		Term cap-admin ({TERM_ID}) changed from 1 to 1 and the description was refreshed
		Now inspecting or updating 0 Guest Authors.
		Success: All done
		"""
		When I run `wp term list author --field=count`
		Then STDOUT should be:
		"""
		1
		"""

	Scenario: A stale term count is corrected in the database but the log reports the stale value
		When I run `wp post create --post_title="A post" --post_status=publish --post_author=1 --porcelain`
		And I run `wp term list author --slug=cap-admin --field=term_id`
		And save STDOUT as {TERM_ID}
		And I run `wp eval '$t = get_term_by( "slug", "cap-admin", "author" ); global $wpdb; $wpdb->update( $wpdb->term_taxonomy, array( "count" => 5 ), array( "term_taxonomy_id" => $t->term_taxonomy_id ) );'`
		And I run `wp term list author --field=count`
		Then STDOUT should be:
		"""
		5
		"""
		When I run `wp co-authors-plus update-author-terms`
		Then STDOUT should be:
		"""
		Now updating 1 terms
		Term cap-admin ({TERM_ID}) changed from 5 to 5 and the description was refreshed
		Now inspecting or updating 0 Guest Authors.
		Success: All done
		"""
		When I run `wp term list author --field=count`
		Then STDOUT should be:
		"""
		1
		"""

	Scenario: A term without a matching co-author is still reported as refreshed
		When I run `wp term create author ghost --slug=cap-ghost --porcelain`
		And save STDOUT as {TERM_ID}
		When I run `wp co-authors-plus update-author-terms`
		Then STDOUT should be:
		"""
		Now updating 1 terms
		Term cap-ghost ({TERM_ID}) changed from 0 to 0 and the description was refreshed
		Created author term for admin
		Now inspecting or updating 0 Guest Authors.
		Success: All done
		"""
		When I run `wp term list author --field=slug --orderby=slug --order=asc`
		Then STDOUT should be:
		"""
		cap-admin
		cap-ghost
		"""

	Scenario: The description of an existing term is rewritten from the co-author's searchable fields
		When I run `wp term create author admin --slug=cap-admin --description="stale description" --porcelain`
		And save STDOUT as {TERM_ID}
		And I run `wp co-authors-plus update-author-terms`
		Then STDOUT should be:
		"""
		Now updating 1 terms
		Term cap-admin ({TERM_ID}) changed from 0 to 0 and the description was refreshed
		Now inspecting or updating 0 Guest Authors.
		Success: All done
		"""
		When I run `wp term get author {TERM_ID} --field=description`
		Then STDOUT should match #^admin {3}admin 1 \S+@\S+$#
		And STDOUT should not match /stale description/

	Scenario: An author term is created for every user, including one who has never authored a post
		When I run `wp user create zsub zsub@example.com --role=subscriber --porcelain`
		And I run `wp co-authors-plus update-author-terms`
		Then STDOUT should be:
		"""
		Now updating 0 terms
		Created author term for admin
		Created author term for zsub
		Now inspecting or updating 0 Guest Authors.
		Success: All done
		"""
		When I run `wp term list author --field=slug --orderby=slug --order=asc`
		Then STDOUT should be:
		"""
		cap-admin
		cap-zsub
		"""

	Scenario: Guest authors created via the CLI are drafts and invisible to the guest author pass
		When I run `wp co-authors-plus create-guest-authors`
		And I run `wp post list --post_type=guest-author --fields=post_name,post_status --format=csv`
		Then STDOUT should be:
		"""
		post_name,post_status
		cap-admin,draft
		"""
		When I run `wp term list author --slug=cap-admin --field=term_id`
		And save STDOUT as {TERM_ID}
		When I run `wp co-authors-plus update-author-terms`
		Then STDOUT should be:
		"""
		Now updating 1 terms
		Term cap-admin ({TERM_ID}) changed from 0 to 0 and the description was refreshed
		Now inspecting or updating 0 Guest Authors.
		Success: All done
		"""

	Scenario: An author term is created for a published guest author without one
		When I run `wp eval 'echo $GLOBALS["coauthors_plus"]->guest_authors->create( array( "display_name" => "Guest One", "user_login" => "guest-one" ) );'`
		And save STDOUT as {GA_ID}
		And I run `wp post update {GA_ID} --post_status=publish`
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`
		When I run `wp co-authors-plus update-author-terms`
		Then STDOUT should be:
		"""
		Now updating 0 terms
		Created author term for admin
		Now inspecting or updating 1 Guest Authors.
		Created author term for Guest Author guest-one
		Success: All done
		"""
		When I run `wp term list author --field=slug --orderby=slug --order=asc`
		Then STDOUT should be:
		"""
		cap-admin
		cap-guest-one
		"""
