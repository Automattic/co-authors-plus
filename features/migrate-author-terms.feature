Feature: Legacy author terms can be migrated to prefixed slugs

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`

	Scenario: Report when there are no author terms to migrate
		When I run `wp co-authors-plus migrate-author-terms`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Now migrating up to 0 terms
		Success: All done! Grab a cold one (Affogato)
		"""

	Scenario: Prefix an unprefixed author term
		When I run `wp term create author someone --porcelain`
		And save STDOUT as {TERM_ID}
		And I run `wp co-authors-plus migrate-author-terms`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Now migrating up to 1 term
		Term someone ({TERM_ID}) isn't prefixed, adding one
		Success: All done! Grab a cold one (Affogato)
		"""
		When I run `wp term list author --field=slug`
		Then STDOUT should be:
		"""
		cap-someone
		"""

	Scenario: The term name keeps its original value when the slug is prefixed
		When I run `wp term create author "Legacy Author" --porcelain`
		And save STDOUT as {TERM_ID}
		And I run `wp co-authors-plus migrate-author-terms`
		Then STDOUT should be:
		"""
		Now migrating up to 1 term
		Term legacy-author ({TERM_ID}) isn't prefixed, adding one
		Success: All done! Grab a cold one (Affogato)
		"""
		When I run `wp term list author --fields=name,slug --format=csv`
		Then STDOUT should be:
		"""
		name,slug
		"Legacy Author",cap-legacy-author
		"""

	Scenario: Skip terms that already have the cap- prefix
		When I run `wp term create author someone --slug=cap-someone --porcelain`
		And save STDOUT as {TERM_ID}
		And I run `wp co-authors-plus migrate-author-terms`
		Then STDOUT should be:
		"""
		Now migrating up to 0 terms
		Success: All done! Grab a cold one (Affogato)
		"""
		# With the log silent, this state assertion is the sole discriminator: an
		# inverted filter would produce cap-cap-someone here.
		When I run `wp term list author --field=slug`
		Then STDOUT should be:
		"""
		cap-someone
		"""

	Scenario: Merge a prefixed sibling into the unprefixed term and then prefix it
		When I run `wp term create author someone --porcelain`
		And save STDOUT as {BARE_ID}
		And I run `wp term create author cap-someone --porcelain`
		And save STDOUT as {PREFIXED_ID}
		And I run `wp post create --post_title="Merged post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post term add {POST_ID} author cap-someone`
		And I run `wp co-authors-plus migrate-author-terms`
		Then STDOUT should be:
		"""
		Now migrating up to 1 term
		Term someone ({BARE_ID}) has a new term too: cap-someone ({PREFIXED_ID}). Merging
		Term someone ({BARE_ID}) isn't prefixed, adding one
		Success: All done! Grab a cold one (Affogato)
		"""
		When I run `wp term list author --fields=term_id,slug --format=csv`
		Then STDOUT should be:
		"""
		term_id,slug
		{BARE_ID},cap-someone
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=term_id`
		Then STDOUT should be:
		"""
		{BARE_ID}
		"""

	Scenario: Running the migration twice finds nothing left to do
		When I run `wp term create author someone --porcelain`
		And save STDOUT as {TERM_ID}
		And I run `wp co-authors-plus migrate-author-terms`
		And I run the previous command again
		Then STDOUT should be:
		"""
		Now migrating up to 0 terms
		Success: All done! Grab a cold one (Affogato)
		"""
		# The second run's output is now identical to a run with no terms at all, so
		# only this proves the first run did the migrating.
		When I run `wp term list author --fields=term_id,slug --format=csv`
		Then STDOUT should be:
		"""
		term_id,slug
		{TERM_ID},cap-someone
		"""

	Scenario: Same-slug terms in other taxonomies are left alone
		When I run `wp eval 'foreach ( array( "category", "post_tag" ) as $tax ) { foreach ( array( "guardian", "cap-guardian" ) as $slug ) { $t = get_term_by( "slug", $slug, $tax ); if ( $t ) { wp_delete_term( $t->term_id, $tax ); } } }'`
		And I run `wp term create category guardian`
		And I run `wp term create post_tag cap-guardian`
		And I run `wp term create author guardian --porcelain`
		And save STDOUT as {TERM_ID}
		And I run `wp co-authors-plus migrate-author-terms`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Now migrating up to 1 term
		Term guardian ({TERM_ID}) isn't prefixed, adding one
		Success: All done! Grab a cold one (Affogato)
		"""
		When I run `wp term list post_tag --slug=cap-guardian --field=slug`
		Then STDOUT should be:
		"""
		cap-guardian
		"""
		When I run `wp term list category --slug=guardian --field=slug`
		Then STDOUT should be:
		"""
		guardian
		"""
		When I run `wp term list author --fields=term_id,slug --format=csv`
		Then STDOUT should be:
		"""
		term_id,slug
		{TERM_ID},cap-guardian
		"""

	Scenario: A prefixed sibling is merged and deleted whatever the term ordering
		When I run `wp term create author "Aaa" --slug=someone --porcelain`
		And save STDOUT as {BARE_ID}
		And I run `wp term create author "Zzz" --slug=cap-someone --porcelain`
		And save STDOUT as {PREFIXED_ID}
		And I run `wp co-authors-plus migrate-author-terms`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Now migrating up to 1 term
		Term someone ({BARE_ID}) has a new term too: cap-someone ({PREFIXED_ID}). Merging
		Term someone ({BARE_ID}) isn't prefixed, adding one
		Success: All done! Grab a cold one (Affogato)
		"""
		When I run `wp term list author --fields=term_id,name,slug --format=csv`
		Then STDOUT should be:
		"""
		term_id,name,slug
		{BARE_ID},Aaa,cap-someone
		"""

	Scenario: The count reports only the terms that will be migrated
		When I run `wp term create author alice --slug=cap-alice`
		And I run `wp term create author bob --slug=cap-bob`
		And I run `wp term create author someone --porcelain`
		And save STDOUT as {TERM_ID}
		And I run `wp co-authors-plus migrate-author-terms`
		Then the return code should be 0
		And STDOUT should be:
		"""
		Now migrating up to 1 term
		Term someone ({TERM_ID}) isn't prefixed, adding one
		Success: All done! Grab a cold one (Affogato)
		"""
		When I run `wp term list author --field=slug --orderby=slug --order=asc`
		Then STDOUT should be:
		"""
		cap-alice
		cap-bob
		cap-someone
		"""
