<?php

function get_coauthors( $post_id = 0 ) {
	global $post, $post_ID, $coauthors_plus, $wpdb;

	$coauthors = array();
	$post_id   = (int) $post_id;
	if ( ! $post_id && $post_ID ) {
		$post_id = $post_ID;
	}

	if ( ! $post_id && $post ) {
		$post_id = $post->ID;
	}

	if ( $post_id ) {
		$coauthor_terms = cap_get_coauthor_terms_for_post( $post_id );
		if ( is_array( $coauthor_terms ) && ! empty( $coauthor_terms ) ) {
			foreach ( $coauthor_terms as $coauthor ) {
				$coauthor_slug = preg_replace( '#^cap\-#', '', $coauthor->slug );
				$post_author   = $coauthors_plus->get_coauthor_by( 'user_nicename', $coauthor_slug );
				// In case the user has been deleted while plugin was deactivated
				if ( ! empty( $post_author ) ) {
					$coauthors[] = $post_author;
				}
			}
		} elseif ( ! $coauthors_plus->force_guest_authors ) {
			if ( $post && $post_id == $post->ID ) {
				$post_author = get_userdata( $post->post_author );
			} else {
				$post_author = get_userdata( $wpdb->get_var( $wpdb->prepare( "SELECT post_author FROM $wpdb->posts WHERE ID = %d", $post_id ) ) );
			}
			if ( ! empty( $post_author ) ) {
				$coauthors[] = $post_author;
			}
		} // the empty else case is because if we force guest authors, we don't ever care what value wp_posts.post_author has.
	}
	// remove duplicate $coauthors objects from mapping user accounts to guest authors accounts
	$coauthors = array_unique( $coauthors, SORT_REGULAR );
	$coauthors = apply_filters( 'get_coauthors', $coauthors, $post_id );
	return $coauthors;
}

/**
 * Checks to see if the specified user is author of the current global post or post (if specified).
 *
 * @param object|int $user
 * @param int        $post_id
 */
function is_coauthor_for_post( $user, $post_id = 0 ) {
	global $post;

	if ( ! $post_id && $post ) {
		$post_id = $post->ID;
	}

	if ( ! $post_id ) {
		return false;
	}

	if ( ! $user ) {
		return false;
	}

	$coauthors = get_coauthors( $post_id );
	if ( is_numeric( $user ) ) {
		$user = get_userdata( $user );
		if ( isset( $user->user_login ) ) {
			$user = $user->user_login;
		} else {
			return false;
		}
	} elseif ( isset( $user->user_login ) ) {
		$user = $user->user_login;
	} else {
		return false;
	}

	foreach ( $coauthors as $coauthor ) {
		if ( ( isset( $coauthor->user_login ) && $user == $coauthor->user_login )
			|| ( isset( $coauthor->linked_account ) && $user == $coauthor->linked_account ) ) {
			return true;
		}
	}
	return false;
}

// Helper function for the following new template tags
function coauthors__echo( $tag, $type = 'tag', $separators = array(), $tag_args = null, $echo = true ) {

	// Define the standard output separator. Constant support is for backwards compat.
	// @see https://github.com/danielbachhuber/Co-Authors-Plus/issues/12
	$default_before       = ( defined( 'COAUTHORS_DEFAULT_BEFORE' ) ) ? COAUTHORS_DEFAULT_BEFORE : '';
	$default_between      = ( defined( 'COAUTHORS_DEFAULT_BETWEEN' ) ) ? COAUTHORS_DEFAULT_BETWEEN : ', ';
	$default_between_last = ( defined( 'COAUTHORS_DEFAULT_BETWEEN_LAST' ) ) ? COAUTHORS_DEFAULT_BETWEEN_LAST : __( ' and ', 'co-authors-plus' );
	$default_after        = ( defined( 'COAUTHORS_DEFAULT_AFTER' ) ) ? COAUTHORS_DEFAULT_AFTER : '';

	if ( ! isset( $separators['before'] ) ) {
		$separators['before'] = apply_filters( 'coauthors_default_before', $default_before );
	}
	if ( ! isset( $separators['between'] ) ) {
		$separators['between'] = apply_filters( 'coauthors_default_between', $default_between );
	}
	if ( ! isset( $separators['betweenLast'] ) ) {
		$separators['betweenLast'] = apply_filters( 'coauthors_default_between_last', $default_between_last );
	}
	if ( ! isset( $separators['after'] ) ) {
		$separators['after'] = apply_filters( 'coauthors_default_after', $default_after );
	}

	$output = '';

	$i       = new CoAuthorsIterator();
	$output .= $separators['before'];
	$i->iterate();
	do {
		// Skip if there's no valid author (e.g., post has no author)
		if ( ! is_object( $i->current_author ) ) {
			continue;
		}

		$author_text = '';

		if ( 'tag' === $type ) {
			$author_text = $tag( $tag_args );
		} elseif ( 'field' === $type && isset( $i->current_author->$tag ) ) {
			$author_text = $i->current_author->$tag;
		} elseif ( 'callback' === $type && is_callable( $tag ) ) {
			$author_text = $tag( $i->current_author );
		}

		// Fallback to user_login if we get something empty
		if ( empty( $author_text ) && ! empty( $i->current_author->user_login ) ) {
			$author_text = $i->current_author->user_login;
		}

		// Append separators
		if ( $i->count() - $i->position == 1 ) { // last author or only author
			$output .= $author_text;
		} elseif ( $i->count() - $i->position == 2 ) { // second to last
			$output .= $author_text . $separators['betweenLast'];
		} else {
			$output .= $author_text . $separators['between'];
		}
	} while ( $i->iterate() );

	$output .= $separators['after'];

	if ( $echo ) {
		echo $output; // phpcs:ignore
	}

	return $output;
}

