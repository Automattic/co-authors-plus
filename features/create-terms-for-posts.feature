Feature: Author terms can be created for all posts

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`

	Scenario: Succeed cleanly when there are no posts
		When I run `wp co-authors-plus create-terms-for-posts`
		Then STDOUT should be:
		"""
		Now inspecting or updating 0 total posts.
		Success: Done! Of 0 posts, 0 now have author terms.
		"""

	Scenario: Add author terms to posts that are missing them, then skip them on a second run
		When I run `wp post create --post_title="First post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post create --post_title="Second post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp post term remove {POST_A} author --all`
		And I run `wp post term remove {POST_B} author --all`
		And I run `wp co-authors-plus create-terms-for-posts`
		Then STDOUT should be:
		"""
		Now inspecting or updating 2 total posts.
		No co-authors found for post #{POST_A}.
		1/2) Added - Post #{POST_A} 'First post' now has this author term: cap-admin
		No co-authors found for post #{POST_B}.
		2/2) Added - Post #{POST_B} 'Second post' now has this author term: cap-admin
		Success: Done! Of 2 posts, 2 now have author terms.
		"""
		When I run `wp term list author --object_ids={POST_A} --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		"""
		When I run `wp co-authors-plus create-terms-for-posts`
		Then STDOUT should be:
		"""
		Now inspecting or updating 2 total posts.
		1/2) Skipping - Post #{POST_A} 'First post' already has these terms: cap-admin
		2/2) Skipping - Post #{POST_B} 'Second post' already has these terms: cap-admin
		Success: Done! Of 2 posts, 0 now have author terms.
		"""

	Scenario: Skip posts that already have author terms while fixing those without
		When I run `wp post create --post_title="First post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post create --post_title="Second post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp post term remove {POST_B} author --all`
		And I run `wp co-authors-plus create-terms-for-posts`
		Then STDOUT should be:
		"""
		Now inspecting or updating 2 total posts.
		1/2) Skipping - Post #{POST_A} 'First post' already has these terms: cap-admin
		No co-authors found for post #{POST_B}.
		2/2) Added - Post #{POST_B} 'Second post' now has this author term: cap-admin
		Success: Done! Of 2 posts, 1 now have author terms.
		"""
		When I run `wp term list author --object_ids={POST_B} --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		"""

	Scenario: Pages are inspected by default
		When I run `wp post create --post_type=page --post_title="About page" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {PAGE_ID}
		And I run `wp post term remove {PAGE_ID} author --all`
		And I run `wp co-authors-plus create-terms-for-posts`
		Then STDOUT should be:
		"""
		Now inspecting or updating 1 total posts.
		No co-authors found for post #{PAGE_ID}.
		1/1) Added - Post #{PAGE_ID} 'About page' now has this author term: cap-admin
		Success: Done! Of 1 posts, 1 now have author terms.
		"""
		When I run `wp term list author --object_ids={PAGE_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-admin
		"""

	Scenario: The author term reflects the post author rather than always being admin
		When I run `wp user create writer writer@example.com --role=author --porcelain`
		And save STDOUT as {USER_ID}
		And I run `wp post create --post_title="Writer post" --post_status=publish --post_author={USER_ID} --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post term remove {POST_ID} author --all`
		And I run `wp co-authors-plus create-terms-for-posts`
		Then STDOUT should be:
		"""
		Now inspecting or updating 1 total posts.
		No co-authors found for post #{POST_ID}.
		1/1) Added - Post #{POST_ID} 'Writer post' now has this author term: cap-writer
		Success: Done! Of 1 posts, 1 now have author terms.
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-writer
		"""

	# The post is still reported, but as skipped rather than done, and the summary
	# no longer counts a post that came away with no term.
	Scenario: A post whose author does not exist is skipped with a warning
		When I run `wp post create --post_title="Orphan post" --post_status=publish --post_author=999 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp co-authors-plus create-terms-for-posts`
		Then STDOUT should be:
		"""
		Now inspecting or updating 1 total posts.
		No co-authors found for post #{POST_ID}.
		Warning: 1/1) Skipping - Post #{POST_ID} 'Orphan post' has no author term for user ID 999.
		Success: Done! Of 1 posts, 0 now have author terms.
		"""
		And the return code should be 0
		When I run `wp term list author --object_ids={POST_ID} --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: Each post gets an author term for its own author
		When I run `wp user create alpha alpha@example.com --role=author --porcelain`
		And save STDOUT as {USER_A}
		And I run `wp user create beta beta@example.com --role=author --porcelain`
		And save STDOUT as {USER_B}
		And I run `wp post create --post_title="Alpha post" --post_status=publish --post_author={USER_A} --porcelain`
		And save STDOUT as {POST_A}
		And I run `wp post create --post_title="Beta post" --post_status=publish --post_author={USER_B} --porcelain`
		And save STDOUT as {POST_B}
		And I run `wp post term remove {POST_A} author --all`
		And I run `wp post term remove {POST_B} author --all`
		And I run `wp co-authors-plus create-terms-for-posts`
		Then STDOUT should be:
		"""
		Now inspecting or updating 2 total posts.
		No co-authors found for post #{POST_A}.
		1/2) Added - Post #{POST_A} 'Alpha post' now has this author term: cap-alpha
		No co-authors found for post #{POST_B}.
		2/2) Added - Post #{POST_B} 'Beta post' now has this author term: cap-beta
		Success: Done! Of 2 posts, 2 now have author terms.
		"""
		When I run `wp term list author --object_ids={POST_A} --field=slug`
		Then STDOUT should be:
		"""
		cap-alpha
		"""
		When I run `wp term list author --object_ids={POST_B} --field=slug`
		Then STDOUT should be:
		"""
		cap-beta
		"""

	Scenario: Draft and private posts are never inspected
		When I run `wp post create --post_title="Draft post" --post_author=1 --porcelain`
		And save STDOUT as {DRAFT_ID}
		And I run `wp post create --post_title="Private post" --post_status=private --post_author=1 --porcelain`
		And save STDOUT as {PRIVATE_ID}
		And I run `wp post term remove {DRAFT_ID} author --all`
		And I run `wp post term remove {PRIVATE_ID} author --all`
		And I run `wp co-authors-plus create-terms-for-posts`
		Then STDOUT should be:
		"""
		Now inspecting or updating 0 total posts.
		Success: Done! Of 0 posts, 0 now have author terms.
		"""
		When I run `wp term list author --object_ids={DRAFT_ID} --format=count`
		Then STDOUT should be:
		"""
		0
		"""
		When I run `wp term list author --object_ids={PRIVATE_ID} --format=count`
		Then STDOUT should be:
		"""
		0
		"""

	Scenario: A post whose existing author term is not its post author is left alone
		When I run `wp user create writer writer@example.com --role=author --porcelain`
		And I run `wp post create --post_title="Ghostwritten post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp co-authors-plus update-author-terms`
		And I run `wp post term set {POST_ID} author cap-writer --by=slug`
		And I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-writer
		"""
		When I run `wp co-authors-plus create-terms-for-posts`
		Then STDOUT should be:
		"""
		Now inspecting or updating 1 total posts.
		1/1) Skipping - Post #{POST_ID} 'Ghostwritten post' already has these terms: cap-writer
		Success: Done! Of 1 posts, 0 now have author terms.
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-writer
		"""

	# Guards the removal of the "Updating author terms with new counts" pass. The
	# count is recalculated by the taxonomy's update_count_callback when the term is
	# set, so a second pass over the authors was never what maintained it.
	Scenario: Setting an author term recalculates that term's count
		When I run `wp post create --post_title="First post" --post_status=publish --post_author=1 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp post term remove {POST_ID} author --all`
		And I run `wp eval '$t = get_term_by( "slug", "cap-admin", "author" ); global $wpdb; $wpdb->update( $wpdb->term_taxonomy, array( "count" => 5 ), array( "term_taxonomy_id" => $t->term_taxonomy_id ) );'`
		And I run `wp term list author --field=count`
		Then STDOUT should be:
		"""
		5
		"""
		When I run `wp co-authors-plus create-terms-for-posts`
		Then the return code should be 0
		When I run `wp term list author --field=count`
		Then STDOUT should be:
		"""
		1
		"""
