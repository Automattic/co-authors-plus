Feature: Author terms can be created for all posts

	Background:
		Given a WP installation with the Co-Authors Plus plugin
		And I run `wp eval 'foreach ( get_terms( array( "taxonomy" => "author", "hide_empty" => false ) ) as $t ) { wp_delete_term( $t->term_id, "author" ); }'`

	Scenario: Succeed cleanly when there are no posts
		When I run `wp co-authors-plus create-terms-for-posts`
		Then STDOUT should be:
		"""
		Now inspecting or updating 0 total posts.
		Updating author terms with new counts
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
		1/2) Added - Post #{POST_A} 'First post' now has an author term for: admin
		No co-authors found for post #{POST_B}.
		2/2) Added - Post #{POST_B} 'Second post' now has an author term for: admin
		Updating author terms with new counts
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
		1/2) Skipping - Post #{POST_A} 'First post' already has these terms: admin
		2/2) Skipping - Post #{POST_B} 'Second post' already has these terms: admin
		Updating author terms with new counts
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
		1/2) Skipping - Post #{POST_A} 'First post' already has these terms: admin
		No co-authors found for post #{POST_B}.
		2/2) Added - Post #{POST_B} 'Second post' now has an author term for: admin
		Updating author terms with new counts
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
		1/1) Added - Post #{PAGE_ID} 'About page' now has an author term for: admin
		Updating author terms with new counts
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
		1/1) Added - Post #{POST_ID} 'Writer post' now has an author term for: writer
		Updating author terms with new counts
		Success: Done! Of 1 posts, 1 now have author terms.
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-writer
		"""

	Scenario: A post whose author does not exist is reported as updated even though no term is set
		When I run `wp post create --post_title="Orphan post" --post_status=publish --post_author=999 --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp co-authors-plus create-terms-for-posts`
		Then STDOUT should contain:
		"""
		Now inspecting or updating 1 total posts.
		"""
		And STDOUT should contain:
		"""
		No co-authors found for post #{POST_ID}.
		"""
		And STDOUT should contain:
		"""
		Warning: Attempt to read property "slug" on false
		"""
		And STDOUT should contain:
		"""
		Warning: Attempt to read property "user_nicename" on false
		"""
		And STDOUT should match #^1/1\) Added - Post \#\d+ 'Orphan post' now has an author term for: ?$#m
		And STDOUT should contain:
		"""
		Success: Done! Of 1 posts, 1 now have author terms.
		"""
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
		1/2) Added - Post #{POST_A} 'Alpha post' now has an author term for: alpha
		No co-authors found for post #{POST_B}.
		2/2) Added - Post #{POST_B} 'Beta post' now has an author term for: beta
		Updating author terms with new counts
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
		Updating author terms with new counts
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
		1/1) Skipping - Post #{POST_ID} 'Ghostwritten post' already has these terms: writer
		Updating author terms with new counts
		Success: Done! Of 1 posts, 0 now have author terms.
		"""
		When I run `wp term list author --object_ids={POST_ID} --field=slug`
		Then STDOUT should be:
		"""
		cap-writer
		"""
