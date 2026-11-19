<?php
/**
 * Creates a guest author whose login was stored with the prefix, and prints their ID.
 *
 * Run with `wp eval-file`, passing the login without its prefix, then the term's name.
 *
 * @package Automattic\CoAuthorsPlus
 */

global $coauthors_plus;

list( $clean_login, $term_name ) = $args;

$guest_id = $coauthors_plus->guest_authors->create(
	array(
		'display_name' => $clean_login,
		'user_login'   => $clean_login,
	)
);
$author_term = get_term_by( 'slug', 'cap-' . $clean_login, $coauthors_plus->coauthor_taxonomy );

update_post_meta( $guest_id, 'cap-user_login', 'cap-' . $clean_login );
wp_update_term( $author_term->term_id, $coauthors_plus->coauthor_taxonomy, array( 'name' => $term_name ) );

echo (int) $guest_id;
