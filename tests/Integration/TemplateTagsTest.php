<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

class TemplateTagsTest extends TestCase {

	use \Yoast\PHPUnitPolyfills\Polyfills\AssertStringContains;

	private $author1;
	private $editor1;
	private $post;

	public function set_up() {

		parent::set_up();

		/**
		 * When 'coauthors_auto_apply_template_tags' is set to true,
		 * we need CoAuthors_Template_Filters object to check 'the_author' filter.
		 */
		global $coauthors_plus_template_filters;
		$coauthors_plus_template_filters = new \CoAuthors_Template_Filters();

		$this->author1 = $this->factory()->user->create_and_get(
			array(
				'role'       => 'author',
				'user_login' => 'author1',
			)
		);
		$this->editor1 = $this->factory()->user->create_and_get(
			array(
				'role'       => 'editor',
				'user_login' => 'editor1',
			)
		);

		$this->post = $this->factory()->post->create_and_get(
			array(
				'post_author'  => $this->author1->ID,
				'post_status'  => 'publish',
				'post_content' => rand_str(),
				'post_title'   => rand_str(),
				'post_type'    => 'post',
			)
		);
	}

	/**
	 * Tests for co-authors display names.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/279
	 *
	 * @covers ::coauthors_links()
	 */
	public function test_coauthors_links(): void {

		global $coauthors_plus, $coauthors_plus_template_filters;

		// Backing up global post.
		$post_backup = $GLOBALS['post'];

		$GLOBALS['post'] = $this->post;

		// Checks for single post author.
		$single_cpl = coauthors_links( null, null, null, null, false );

		$this->assertEquals( $this->author1->display_name, $single_cpl, 'Author name not found.' );

		// Checks for multiple post author.
		$coauthors_plus->add_coauthors( $this->post->ID, array( $this->editor1->user_login ), true );

		$multiple_cpl = coauthors_links( null, null, null, null, false );

		$this->assertStringContainsString( $this->author1->display_name, $multiple_cpl, 'Main author name not found.' );
		$this->assertEquals( 1, substr_count( $multiple_cpl, $this->author1->display_name ) );
		$this->assertStringContainsString( ' and ', $multiple_cpl, 'Coauthors name separator is not matched.' );
		$this->assertStringContainsString( $this->editor1->display_name, $multiple_cpl, 'Coauthor name not found.' );
		$this->assertEquals( 1, substr_count( $multiple_cpl, $this->editor1->display_name ) );

		$multiple_cpl = coauthors_links( null, ' or ', null, null, false );

		$this->assertStringContainsString( ' or ', $multiple_cpl, 'Coauthors name separator is not matched.' );

		$this->assertEquals(
			10,
			has_filter(
				'the_author',
				array(
					$coauthors_plus_template_filters,
					'filter_the_author',
				)
			)
		);

		// Restore backed up post to global.
		$GLOBALS['post'] = $post_backup;
	}

	/**
	 * Tests for co-authors display names, without links to their posts.
	 *
	 * @covers ::coauthors()
	 * @covers ::coauthors__echo()
	 **/
	public function test_coauthors(): void {

		global $post, $coauthors_plus;

		// Backing up global post.
		$post_backup = $post;

		$post = $this->post;

		// Checks for single post author.
		$coauthors = coauthors( null, null, null, null, false );

		$this->assertEquals( $this->author1->display_name, $coauthors );

		$coauthors = coauthors( '</span><span>', '</span><span>', '<span>', '</span>', false );

		$this->assertEquals( '<span>' . $this->author1->display_name . '</span>', $coauthors );

		// Checks for multiple post author.
		$coauthors_plus->add_coauthors( $this->post->ID, array( $this->editor1->user_login ), true );

		$coauthors = coauthors( null, null, null, null, false );

		$this->assertEquals( $this->author1->display_name . ' and ' . $this->editor1->display_name, $coauthors );

		$coauthors = coauthors( '</span><span>', '</span><span>', '<span>', '</span>', false );

		$this->assertEquals( '<span>' . $this->author1->display_name . '</span><span>' . $this->editor1->display_name . '</span>', $coauthors );

		// Restore global post from backup.
		$post = $post_backup;
	}