/**
 * Outputs the co-authors display names, without links to their posts.
 * Co-Authors Plus equivalent of the_author() template tag.
 *
 * @param string $between Delimiter that should appear between the co-authors
 * @param string $betweenLast Delimiter that should appear between the last two co-authors
 * @param string $before What should appear before the presentation of co-authors
 * @param string $after What should appear after the presentation of co-authors
 * @param bool   $echo Whether the co-authors should be echoed or returned. Defaults to true.
 */
function coauthors( $between = null, $betweenLast = null, $before = null, $after = null, $echo = true ) {
	return coauthors__echo(
		'display_name',
		'field',
		array(
			'between'     => $between,
			'betweenLast' => $betweenLast,
			'before'      => $before,
			'after'       => $after,
		),
		null,
		$echo
	);
}

/**
 * Outputs the co-authors display names, with links to their posts.
 * Co-Authors Plus equivalent of the_author_posts_link() template tag.
 *
 * @param string $between Delimiter that should appear between the co-authors
 * @param string $betweenLast Delimiter that should appear between the last two co-authors
 * @param string $before What should appear before the presentation of co-authors
 * @param string $after What should appear after the presentation of co-authors
 * @param bool   $echo Whether the co-authors should be echoed or returned. Defaults to true.
 */
function coauthors_posts_links( $between = null, $betweenLast = null, $before = null, $after = null, $echo = true ) {

	global $coauthors_plus_template_filters;

	$modify_filter = ! empty( $coauthors_plus_template_filters ) && $coauthors_plus_template_filters instanceof CoAuthors_Template_Filters;

	if ( $modify_filter ) {

		/**
		 * Removing "the_author" filter so that it won't get called in loop and append names for each author.
		 *
		 * Ref : https://github.com/Automattic/Co-Authors-Plus/issues/279
		 */
		remove_filter( 'the_author', array( $coauthors_plus_template_filters, 'filter_the_author' ) );
	}

	$coauthors_posts_links = coauthors__echo(
		'coauthors_posts_links_single',
		'callback',
		array(
			'between'     => $between,
			'betweenLast' => $betweenLast,
			'before'      => $before,
			'after'       => $after,
		),
		null,
		$echo
	);

	if ( $modify_filter ) {
		add_filter( 'the_author', array( $coauthors_plus_template_filters, 'filter_the_author' ) );
	}

	return $coauthors_posts_links;
}

/**
 * Outputs a single co-author linked to their post archive.
 *
 * @param object $author
 * @return string
 */
