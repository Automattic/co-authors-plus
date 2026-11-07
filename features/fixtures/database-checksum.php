<?php
/**
 * Prints a checksum of everything a co-author report could change.
 *
 * Run with `wp eval-file`. Transients and cron are left out, because WordPress
 * can write those on any request.
 *
 * @package Automattic\CoAuthorsPlus
 */

global $wpdb;

$queries = array(
	"SELECT * FROM {$wpdb->posts} ORDER BY ID",
	"SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id",
	"SELECT * FROM {$wpdb->terms} ORDER BY term_id",
	"SELECT * FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id",
	"SELECT * FROM {$wpdb->term_relationships} ORDER BY object_id, term_taxonomy_id",
	"SELECT * FROM {$wpdb->termmeta} ORDER BY meta_id",
	// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users -- Only read, to prove a report leaves users alone.
	"SELECT * FROM {$wpdb->users} ORDER BY ID",
	"SELECT * FROM {$wpdb->usermeta} ORDER BY umeta_id",
	"SELECT * FROM {$wpdb->options} WHERE option_name NOT LIKE '%transient%' AND option_name <> 'cron' ORDER BY option_id",
);

$rows = array();

foreach ( $queries as $query ) {
	$rows[] = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed queries with no input.
}

echo md5( wp_json_encode( $rows ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A checksum.