	/**
	 * Checks co-authors first names, without links to their posts.
	 *
	 * @covers ::coauthors_firstnames()
	 * @covers ::coauthors__echo()
	 */
	public function test_coauthors_firstnames(): void {

		global $post, $coauthors_plus;

		// Backing up global post.
		$post_backup = $post;

		$post = $this->post;

		// Checking when first name is not set for user, so it should match with user_login.
		$first_names = coauthors_firstnames( null, null, null, null, false );

		$this->assertEquals( $this->author1->user_login, $first_names );

		$first_names = coauthors_firstnames( '</span><span>', '</span><span>', '<span>', '</span>', false );

		$this->assertEquals( '<span>' . $this->author1->user_login . '</span>', $first_names );

		$coauthors_plus->add_coauthors( $this->post->ID, array( $this->editor1->user_login ), true );

		$first_names = coauthors_firstnames( null, null, null, null, false );

		$this->assertEquals( $this->author1->user_login . ' and ' . $this->editor1->user_login, $first_names );

		$first_names = coauthors_firstnames( '</span><span>', '</span><span>', '<span>', '</span>', false );

		$this->assertEquals( '<span>' . $this->author1->user_login . '</span><span>' . $this->editor1->user_login . '</span>', $first_names );

		// Checking when first name is set for user.
		$first_name = 'Test';
		$user_id    = $this->factory()->user->create(
			array(
				'first_name' => $first_name,
			)
		);
		$post       = $this->factory()->post->create_and_get(
			array(
				'post_author' => $user_id,
			)
		);

		$first_names = coauthors_firstnames( null, null, null, null, false );

		$this->assertEquals( $first_name, $first_names );

		// Restore global post from backup.
		$post = $post_backup;
	}

	/**
	 * Checks co-authors last names, without links to their posts.
	 *
	 * @covers ::coauthors_lastnames()
	 * @covers ::coauthors__echo()
	 */
	public function test_coauthors_lastnames(): void {

		global $post, $coauthors_plus;

		// Backing up global post.
		$post_backup = $post;

		$post = $this->post;

		// Checking when last name is not set for user, so it should match with user_login.
		$last_names = coauthors_lastnames( null, null, null, null, false );

		$this->assertEquals( $this->author1->user_login, $last_names );

		$last_names = coauthors_lastnames( '</span><span>', '</span><span>', '<span>', '</span>', false );

		$this->assertEquals( '<span>' . $this->author1->user_login . '</span>', $last_names );

		$coauthors_plus->add_coauthors( $this->post->ID, array( $this->editor1->user_login ), true );

		$last_names = coauthors_lastnames( null, null, null, null, false );

		$this->assertEquals( $this->author1->user_login . ' and ' . $this->editor1->user_login, $last_names );

		$last_names = coauthors_lastnames( '</span><span>', '</span><span>', '<span>', '</span>', false );

		$this->assertEquals( '<span>' . $this->author1->user_login . '</span><span>' . $this->editor1->user_login . '</span>', $last_names );

		// Checking when last name is set for user.
		$last_name = 'Test';
		$user_id   = $this->factory()->user->create(
			array(
				'last_name' => $last_name,
			)
		);
		$post      = $this->factory()->post->create_and_get(
			array(
				'post_author' => $user_id,
			)
		);

		$last_names = coauthors_lastnames( null, null, null, null, false );

		$this->assertEquals( $last_name, $last_names );

		// Restore global post from backup.
		$post = $post_backup;
	}

	/**
	 * Checks co-authors nicknames, without links to their posts.
	 *
	 * @covers ::coauthors_nicknames()
	 * @covers ::coauthors__echo()
	 */
	public function test_coauthors_nicknames(): void {

		global $post, $coauthors_plus;

		// Backing up global post.
		$post_backup = $post;

		$post = $this->post;

		// Checking when nickname is not set for user, so it should match with user_login.
		$nick_names = coauthors_nicknames( null, null, null, null, false );

		$this->assertEquals( $this->author1->user_login, $nick_names );

		$nick_names = coauthors_nicknames( '</span><span>', '</span><span>', '<span>', '</span>', false );

		$this->assertEquals( '<span>' . $this->author1->user_login . '</span>', $nick_names );

		$coauthors_plus->add_coauthors( $this->post->ID, array( $this->editor1->user_login ), true );

		$nick_names = coauthors_nicknames( null, null, null, null, false );

		$this->assertEquals( $this->author1->user_login . ' and ' . $this->editor1->user_login, $nick_names );

		$nick_names = coauthors_nicknames( '</span><span>', '</span><span>', '<span>', '</span>', false );

		$this->assertEquals( '<span>' . $this->author1->user_login . '</span><span>' . $this->editor1->user_login . '</span>', $nick_names );

		// Checking when nickname is set for user.
		$nick_name = 'Test';
		$user_id   = $this->factory()->user->create(
			array(
				'nickname' => $nick_name,
			)
		);
		$post      = $this->factory()->post->create_and_get(
			array(
				'post_author' => $user_id,
			)
		);

		$nick_names = coauthors_nicknames( null, null, null, null, false );

		$this->assertEquals( $nick_name, $nick_names );

		// Restore global post from backup.
		$post = $post_backup;
	}