function coauthors_posts_links_single( $author ) {
	// Return if the fields we are trying to use are not sent
	if ( ! isset( $author->ID, $author->user_nicename, $author->display_name ) ) {
		_doing_it_wrong(
			'coauthors_posts_links_single',
			'Invalid author object used',
			'3.2'
		);
		return;
	}
	$args        = array(
		'before_html' => '',
		'href'        => get_author_posts_url( $author->ID, $author->user_nicename ),
		'rel'         => 'author',
		/* translators: Author display name. */
		'title'       => sprintf( __( 'Posts by %s', 'co-authors-plus' ), apply_filters( 'the_author', $author->display_name ) ),
		'class'       => 'author url fn',
		'text'        => apply_filters( 'the_author', $author->display_name ),
		'after_html'  => '',
	);
	$args        = apply_filters( 'coauthors_posts_link', $args, $author );
	$single_link = sprintf(
		'<a href="%1$s" title="%2$s" class="%3$s" rel="%4$s">%5$s</a>',
		esc_url( $args['href'] ),
		esc_attr( $args['title'] ),
		esc_attr( $args['class'] ),
		esc_attr( $args['rel'] ),
		esc_html( $args['text'] )
	);
	return $args['before_html'] . $single_link . $args['after_html'];
}

/**
 * Outputs the co-authors first names, without links to their posts.
 *
 * @param string $between Delimiter that should appear between the co-authors
 * @param string $betweenLast Delimiter that should appear between the last two co-authors
 * @param string $before What should appear before the presentation of co-authors
 * @param string $after What should appear after the presentation of co-authors
 * @param bool   $echo Whether the co-authors should be echoed or returned. Defaults to true.
 */
function coauthors_firstnames( $between = null, $betweenLast = null, $before = null, $after = null, $echo = true ) {
	return coauthors__echo(
		'get_the_author_meta',
		'tag',
		array(
			'between'     => $between,
			'betweenLast' => $betweenLast,
			'before'      => $before,
			'after'       => $after,
		),
		'first_name',
		$echo
	);
}

/**
 * Outputs the co-authors last names, without links to their posts.
 *
 * @param string $between Delimiter that should appear between the co-authors
 * @param string $betweenLast Delimiter that should appear between the last two co-authors
 * @param string $before What should appear before the presentation of co-authors
 * @param string $after What should appear after the presentation of co-authors
 * @param bool   $echo Whether the co-authors should be echoed or returned. Defaults to true.
 */
function coauthors_lastnames( $between = null, $betweenLast = null, $before = null, $after = null, $echo = true ) {
	return coauthors__echo(
		'get_the_author_meta',
		'tag',
		array(
			'between'     => $between,
			'betweenLast' => $betweenLast,
			'before'      => $before,
			'after'       => $after,
		),
		'last_name',
		$echo
	);
}

/**
 * Outputs the co-authors nicknames, without links to their posts.
 *
 * @param string $between Delimiter that should appear between the co-authors
 * @param string $betweenLast Delimiter that should appear between the last two co-authors
 * @param string $before What should appear before the presentation of co-authors
 * @param string $after What should appear after the presentation of co-authors
 * @param bool   $echo Whether the co-authors should be echoed or returned. Defaults to true.
 */
function coauthors_nicknames( $between = null, $betweenLast = null, $before = null, $after = null, $echo = true ) {
	return coauthors__echo(
		'get_the_author_meta',
		'tag',
		array(
			'between'     => $between,
			'betweenLast' => $betweenLast,
			'before'      => $before,
			'after'       => $after,
		),
		'nickname',
		$echo
	);
}

/**
 * Outputs the co-authors display names, with links to their websites if they've provided them.
 *
 * @param string $between Delimiter that should appear between the co-authors
 * @param string $betweenLast Delimiter that should appear between the last two co-authors
 * @param string $before What should appear before the presentation of co-authors
 * @param string $after What should appear after the presentation of co-authors
 * @param bool   $echo Whether the co-authors should be echoed or returned. Defaults to true.
 */
function coauthors_links( $between = null, $betweenLast = null, $before = null, $after = null, $echo = true ) {

	global $coauthors_plus_template_filters;

	$modify_filter = ! empty( $coauthors_plus_template_filters ) && $coauthors_plus_template_filters instanceof CoAuthors_Template_Filters;

	if ( $modify_filter ) {

		/**
		 * Removing "the_author" filter so that it won't get called in loop and append names for each author.
		 *
		 * Ref : https://github.com/Automattic/Co-Authors-Plus/issues/279
		 */
		remove_filter( 'the_author', array( $coauthors_plus_template_filters, 'filter_the_author' ) );
	}

	$coauthors_links = coauthors__echo(
		'coauthors_links_single',
		'callback',
		array(
			'between'     => $between,
			'betweenLast' => $betweenLast,
			'before'      => $before,
			'after'       => $after,
		),
		null,
		$echo
	);

	if ( $modify_filter ) {
		add_filter( 'the_author', array( $coauthors_plus_template_filters, 'filter_the_author' ) );
	}

	return $coauthors_links;
}

