<?php
/**
 * Seed demo data for manual testing of Co-Authors Plus.
 *
 * Creates a spread of WordPress users, guest authors (standalone and linked),
 * and posts with varied co-author assignments — including the fallback and
 * orphaned-author edge cases — then, on a block theme, swaps the single-post
 * byline for a Co-Authors Plus block so multi-author bylines render on the
 * front end.
 *
 * This file lives in /bin/ and is excluded from the distributed plugin ZIP
 * via .distignore. It is wired into `.wp-env.json`'s `afterStart` lifecycle,
 * and can be re-run at any time with `composer dev:seed`.
 *
 * Idempotent: re-running reuses existing users / guest authors / posts /
 * template (matched by login or slug) rather than duplicating them.
 *
 * @package CoAuthors
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

global $coauthors_plus;

if ( ! isset( $coauthors_plus ) || ! isset( $coauthors_plus->guest_authors ) ) {
	WP_CLI::error( 'Co-Authors Plus is not active; cannot seed demo data.' );
	return;
}

$report = array();

/* -------------------------------------------------------------------------
 * 1. WordPress users (one of each relevant role)
 * ---------------------------------------------------------------------- */
$seed_user = static function ( $login, $email, $display, $role, $first, $last ) {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		return (int) $existing->ID;
	}
	$id = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => 'password',
			'display_name' => $display,
			'first_name'   => $first,
			'last_name'    => $last,
			'role'         => $role,
		)
	);
	return is_wp_error( $id ) ? 0 : (int) $id;
};

$users = array(
	'ellie_editor'     => $seed_user( 'ellie_editor', 'ellie@example.test', 'Ellie Editor', 'editor', 'Ellie', 'Editor' ),
	'ana_author'       => $seed_user( 'ana_author', 'ana@example.test', 'Ana Author', 'author', 'Ana', 'Author' ),
	'ben_author'       => $seed_user( 'ben_author', 'ben@example.test', 'Ben Author', 'author', 'Ben', 'Author' ),
	'cara_contributor' => $seed_user( 'cara_contributor', 'cara@example.test', 'Cara Contributor', 'contributor', 'Cara', 'Contributor' ),
	'sam_subscriber'   => $seed_user( 'sam_subscriber', 'sam@example.test', 'Sam Subscriber', 'subscriber', 'Sam', 'Subscriber' ),
);
foreach ( $users as $login => $id ) {
	$report[] = sprintf( 'user      %-18s #%d', $login, $id );
}

/* -------------------------------------------------------------------------
 * 2. Guest authors (two standalone, one linked to a WP account)
 * ---------------------------------------------------------------------- */
$seed_guest = static function ( $args ) use ( $coauthors_plus ) {
	$existing = $coauthors_plus->guest_authors->get_guest_author_by( 'user_login', $args['user_login'] );
	if ( $existing ) {
		return (int) $existing->ID;
	}
	return $coauthors_plus->guest_authors->create( $args );
};

$greta = $seed_guest(
	array(
		'display_name' => 'Greta Guest',
		'user_login'   => 'greta_guest',
		'first_name'   => 'Greta',
		'last_name'    => 'Guest',
		'user_email'   => 'greta@example.test',
		'website'      => 'https://greta.example.test',
		'description'  => 'A standalone guest author with no linked WordPress account.',
	)
);
$hank = $seed_guest(
	array(
		'display_name' => 'Hank Helper',
		'user_login'   => 'hank_helper',
		'first_name'   => 'Hank',
		'last_name'    => 'Helper',
		'user_email'   => 'hank@example.test',
		'website'      => 'https://hank.example.test',
		'description'  => 'A second standalone guest author, byline-only.',
	)
);
$linked = $seed_guest(
	array(
		'display_name' => 'Cara (Guest Profile)',
		'user_login'   => 'cara_guest_profile',
		'first_name'   => 'Cara',
		'last_name'    => 'Contributor',
		'user_email'   => 'cara.guest@example.test',
		'description'  => 'A guest author linked to the cara_contributor user account.',
	)
);
if ( $linked && ! is_wp_error( $linked ) ) {
	update_post_meta( $linked, 'cap-linked_account', 'cara_contributor' );
	$coauthors_plus->guest_authors->delete_guest_author_cache( $linked );
}

$report[] = sprintf( 'guest     %-18s #%s', 'greta_guest', is_wp_error( $greta ) ? 'ERR' : $greta );
$report[] = sprintf( 'guest     %-18s #%s', 'hank_helper', is_wp_error( $hank ) ? 'ERR' : $hank );
$report[] = sprintf( 'guest     %-18s #%s (linked -> cara_contributor)', 'cara_guest_profile', is_wp_error( $linked ) ? 'ERR' : $linked );

/* -------------------------------------------------------------------------
 * 3. Posts with varied co-author assignments
 * ---------------------------------------------------------------------- */
$seed_post = static function ( $slug, $title, $author_login, $coauthor_logins ) use ( $coauthors_plus ) {
	$found = get_posts(
		array(
			'name'        => $slug,
			'post_type'   => 'post',
			'post_status' => 'any',
			'numberposts' => 1,
		)
	);
	if ( $found ) {
		$post_id = (int) $found[0]->ID;
	} else {
		$author_user = $author_login ? get_user_by( 'login', $author_login ) : null;
		$post_id     = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_author'  => $author_user ? $author_user->ID : 1,
				'post_content' => 'Sample content for smoke testing Co-Authors Plus bylines and assignments.',
			)
		);
	}

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return 'ERR';
	}

	// Assign co-authors by login (covers both WP users and guest authors).
	if ( ! empty( $coauthor_logins ) ) {
		$coauthors_plus->add_coauthors( $post_id, $coauthor_logins, false, 'user_login' );
	}
	return $post_id;
};