	/**
	 * Checks co-authors email addresses.
	 *
	 * @covers ::coauthors_emails()
	 * @covers ::coauthors__echo()
	 */
	public function test_coauthors_emails(): void {

		global $post, $coauthors_plus;

		// Backing up global post.
		$post_backup = $post;

		$post = $this->post;

		$emails = coauthors_emails( null, null, null, null, false );

		$this->assertEquals( $this->author1->user_email, $emails );

		$emails = coauthors_emails( '</span><span>', '</span><span>', '<span>', '</span>', false );

		$this->assertEquals( '<span>' . $this->author1->user_email . '</span>', $emails );

		$coauthors_plus->add_coauthors( $this->post->ID, array( $this->editor1->user_login ), true );

		$emails = coauthors_emails( null, null, null, null, false );

		$this->assertEquals( $this->author1->user_email . ' and ' . $this->editor1->user_email, $emails );

		$emails = coauthors_emails( '</span><span>', '</span><span>', '<span>', '</span>', false );

		$this->assertEquals( '<span>' . $this->author1->user_email . '</span><span>' . $this->editor1->user_email . '</span>', $emails );

		$email   = 'test@example.org';
		$user_id = $this->factory()->user->create(
			array(
				'user_email' => $email,
			)
		);
		$post    = $this->factory()->post->create_and_get(
			array(
				'post_author' => $user_id,
			)
		);

		$emails = coauthors_emails( null, null, null, null, false );

		$this->assertEquals( $email, $emails );

		// Restore global post from backup.
		$post = $post_backup;
	}

	/**
	 * Checks single co-author if he/she is a guest author.
	 *
	 * @covers ::coauthors_links_single()
	 */
	public function test_coauthors_links_single_when_guest_author(): void {

		global $post, $authordata;

		// Backing up global post.
		$post_backup = $post;

		$post = $this->post;

		// Backing up global author data.
		$authordata_backup = $authordata;
		$authordata        = $this->author1;

		// Shows that it's necessary to set $authordata to $this->author1
		$this->assertEquals( $authordata, $this->author1, 'Global $authordata not matching expected $this->author1.' );

		$this->author1->type = 'guest-author';

		$this->assertEquals( get_the_author_link(), coauthors_links_single( $this->author1 ), 'Co-Author link generation differs from Core author link one (without user_url)' );

		wp_update_user(
			array(
				'ID'       => $this->author1->ID,
				'user_url' => 'example.org',
			)
		);
		$authordata = get_userdata( $this->author1->ID ); // Because wp_update_user flushes cache, but does not update global var

		$this->assertEquals( get_the_author_link(), coauthors_links_single( $this->author1 ), 'Co-Author link generation differs from Core author link one (with user_url)' );

		$author_link = coauthors_links_single( $this->author1 );
		$this->assertStringContainsString( get_the_author_meta( 'url' ), $author_link, 'Author url not found in link.' );
		$this->assertStringContainsString( get_the_author(), $author_link, 'Author name not found in link.' );

		// Here we are checking author name should not be more than one time.
		// Asserting ">get_the_author()<" because "get_the_author()" can be multiple times like in href, title, etc.
		$this->assertEquals( 1, substr_count( $author_link, '>' . get_the_author() . '<' ) );

		// Restore global author data from backup.
		$authordata = $authordata_backup;

		// Restore global post from backup.
		$post = $post_backup;
	}

	/**
	 * Checks single co-author when user's url is set and not a guest author.
	 *
	 * @covers ::coauthors_links_single()
	 */
	public function test_coauthors_links_single_author_url_is_set(): void {

		global $post, $authordata;

		// Backing up global post.
		$post_backup = $post;

		$post = $this->post;

		// Backing up global author data.
		$authordata_backup = $authordata;

		$user_id = $this->factory()->user->create(
			array(
				'user_url' => 'example.org',
			)
		);
		$user    = get_user_by( 'id', $user_id );

		$authordata  = $user;
		$author_link = coauthors_links_single( $user );

		$this->assertStringContainsString( get_the_author_meta( 'url' ), $author_link, 'Author link not found.' );
		$this->assertStringContainsString( get_the_author(), $author_link, 'Author name not found.' );

		// Here we are checking author name should not be more than one time.
		// Asserting ">get_the_author()<" because "get_the_author()" can be multiple times like in href, title, etc.
		$this->assertEquals( 1, substr_count( $author_link, '>' . get_the_author() . '<' ) );

		// Restore global author data from backup.
		$authordata = $authordata_backup;

		// Restore global post from backup.
		$post = $post_backup;
	}

