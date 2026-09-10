Feature: The co-author term index can be checked for drift

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`

	Scenario: A term whose stored count is wrong is reported
		When I run `wp post create --post_title="Admin post" --post_status=publish --post_author=1`
		And I run `wp eval '$t = get_term_by( "slug", "cap-admin", "author" ); global $wpdb; $wpdb->update( $wpdb->term_taxonomy, array( "count" => 5 ), array( "term_taxonomy_id" => $t->term_taxonomy_id ) ); clean_term_cache( $t->term_id, "author" );'`
		And I run `wp co-authors-plus check --only=stale-term-counts --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"stale-term-counts","status":"issues","count":1
		"""

	Scenario: A term whose stored count is correct is not reported
		When I run `wp post create --post_title="Admin post" --post_status=publish --post_author=1`
		And I run `wp co-authors-plus check --only=stale-term-counts --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"stale-term-counts","status":"ok","count":0
		"""

	Scenario: A term whose stored description is wrong is reported
		When I run `wp post create --post_title="Admin post" --post_status=publish --post_author=1`
		And I run `wp term update author cap-admin --by=slug --description="Out of date"`
		And I run `wp co-authors-plus check --only=stale-term-descriptions --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"stale-term-descriptions","status":"issues","count":1
		"""

	Scenario: A term whose stored description is correct is not reported
		When I run `wp post create --post_title="Admin post" --post_status=publish --post_author=1`
		And I run `wp co-authors-plus check --only=stale-term-descriptions --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"stale-term-descriptions","status":"ok","count":0
		"""

	Scenario: A guest author with no author term is reported
		When I run `wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe`
		And I run `wp eval 'wp_delete_term( get_term_by( "slug", "cap-jane-doe", "author" )->term_id, "author" );'`
		And I run `wp co-authors-plus check --only=missing-guest-author-terms --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"missing-guest-author-terms","status":"issues","count":1
		"""

	Scenario: A guest author with an author term is not reported
		When I run `wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe`
		And I run `wp co-authors-plus check --only=missing-guest-author-terms --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"missing-guest-author-terms","status":"ok","count":0
		"""

	# --format=count counts rows, so this is the number of checks, not the number
	# of findings. It is 9 whatever the state of the site, which is what makes it
	# a usable assertion for "every check ran exactly once".
	Scenario: Every check runs by default
		When I run `wp co-authors-plus check --format=count`
		Then the return code should be 0
		And STDOUT should be:
		"""
		9
		"""

	Scenario: Every check is named in the default report
		When I run `wp co-authors-plus check`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		posts-missing-terms
		"""
		And STDOUT should contain:
		"""
		missing-user-terms
		"""
		And STDOUT should contain:
		"""
		missing-guest-author-terms
		"""
		And STDOUT should contain:
		"""
		stale-term-counts
		"""
		And STDOUT should contain:
		"""
		stale-term-descriptions
		"""
		And STDOUT should contain:
		"""
		unprefixed-terms
		"""
		And STDOUT should contain:
		"""
		revision-terms
		"""
		And STDOUT should contain:
		"""
		orphaned-skip-markers
		"""
		And STDOUT should contain:
		"""
		guest-author-drift
		"""

	Scenario: A post that gained no author term is reported
		When I run `wp post create --post_title="No terms post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp eval 'wp_set_post_terms( {POST_ID}, array(), "author" );'`
		And I run `wp co-authors-plus check --only=posts-missing-terms --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"posts-missing-terms","status":"issues","count":1
		"""

	Scenario: A post with an author term is not reported
		When I run `wp post create --post_title="Authored post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		"""
		When I run `wp co-authors-plus check --only=posts-missing-terms --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"posts-missing-terms","status":"ok","count":0
		"""

	# A user is given a term only when they author something or their profile is
	# updated, so the post below is what puts admin into the clear and leaves the
	# new user as the only one missing a term.
	Scenario: A user with no author term is reported
		When I run `wp post create --post_title="Admin post" --post_status=publish --post_author=1`
		And I run `wp user create checker checker@example.com --role=author --porcelain`
		And I run `wp co-authors-plus check --only=missing-user-terms --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"missing-user-terms","status":"issues","count":1
		"""

	Scenario: A user whose term exists is not reported
		When I run `wp post create --post_title="Admin post" --post_status=publish --post_author=1`
		And I run `wp co-authors-plus check --only=missing-user-terms --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"missing-user-terms","status":"ok","count":0
		"""

	Scenario: An unprefixed legacy term is reported
		When I run `wp term create author legacy --porcelain`
		And I run `wp co-authors-plus check --only=unprefixed-terms --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"unprefixed-terms","status":"issues","count":1
		"""

	Scenario: A prefixed term is not reported as unprefixed
		When I run `wp term create author legacy --slug=cap-legacy`
		And I run `wp co-authors-plus check --only=unprefixed-terms --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"unprefixed-terms","status":"ok","count":0
		"""

	Scenario: A revision carrying author terms is reported
		When I run `wp post create --post_title="Revised post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post update {POST_ID} --post_content="Updated content"`
		And I run `wp post list --post_type=revision --post_status=inherit --post_parent={POST_ID} --posts_per_page=1 --orderby=ID --order=DESC --field=ID`
		And save STDOUT as {REVISION_ID}
		And I run `wp eval 'wp_set_post_terms( {REVISION_ID}, array( "cap-admin" ), "author" );'`
		And I run `wp co-authors-plus check --only=revision-terms --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"revision-terms","status":"issues","count":1
		"""

	Scenario: A revision without author terms is not reported
		When I run `wp post create --post_title="Plain post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post update {POST_ID} --post_content="Updated content"`
		And I run `wp co-authors-plus check --only=revision-terms --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"revision-terms","status":"ok","count":0
		"""

	# The marker is orphaned because the post has terms anyway, so it is no
	# longer holding the backfill back from anything.
	Scenario: A backfill skip marker on a post that has terms is orphaned
		When I run `wp post create --post_title="Skipped post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post meta add {POST_ID} _cap_skip_backfill nonexistent_post_author_id`
		And I run `wp co-authors-plus check --only=orphaned-skip-markers --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"orphaned-skip-markers","status":"issues","count":1
		"""

	Scenario: A backfill skip marker on a post that still has no terms is not orphaned
		When I run `wp post create --post_title="Still skipped post" --post_status=publish --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp eval 'wp_set_post_terms( {POST_ID}, array(), "author" );'`
		And I run `wp post meta add {POST_ID} _cap_skip_backfill nonexistent_post_author_id`
		And I run `wp co-authors-plus check --only=orphaned-skip-markers --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"orphaned-skip-markers","status":"ok","count":0
		"""

	Scenario: A guest author whose term slug no longer matches the profile is reported
		When I run `wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe`
		And I run `wp term list author --slug=cap-jane-doe --field=slug`
		Then STDOUT should be:
		"""
		cap-jane-doe
		"""
		And I run `wp eval 'wp_update_term( get_term_by( "slug", "cap-jane-doe", "author" )->term_id, "author", array( "slug" => "cap-jane-renamed" ) );'`
		And I run `wp co-authors-plus check --only=guest-author-drift --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"guest-author-drift","status":"issues","count":1
		"""

	Scenario: A guest author whose term slug matches the profile is not reported
		When I run `wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe`
		And I run `wp co-authors-plus check --only=guest-author-drift --format=json`
		Then the return code should be 0
		And STDOUT should contain:
		"""
		{"check":"guest-author-drift","status":"ok","count":0
		"""

	Scenario: --only runs exactly the named checks
		When I run `wp co-authors-plus check --only=unprefixed-terms,revision-terms --format=count`
		Then the return code should be 0
		And STDOUT should be:
		"""
		2
		"""

	Scenario: An unknown check name is rejected
		When I run `wp co-authors-plus check --only=no-such-check`
		Then the return code should not be 0
		And STDERR should contain:
		"""
		Unknown check(s): no-such-check
		"""