/**
 * Outputs the co-authors email addresses
 *
 * @param string $between Delimiter that should appear between the email addresses
 * @param string $betweenLast Delimiter that should appear between the last two email addresses
 * @param string $before What should appear before the presentation of email addresses
 * @param string $after What should appear after the presentation of email addresses
 * @param bool   $echo Whether the co-authors should be echoed or returned. Defaults to true.
 */
function coauthors_emails( $between = null, $betweenLast = null, $before = null, $after = null, $echo = true ) {
	return coauthors__echo(
		'get_the_author_meta',
		'tag',
		array(
			'between'     => $between,
			'betweenLast' => $betweenLast,
			'before'      => $before,
			'after'       => $after,
		),
		'user_email',
		$echo
	);
}

/**
 * Outputs a single co-author, linked to their website if they've provided one.
 *
 * @param object $author
 * @return string
 */
function coauthors_links_single( $author ) {
	if ( 'guest-author' === $author->type && get_the_author_meta( 'website' ) ) {
		return sprintf(
			'<a href="%s" title="%s" rel="author external">%s</a>',
			esc_url( get_the_author_meta( 'website' ) ),
			/* translators: Author display name. */
			esc_attr( sprintf( __( 'Visit %s&#8217;s website', 'co-authors-plus' ), esc_html( get_the_author() ) ) ),
			esc_html( get_the_author() )
		);
	}

	if ( get_the_author_meta( 'url' ) ) {
		return sprintf(
			'<a href="%s" title="%s" rel="author external">%s</a>',
			esc_url( get_the_author_meta( 'url' ) ),
			/* translators: Author display name. */
			esc_attr( sprintf( __( 'Visit %s&#8217;s website', 'co-authors-plus' ), esc_html( get_the_author() ) ) ),
			esc_html( get_the_author() )
		);
	}

	return esc_html( get_the_author() );
}

/**
 * Outputs the co-authors IDs
 *
 * @param string $between Delimiter that should appear between the co-authors
 * @param string $betweenLast Delimiter that should appear between the last two co-authors
 * @param string $before What should appear before the presentation of co-authors
 * @param string $after What should appear after the presentation of co-authors
 * @param bool   $echo Whether the co-authors should be echoed or returned. Defaults to true.
 */
function coauthors_ids( $between = null, $betweenLast = null, $before = null, $after = null, $echo = true ) {
	return coauthors__echo(
		'ID',
		'field',
		array(
			'between'     => $between,
			'betweenLast' => $betweenLast,
			'before'      => $before,
			'after'       => $after,
		),
		null,
		$echo
	);
}

/**
 * Outputs the co-authors metadata.
 *
 * @param string $field Required The user field to retrieve.[login, email, nicename, display_name, url, type]
 * @param string $user_id Optional The user ID for meta
 *
 * @return array $meta Value of the user field
 */
function get_the_coauthor_meta( $field, $user_id = false ) {
	global $coauthors_plus;

	if ( ! $user_id ) {
		$coauthors = get_coauthors();
	} else {
		$coauthor_data = $coauthors_plus->get_coauthor_by( 'id', $user_id );
		$coauthors     = array();
		if ( ! empty( $coauthor_data ) ) {
			$coauthors[] = $coauthor_data;
		}
	}

	$meta = array();

	if ( in_array( $field, array( 'login', 'pass', 'nicename', 'email', 'url', 'registered', 'activation_key', 'status' ) ) ) {
		$field = 'user_' . $field;
	}

	foreach ( $coauthors as $coauthor ) {
		$user_id = $coauthor->ID;

		if ( isset( $coauthor->type ) && 'user_url' === $field ) {
			if ( 'guest-author' === $coauthor->type ) {
				$field = 'website';
			}
		} elseif ( 'website' === $field ) {
			$field = 'user_url';
		}

		if ( isset( $coauthor->$field ) ) {
			$meta[ $user_id ] = $coauthor->$field;
		} else {
			$meta[ $user_id ] = '';
		}
	}

	return $meta;
}