	/**
	 * Checks single co-author when user's website/url not exist.
	 *
	 * @covers ::coauthors_links_single()
	 */
	public function test_coauthors_links_single_when_url_not_exist(): void {
		global $wp_version;
		if ( PHP_VERSION_ID >= 80100 && version_compare( $wp_version, '6.3.0', '<' ) ) {
			/*
			 * Ignoring PHP 8.1 "null to non-nullable" deprecation that is fixed in WP 6.3.
			 *
			 * @see https://core.trac.wordpress.org/ticket/58157
			*/
			$this->markTestSkipped( 'PHP 8.1 gives a deprecation notice that is fixed in WP 6.3' );
		}

		global $post, $authordata;

		// Backing up global post.
		$post_backup = $post;

		$post = $this->post;

		// Backing up global author data.
		$authordata_backup = $authordata;

		$this->editor1->type = 'guest-author';

		$author_link = coauthors_links_single( $this->editor1 );

		$this->assertEquals( get_the_author(), $author_link );

		$authordata  = $this->author1;
		$author_link = coauthors_links_single( $this->author1 );

		$this->assertEquals( get_the_author(), $author_link );

		// Restore global author data from backup.
		$authordata = $authordata_backup;

		// Restore global post from backup.
		$post = $post_backup;
	}

	/**
	 * Checks co-authors IDs.
	 *
	 * @covers ::coauthors_ids()
	 * @covers ::coauthors__echo()
	 */
	public function test_coauthors_ids(): void {

		global $post, $coauthors_plus;

		// Backing up global post.
		$post_backup = $post;

		$post = $this->post;

		$ids = coauthors_ids( null, null, null, null, false );

		$this->assertEquals( $this->author1->ID, $ids );

		$ids = coauthors_ids( '</span><span>', '</span><span>', '<span>', '</span>', false );

		$this->assertEquals( '<span>' . $this->author1->ID . '</span>', $ids );

		$coauthors_plus->add_coauthors( $this->post->ID, array( $this->editor1->user_login ), true );

		$ids = coauthors_ids( null, null, null, null, false );

		$this->assertEquals( $this->author1->ID . ' and ' . $this->editor1->ID, $ids );

		$ids = coauthors_ids( '</span><span>', '</span><span>', '<span>', '</span>', false );

		$this->assertEquals( '<span>' . $this->author1->ID . '</span><span>' . $this->editor1->ID . '</span>', $ids );

		// Restore global post from backup.
		$post = $post_backup;
	}

	/**
	 * Checks co-authors meta.
	 *
	 * @covers ::get_the_coauthor_meta()
	 */
	public function test_get_the_coauthor_meta(): void {

		global $post;

		// Backing up global post.
		$post_backup = $post;

		$this->assertEmpty( get_the_coauthor_meta( '' ) );

		update_user_meta( $this->author1->ID, 'meta_key', 'meta_value' );

		$this->assertEmpty( get_the_coauthor_meta( 'meta_key' ) );

		$post = $this->post;
		$meta = get_the_coauthor_meta( 'meta_key' );

		$this->assertEquals( 'meta_value', $meta[ $this->author1->ID ] );

		// Restore global post from backup.
		$post = $post_backup;
	}

	/**
	 * Tests that template tags don't cause PHP warnings when post has no author.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/1066
	 *
	 * @covers ::coauthors_links()
	 * @covers ::coauthors__echo()
	 */
	public function test_coauthors_links_when_post_has_no_author(): void {
		global $post;

		// Backing up global post.
		$post_backup = $post;

		// Create a post with no author (post_author = 0)
		$post_without_author = $this->factory()->post->create_and_get(
			array(
				'post_author'  => 0,
				'post_status'  => 'publish',
				'post_content' => rand_str(),
				'post_title'   => 'Post without author',
				'post_type'    => 'post',
			)
		);

		$post = $post_without_author;

		// This should not cause a PHP warning about accessing property on null
		// The function should handle the case where there's no author gracefully
		$result = coauthors_links( null, null, null, null, false );

		// When there's no author, expect empty string or a safe fallback
		$this->assertIsString( $result, 'Result should be a string' );

		// Restore global post from backup.
		$post = $post_backup;
	}
}
