jQuery( document ).ready( function( $ ) {

	/*
	 * Click handler for the delete button
	 */
	var coauthors_delete_onclick = function() {
		if ( confirm( coAuthorsPlusStrings.confirm_delete ) ) {
			return coauthors_delete( this );
		}
		return false;
	};

	var $coauthors_loading = $( '<span id="ajax-loading"></span>' );

	function coauthors_delete( elem ) {
		var $coauthor_row = $( elem ).closest( '.coauthor-row' );
		$coauthor_row.remove();

		// Hide the delete button when there's only one Co-Author
		if ( $( '#coauthors-list .coauthor-row .coauthor-tag' ).length <= 1 ) {
			$( '#coauthors-list .coauthor-row .coauthors-author-options' ).addClass( 'hidden' );
		}

		return true;
	}

	var coauthors_edit_onclick = function() {
		var $tag = $( this );
		var $co = $tag.prev();

		$tag.hide();
		$co.show().trigger( 'focus' );
		$co.data( 'previousAuthor', $tag.text() );
	};

	/*
	 * Save co-author (when editing an existing one)
	 */
	function coauthors_save_coauthor( author, $co ) {
		// Get sibling <span> and update
		$co.siblings( '.coauthor-tag' )
			.html( author.label )
			.append( coauthors_create_author_gravatar( author ) )
			.show();

		// Update the value of the hidden input
		$co.siblings( 'input[name="coauthors[]"]' ).val( author.nicename );
	}

	/*
	 * Add co-author
	 */
	function coauthors_add_coauthor( author, $co, init, count ) {
		// Check if editing
		if ( $co && $co.siblings( '.coauthor-tag' ).length ) {
			coauthors_save_coauthor( author, $co );
		} else {
			// Not editing, so we create a new co-author entry
			var coName = ( count === 0 ) ? 'coauthors-main' : '';
			var options = { addDelete: true, addEdit: false };

			// Create autocomplete box and text tag
			if ( ! $co ) {
				$co = coauthors_create_autocomplete( author.label, coName );
			}
			var $tag = coauthors_create_author_tag( author );
			var $input = coauthors_create_author_hidden_input( author );
			var $gravatar = coauthors_create_author_gravatar( author );

			$tag.append( $gravatar );
			coauthors_add_to_table( $co, $tag, $input, options );

			if ( ! init ) {
				// Create new autocomplete and append it to a new row
				var $newCO = coauthors_create_autocomplete( '', false );
				coauthors_add_to_table( $newCO );
				move_loading( $newCO );
			}
		}

		$co.on( 'blur', coauthors_stop_editing );

		// Set the value for the autocomplete box to the co-author's name and hide it
		$co.val( decodeURIComponent( author.label || author.name ) ).hide().off( 'focus' );

		return true;
	}

	/*
	 * Add the autocomplete box and text tag to the Co-Authors table
	 */
	function coauthors_add_to_table( $co, $tag, $input, options ) {
		if ( $co ) {
			var $div = $( '<div/>' )
				.addClass( 'suggest' )
				.addClass( 'coauthor-row' )
				.append( $co )
				.append( $tag )
				.append( $input );

			// Add buttons to row
			if ( $tag ) {
				coauthors_insert_author_edit_cells( $div, options );
			}

			$( '#coauthors-list' ).append( $div );
		}
	}

	/*
	 * Adds a delete button next to a co-author
	 */
	function coauthors_insert_author_edit_cells( $div, options ) {
		var $options = $( '<div/>' ).addClass( 'coauthors-author-options' );

		if ( options.addDelete ) {
			var $deleteBtn = $( '<span/>' )
				.addClass( 'delete-coauthor' )
				.text( coAuthorsPlusStrings.delete_label )
				.on( 'click', coauthors_delete_onclick );
			$options.append( $deleteBtn );
		}

		$div.append( $options );
		return $div;
	}

	/*
	 * Creates autocomplete input box
	 */
	function coauthors_create_autocomplete( authorName, inputName ) {
		if ( ! inputName ) {
			inputName = 'coauthorsinput[]';
		}

		var $co = $( '<input/>' ).attr( {
			'class': 'coauthor-suggest',
			'name': inputName
		} ).appendTo( $coauthors_div );

		// Build the AJAX URL with existing authors filter
		var getAutocompleteUrl = function() {
			var existing = $( 'input[name="coauthors[]"]' ).map( function() {
				return $( this ).val();
			} ).get();
			return coAuthorsPlus_ajax_suggest_link + '&existing_authors=' + existing.join( ',' );
		};

		$co.autocomplete( {
			source: function( request, response ) {
				show_loading();
				$.getJSON( getAutocompleteUrl(), { term: request.term }, function( data ) {
					hide_loading();
					response( data );
				} );
			},
			minLength: 1,
			delay: 500,
			select: function( event, ui ) {
				coauthors_add_coauthor( ui.item, $co );

				// Show the delete button if we now have more than one co-author
				if ( $( '#coauthors-list .coauthor-row .coauthor-tag' ).length > 1 ) {
					$( '#coauthors-list .coauthor-row .coauthors-author-options' ).removeClass( 'hidden' );
				}

				return false;
			},
			focus: function( event, ui ) {
				$co.val( ui.item.label );
				return false;
			}
		} );

		// Custom rendering to show avatar alongside name
		var autocompleteInstance = $co.data( 'ui-autocomplete' );
		if ( autocompleteInstance ) {
			autocompleteInstance._renderItem = function( ul, item ) {
				var $li = $( '<li>' );
				var $wrapper = $( '<div>' ).addClass( 'coauthor-autocomplete-item' );

				if ( item.avatar ) {
					$wrapper.append( $( '<img>' ).attr( 'src', item.avatar ).addClass( 'coauthor-autocomplete-avatar' ) );
				}
				$wrapper.append( $( '<span>' ).text( item.label ) );

				return $li.append( $wrapper ).appendTo( ul );
			};
		}

		$co.on( 'keydown', function( e ) {
			// Prevent enter key from submitting form
			if ( e.keyCode === 13 ) {
				return false;
			}
		} );

		if ( authorName ) {
			$co.val( decodeURIComponent( authorName ) );
		} else {
			$co.val( coAuthorsPlusStrings.search_box_text )
				.on( 'focus', function() { $co.val( '' ); } )
				.on( 'blur', function() {
					if ( $co.val() === '' ) {
						$co.val( coAuthorsPlusStrings.search_box_text );
					}
				} );
		}

		if ( coauthors_initialized_on_bulk_edit ) {
			$co.attr( {
				'aria-labelledby': 'coauthors-bulk-edit-label',
				'aria-describedby': 'coauthors-bulk-edit-desc'
			} );
		}

		return $co;
	}

	/*
	 * Blur handler for autocomplete input box
	 */
	function coauthors_stop_editing() {
		var $co = $( this );
		var $tag = $co.next();

		$co.val( $tag.text() );
		$co.hide();
		$tag.show();
	}

	/*
	 * Creates the text tag for a co-author
	 */
	function coauthors_create_author_tag( author ) {
		var displayName = author.label || author.name;
		return $( '<span></span>' )
			.text( decodeURIComponent( displayName ) )
			.attr( 'title', coAuthorsPlusStrings.input_box_title )
			.addClass( 'coauthor-tag' )
			.on( 'click', coauthors_edit_onclick );
	}

	function coauthors_create_author_gravatar( author ) {
		return $( '<img/>' )
			.attr( 'alt', author.label || author.name )
			.attr( 'src', author.avatar )
			.addClass( 'coauthor-gravatar' );
	}

	/*
	 * Creates the hidden input for a co-author
	 */
	function coauthors_create_author_hidden_input( author ) {
		return $( '<input />' ).attr( {
			'type': 'hidden',
			'id': 'coauthors_hidden_input',
			'name': 'coauthors[]',
			'value': decodeURIComponent( author.nicename )
		} );
	}

	var $coauthors_div = null;
	var coauthors_initialized_on_bulk_edit = false;

	/**
	 * Initialize the Coauthors UI.
	 */
	function coauthors_initialize( post_coauthors ) {
		$coauthors_div = $( '#coauthors-edit' );

		if ( $coauthors_div.length ) {
			var $table = $( '<div/>' ).attr( 'id', 'coauthors-list' );
			$coauthors_div.append( $table );
		}

		// Add existing co-authors
		var count = 0;
		$.each( post_coauthors, function() {
			// Map legacy property names to new format
			var author = {
				id: this.id,
				login: this.login,
				label: this.name,
				email: this.email,
				nicename: this.nicename,
				avatar: this.avatar
			};
			coauthors_add_coauthor( author, undefined, true, count );
			count++;
		} );

		// Hide the delete button if there's only one co-author
		if ( $( '#coauthors-list .coauthor-row .coauthor-tag' ).length < 2 ) {
			$( '#coauthors-list .coauthor-row .coauthors-author-options' ).addClass( 'hidden' );
		}

		// Create new autocomplete and append it to a new row
		var $newCO = coauthors_create_autocomplete( '', false );
		coauthors_add_to_table( $newCO );

		$coauthors_loading = $( '#publishing-action .spinner' ).clone().attr( 'id', 'coauthors-loading' );
		move_loading( $newCO );

		// Make co-authors sortable
		$( '#coauthors-list' ).sortable( {
			axis: 'y',
			handle: '.coauthor-tag',
			placeholder: 'ui-state-highlight',
			items: 'div.coauthor-row:not(div.coauthor-row:last)',
			containment: 'parent'
		} );
	}

	function show_loading() {
		$coauthors_loading.css( 'visibility', 'visible' );
	}

	function hide_loading() {
		$coauthors_loading.css( 'visibility', 'hidden' );
	}

	function move_loading( $input ) {
		$coauthors_loading.insertAfter( $input );
	}

	// Initialize on post edit screen
	if ( 'post-php' === adminpage || 'post-new-php' === adminpage ) {
		var $post_coauthor_logins = $( 'input[name="coauthors[]"]' );
		var $post_coauthor_names = $( 'input[name="coauthorsinput[]"]' );
		var $post_coauthor_emails = $( 'input[name="coauthorsemails[]"]' );
		var $post_coauthor_nicenames = $( 'input[name="coauthorsnicenames[]"]' );
		var $post_coauthoravatars = $( 'input[name="coauthorsavatars[]"]' );

		var post_coauthors = [];

		for ( var i = 0; i < $post_coauthor_logins.length; i++ ) {
			post_coauthors.push( {
				login: $post_coauthor_logins[ i ].value,
				name: $post_coauthor_names[ i ].value,
				email: $post_coauthor_emails[ i ].value,
				nicename: $post_coauthor_nicenames[ i ].value,
				avatar: $post_coauthoravatars[ i ].value
			} );
		}

		// Remove the read-only co-authors
		$( '#coauthors-readonly' ).remove();
		coauthors_initialize( post_coauthors );
	} else if ( 'edit-php' === adminpage ) {
		// Quick Edit
		var wpInlineEdit = inlineEditPost.edit;

		inlineEditPost.edit = function( id ) {
			wpInlineEdit.apply( this, arguments );

			var postId = 0;
			if ( typeof id === 'object' ) {
				postId = parseInt( this.getId( id ), 10 );
			}

			if ( postId > 0 ) {
				var $postRow = $( '#post-' + postId );

				// Move the element to the appropriate position
				$( '.quick-edit-row .inline-edit-col-left .inline-edit-col' ).find( '.inline-edit-coauthors' ).remove();
				var $el = $( '.inline-edit-group.inline-edit-coauthors', '#edit-' + postId );
				$el.detach().appendTo( '.quick-edit-row .inline-edit-col-left .inline-edit-col' ).show();

				// Initialize co-authors
				var post_coauthors = $.map( $( '.column-coauthors a', $postRow ), function( el ) {
					return {
						login: $( el ).data( 'user_login' ),
						name: $( el ).data( 'display_name' ),
						email: $( el ).data( 'user_email' ),
						nicename: $( el ).data( 'user_nicename' ),
						avatar: $( el ).data( 'avatar' )
					};
				} );

				coauthors_initialize( post_coauthors );
			}
		};

		// Bulk Edit
		var wpBulkEdit = inlineEditPost.setBulk;

		inlineEditPost.setBulk = function() {
			wpBulkEdit.apply( this, arguments );

			if ( ! coauthors_initialized_on_bulk_edit ) {
				var $bulk_right_column = $( '#bulk-edit .inline-edit-col-right' );
				var $coauthors_label = $( '#bulk-edit .bulk-edit-coauthors' );

				$coauthors_label.appendTo( $bulk_right_column );
				$bulk_right_column.find( 'div.inline-edit-col' ).addClass( 'wp-clearfix' );
				$( '#coauthors-edit' ).appendTo( $coauthors_label );

				coauthors_initialized_on_bulk_edit = true;
				coauthors_initialize( [] );
			}
		};
	}

} );

if ( typeof console === 'undefined' ) {
	var console = {};
	console.log = console.error = function() {};
}