function the_coauthor_meta( $field, $user_id = 0 ) {
	// TODO: need before after options
	$coauthor_meta = get_the_coauthor_meta( $field, $user_id );
	foreach ( $coauthor_meta as $meta ) {
		echo esc_html( $meta );
	}
}

/**
 * Returns an array of blog users and co-authors.
 * @param array $args An argument array to customize the returned result.
 *      number (int) (20): The maximum number of (co-)authors to return.
 *      guest_authors_only (boolean) (false): If true, include only guest authors without WP users.
 *      authors_with_posts_only (boolean) (false): If true, don't query for authors with no posts.
 *      orderby (string) ('name'): A field to order the authors by {@see WP_Term_Query::__construct()}
 *
 * @return array A unique array of WP_User-like objects each containing data for a use or a co-author.
 *      The returned array may contain a mix of native WP users as well as guest authors as
 *      designated by $args. You can use the $user->type property to check for the user type.
 */
function coauthors_get_users( $args = array() ) {
	global $coauthors_plus;

	$defaults = array(
		'number'                  => 20, // A sane limit to start to avoid breaking all the things
		'guest_authors_only'      => false,
		'authors_with_posts_only' => false,
		'orderby'                 => 'name',
	);
	$args   = wp_parse_args( $args, $defaults );

	$term_args    = array(
		'orderby'    => $args['orderby'],
		'number'     => (int) $args['number'],
		/*
		 * Historically, this was set to always be `0` ignoring `$args['hide_empty']` value
		 * To avoid any backwards incompatibility, inventing `authors_with_posts_only` that defaults to false
		 */
		'hide_empty' => (bool) $args['authors_with_posts_only'],
	);
	$author_terms = get_terms( $coauthors_plus->coauthor_taxonomy, $term_args );

	$authors = array();
	foreach ( $author_terms as $author_term ) {
		// Something's wrong in the state of Denmark
		if ( false === ( $coauthor = $coauthors_plus->get_coauthor_by( 'user_login', $author_term->name ) ) ) {
			continue;
		}

		$authors[ $author_term->name ] = $coauthor;

		// only show guest authors if the $args is set to true
		if ( ! $args['guest_authors_only'] || $authors[ $author_term->name ]->type === 'guest-author' ) {
			$authors[ $author_term->name ]->post_count = $author_term->count;
		} else {
			unset( $authors[ $author_term->name ] );
		}
	}
	$authors = apply_filters( 'coauthors_wp_list_authors_array', $authors );

	// remove duplicates from linked accounts
	$linked_accounts = array_unique( array_column( $authors, 'linked_account' ) );
	foreach ( $linked_accounts as $linked_account ) {
		unset( $authors[ $linked_account ] );
	}

	return $authors;
}

/**
 * List all the *co-authors* of the blog, with several options available.
 * optioncount (boolean) (false): Show the count in parentheses next to the author's name.
 * show_fullname (boolean) (false): Show their full names.
 * hide_empty (boolean) (true): Don't show authors without any posts.
 * feed (string) (''): If isn't empty, show links to author's feeds.
 * feed_image (string) (''): If isn't empty, use this image to link to feeds.
 * echo (boolean) (true): False to return the output, true to echo.
 * authors_with_posts_only (boolean) (false): If true, don't query for authors with no posts.
 *
 * @param array $args The argument array.
 * @return null|string The output, if echo is set to false.
 */