$posts = array(
	array( 'cap-single-author', 'The Quarterly Roadmap', 'ellie_editor', array( 'ellie_editor' ) ),
	array( 'cap-two-authors', 'Two Heads Are Better Than One', 'ana_author', array( 'ana_author', 'ben_author' ) ),
	array( 'cap-guest-only', 'A Guest Perspective', 'ellie_editor', array( 'greta_guest' ) ),
	array( 'cap-mixed-byline', 'Collaboration Across Teams', 'ellie_editor', array( 'ellie_editor', 'greta_guest' ) ),
	array( 'cap-three-mixed', 'The Big Announcement', 'ana_author', array( 'ana_author', 'ben_author', 'hank_helper' ) ),
	array( 'cap-linked-guest', 'Notes From a Linked Profile', 'cara_contributor', array( 'cara_guest_profile' ) ),
	// No co-author terms: falls back to post_author (legacy post).
	array( 'cap-fallback-no-terms', 'Legacy Import (No Co-Author Terms)', 'ben_author', array() ),
);

foreach ( $posts as $p ) {
	list( $slug, $title, $author, $coauthors ) = $p;
	$pid      = $seed_post( $slug, $title, $author, $coauthors );
	$report[] = sprintf( 'post      %-22s #%s  [%s]', $slug, $pid, implode( ', ', $coauthors ) );
}

// Orphaned-author post: post_author points at a non-existent user. Useful for
// testing `wp co-authors-plus assign-user-to-coauthor --user_id`.
$orphan_found = get_posts(
	array(
		'name'        => 'cap-orphaned-author',
		'post_type'   => 'post',
		'post_status' => 'any',
		'numberposts' => 1,
	)
);
if ( $orphan_found ) {
	$orphan_id = (int) $orphan_found[0]->ID;
} else {
	$orphan_id = wp_insert_post(
		array(
			'post_title'   => 'Orphaned Author Post',
			'post_name'    => 'cap-orphaned-author',
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_author'  => 99999, // Deliberately non-existent user ID.
			'post_content' => 'post_author references a user that no longer exists.',
		)
	);
}
$report[] = sprintf( 'post      %-22s #%s  [post_author=99999, no terms]', 'cap-orphaned-author', is_wp_error( $orphan_id ) ? 'ERR' : $orphan_id );

/* -------------------------------------------------------------------------
 * 4. On a block theme, render the single-post byline with a CAP block so
 *    multi-author bylines are visible on the front end.
 * ---------------------------------------------------------------------- */
if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
	$theme = get_stylesheet();
	$tpl   = get_block_template( $theme . '//single', 'wp_template' );

	if ( $tpl ) {
		$cap_byline = '<!-- wp:group {"style":{"spacing":{"margin":{"top":"var:preset|spacing|20"}}},"layout":{"type":"constrained"}} -->'
			. "\n" . '<div class="wp-block-group" style="margin-top:var(--wp--preset--spacing--20)">'
			. "\n" . '<!-- wp:co-authors-plus/coauthors -->'
			. "\n" . '<div class="wp-block-co-authors-plus-coauthors"><!-- wp:co-authors-plus/name /--></div>'
			. "\n" . '<!-- /wp:co-authors-plus/coauthors -->'
			. "\n" . '</div>'
			. "\n" . '<!-- /wp:group -->';

		// Twenty Twenty-Five renders the byline through this pattern; swap it.
		$needle  = '<!-- wp:pattern {"slug":"twentytwentyfive/hidden-written-by"} /-->';
		$content = $tpl->content;
		if ( strpos( $content, $needle ) !== false ) {
			$content = str_replace( $needle, $cap_byline, $content );
		} else {
			// Fallback for other block themes: inject after the post title.
			$title   = '<!-- wp:post-title {"level":1} /-->';
			$content = str_replace( $title, $title . "\n" . $cap_byline, $content );
		}

		$existing = get_posts(
			array(
				'post_type'   => 'wp_template',
				'post_status' => 'any',
				'name'        => 'single',
				'numberposts' => 1,
				'tax_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One-off dev seed.
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => $theme,
					),
				),
			)
		);

		$postarr = array(
			'post_type'    => 'wp_template',
			'post_status'  => 'publish',
			'post_name'    => 'single',
			'post_title'   => 'Single Posts',
			'post_excerpt' => 'Displays a single post with a Co-Authors Plus byline.',
			'post_content' => $content,
		);
		if ( $existing ) {
			$postarr['ID'] = $existing[0]->ID;
			$tpl_id        = wp_update_post( $postarr, true );
		} else {
			$tpl_id = wp_insert_post( $postarr, true );
		}

		if ( ! is_wp_error( $tpl_id ) ) {
			wp_set_object_terms( $tpl_id, $theme, 'wp_theme' );
			$report[] = sprintf( 'template  %-22s #%s  (CAP byline on %s)', 'single', $tpl_id, $theme );
		}
	}
}

/* -------------------------------------------------------------------------
 * Summary
 * ---------------------------------------------------------------------- */
WP_CLI::log( "\n=== Co-Authors Plus demo data ===" );
WP_CLI::log( implode( "\n", $report ) );
WP_CLI::log( "\nAll users share the password: password" );
WP_CLI::success( 'Demo data seeded.' );