function coauthors_wp_list_authors( $args = array() ) {
	$defaults = array(
		'optioncount'             => false,
		'show_fullname'           => false,
		'hide_empty'              => true,
		'feed'                    => '',
		'feed_image'              => '',
		'feed_type'               => '',
		'echo'                    => true,
		'style'                   => 'list',
		'html'                    => true,
		'number'                  => 20, // A sane limit to start to avoid breaking all the things
		'guest_authors_only'      => false,
		'authors_with_posts_only' => false,
		'orderby'                 => 'name',
	);
	$args   = wp_parse_args( $args, $defaults );

	$return = '';

	$authors = coauthors_get_users( $args );
	foreach ( $authors as $author ) {

		$link = '';

		if ( $args['show_fullname'] && ( $author->first_name && $author->last_name ) ) {
			$name = "$author->first_name $author->last_name";
		} else {
			$name = $author->display_name;
		}

		if ( ! $args['html'] ) {
			if ( 0 === $author->post_count ) {
				if ( ! $args['hide_empty'] ) {
					$return .= $name . ', ';
				}
			} else {
				$return .= $name . ', ';
			}

			// No need to go further to process HTML.
			continue;
		}

		if ( ! ( 0 === $author->post_count && $args['hide_empty'] ) && 'list' == $args['style'] ) {
			$return .= '<li>';
		}
		if ( 0 === $author->post_count ) {
			if ( ! $args['hide_empty'] ) {
				$link = $name;
			}
		} else {
			/* translators: Author display name. */
			$link = '<a href="' . get_author_posts_url( $author->ID, $author->user_nicename ) . '" title="' . esc_attr( sprintf( __( 'Posts by %s', 'co-authors-plus' ), $name ) ) . '">' . esc_html( $name ) . '</a>';

			if ( ( ! empty( $args['feed_image'] ) ) || ( ! empty( $args['feed'] ) ) ) {
				$link .= ' ';
				if ( empty( $args['feed_image'] ) ) {
					$link .= '(';
				}
				$link .= '<a href="' . esc_url( get_author_feed_link( $author->ID, $args['feed_type'] ) ) . '"';

				$alt   = '';
				$title = '';

				if ( ! empty( $args['feed'] ) ) {

					$title = ' title="' . esc_attr( $args['feed'] ) . '"';
					$alt   = ' alt="' . esc_attr( $args['feed'] ) . '"';
					$name  = $args['feed'];
					$link .= $title;
				}

				$link .= '>';

				if ( ! empty( $args['feed_image'] ) ) {
					$link .= '<img src="' . esc_url( $args['feed_image'] ) . "\" style=\"border: none;\"$alt$title" . ' />';
				} else {
					$link .= $name;
				}

				$link .= '</a>';

				if ( empty( $args['feed_image'] ) ) {
					$link .= ')';
				}
			}

			if ( $args['optioncount'] ) {
				$link .= ' (' . $author->post_count . ')';
			}
		}

		if ( ! ( 0 === $author->post_count && $args['hide_empty'] ) && 'list' == $args['style'] ) {
			$return .= $link . '</li>';
		} elseif ( ! $args['hide_empty'] ) {
			$return .= $link . ', ';
		}
	}

	$return = trim( $return, ', ' );
	if ( ! $args['echo'] ) {
		return $return;
	}

	echo $return; // phpcs:ignore
}

/**
 * Retrieve a Co-Author's Avatar.
 *
 * Since Guest Authors doesn't enforce unique email addresses, simply loading the avatar by email won't work when
 * multiple Guest Authors share the same address.
 *
 * This is a replacement for using get_avatar(), which only operates on email addresses and cannot differentiate
 * between Guest Authors (who may share an email) and regular user accounts
 *
 * @param  object       $coauthor The Co Author or Guest Author object.
 * @param  int          $size     The desired size.
 * @param  string       $default  Optional. URL for the default image or a default type. Accepts '404'
 *                                (return a 404 instead of a default image), 'retro' (8bit), 'monsterid'
 *                                (monster), 'wavatar' (cartoon face), 'indenticon' (the "quilt"),
 *                                'mystery', 'mm', or 'mysteryman' (The Oyster Man), 'blank' (transparent GIF),
 *                                or 'gravatar_default' (the Gravatar logo). Default is the value of the
 *                                'avatar_default' option, with a fallback of 'mystery'.
 * @param  string       $alt      Optional. Alternative text to use in &lt;img&gt; tag. Default false.
 * @param  array|string $class    Optional. Array or string of additional classes to add to the &lt;img&gt; element. Default null.
 * @return string                  The image tag for the avatar, or an empty string if none could be determined.
 */
function coauthors_get_avatar( $coauthor, $size = 32, $default = '', $alt = false, $class = null ) {
	global $coauthors_plus;

	if ( ! is_object( $coauthor ) ) {
		return '';
	}

	if ( isset( $coauthor->type ) && 'guest-author' == $coauthor->type ) {
		$guest_author_thumbnail = $coauthors_plus->guest_authors->get_guest_author_thumbnail( $coauthor, $size, $class );

		if ( $guest_author_thumbnail ) {
			return $guest_author_thumbnail;
		}
	}

	// Make sure we're dealing with an object for which we can retrieve an email
	if ( isset( $coauthor->user_email ) ) {
		return get_avatar( $coauthor->user_email, $size, $default, $alt, array( 'class' => $class ) );
	}

	// Nothing matched, an invalid object was passed.
	return '';
}
