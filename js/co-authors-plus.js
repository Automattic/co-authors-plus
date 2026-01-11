jQuery( document ).ready(function () {

	/*
	 * Click handler for the delete button
	 * @param event
	 */
	var coauthors_delete_onclick = function( e ) {
		if ( confirm( coAuthorsPlusStrings.confirm_delete ) ) {
			return coauthors_delete( this );
		}
		return false;
	};

	var $coauthors_loading = jQuery("<span id='ajax-loading'></span>");

	function coauthors_delete( elem ) {

		var $coauthor_row = jQuery( elem ).closest( '.coauthor-row' );
		$coauthor_row.remove();

		// Hide the delete button when there's only one Co-Author
		if ( jQuery( '#coauthors-list .coauthor-row .coauthor-tag' ).length <= 1 )
			jQuery( '#coauthors-list .coauthor-row .coauthors-author-options' ).addClass( 'hidden' );

		return true;
	}

	var coauthors_edit_onclick = function( event ) {
		var $tag = jQuery( this );

		var $co = $tag.prev();

		$tag.hide();
		$co.show()
			.on( 'focus' )
			;

		$co.previousAuthor = $tag.text();
	}

	/*
	 * Save co-author
	 * @param int Co-Author ID
	 * @param string Co-Author Name
	 * @param object The autosuggest input box
	 */
	function coauthors_save_coauthor( author, co ) {

		// get sibling <span> and update
		co.siblings( '.coauthor-tag' )
			.html( author.name )
			.append( coauthors_create_author_gravatar( author ) )
			.show()
			;

		// Update the value of the hidden input
		co.siblings( 'input[name="coauthors[]"]' ).val( author.nicename );
	}


	/*
	 * Add co-author
	 * @param string Co-Author Name
	 * @param object The autosuggest input box
	 * @param boolean Initial set up or not?
	 */
	function coauthors_add_coauthor( author, co, init, count ){

		// Check if editing
		if ( co && co.siblings( '.coauthor-tag' ).length ) {
			coauthors_save_coauthor( author, co );
		} else {
			// Not editing, so we create a new co-author entry
			if ( count == 0 ) {
				var coName = ( count == 0 ) ? 'coauthors-main' : '';
				// Add new co-author to <select>
				//coauthors_select_author( co-author );
			}
			var options = { addDelete: true, addEdit: false };

			// Create autosuggest box and text tag
			if ( ! co ) var co = coauthors_create_autosuggest( author.name, coName )
			var tag = coauthors_create_author_tag( author );
			var input = coauthors_create_author_hidden_input( author );
			var $gravatar = coauthors_create_author_gravatar( author );

			tag.append( $gravatar );

			coauthors_add_to_table( co, tag, input, options );

			if ( ! init ) {
				// Create new author-suggest and append it to a new row
				var newCO = coauthors_create_autosuggest( '', false );
				coauthors_add_to_table( newCO );
				move_loading( newCO );
			}
		}

		co.on( 'blur', coauthors_stop_editing );

		// Set the value for the auto-suggest box to the co-author's name and hide it
		// unescape() is deprecated, so replacing it with decodeURIComponent() here and every places.
		co.val( decodeURIComponent( author.name ) )
			.hide()
			.off( 'focus' )
			;

		return true;
	}


	/*
	 * Add the autosuggest box and text tag to the Co-Authors table
	 * @param object Autosuggest input box
	 * @param object Text tag
	 * @param
	 */
	function coauthors_add_to_table( co, tag, input, options ) {
		if ( co ) {
			var $div = jQuery( '<div/>' )
						.addClass( 'suggest' )
						.addClass( 'coauthor-row' )
						.append( co )
						.append( tag )
						.append( input )
						;

			//Add buttons to row
			if ( tag ) coauthors_insert_author_edit_cells( $div, options );

			jQuery( '#coauthors-list' ).append( $div );
		}
	}

	/*
	 * Adds a delete and edit button next to a co-author
	 * @param object The row to which the new co-author should be added
	 */
	function coauthors_insert_author_edit_cells( $div, options ){

		var $options = jQuery( '<div/>' )
			.addClass( 'coauthors-author-options' )
			;

		if ( options.addDelete ) {
			var deleteBtn = jQuery( '<span/>' )
								.addClass( 'delete-coauthor' )
								.text( coAuthorsPlusStrings.delete_label )
								.on( 'click', coauthors_delete_onclick )
								;
			$options.append( deleteBtn );
		}

		$div.append( $options );
		return $div;
	}

	/*
	 * Creates autosuggest input box
	 * @param string [optional] Name of the co-author
	 * @param string [optional] Name to be applied to the input box
	 */
	function coauthors_create_autosuggest( authorName, inputName ) {

		if ( ! inputName ) inputName = 'coauthorsinput[]';

		var $co = jQuery( '<input/>' );

		$co.attr({
			'class': 'coauthor-suggest'
			, 'name': inputName
			})
			.appendTo( $coauthors_div )
			.suggest( coAuthorsPlus_ajax_suggest_link, {
				onSelect: coauthors_autosuggest_select,
				delay: 1000
			})
			.on( 'keydown', coauthors_autosuggest_keydown )
			;

		if ( authorName )
			$co.attr( 'value', decodeURIComponent( authorName ) );
		else
			$co.attr( 'value', coAuthorsPlusStrings.search_box_text )
				.on( 'focus', function(){ $co.val( '' ) } )
				.on( 'blur', function(){ $co.val( coAuthorsPlusStrings.search_box_text ) } )
				;

		if ( coauthors_initialized_on_bulk_edit )
			$co.attr({
				'aria-labelledby': 'coauthors-bulk-edit-label',
				'aria-describedby': 'coauthors-bulk-edit-desc'
			})
			;

		return $co;

	}

	// Callback for when a user selects a co-author
	function coauthors_autosuggest_select() {
		$this = jQuery( this );
		var vals = this.value.split( '∣' );

		var author = {}
		author.id = vals[0].trim();
		author.login = vals[1].trim();
		author.name = vals[2].trim();
		author.email = vals[3].trim();
		if( author.avatar !== '' ){
			author.avatar = vals[5].trim();
		}

		// Decode user-nicename if it has special characters in it.
		author.nicename = decodeURIComponent( vals[4].trim() );

		if ( author.id=='New' ) {
			coauthors_new_author_display( name );
		} else {
			coauthors_add_coauthor( author, $this );
			// Show the delete button if we now have more than one co-author
			if ( jQuery( '#coauthors-list .coauthor-row .coauthor-tag' ).length > 1 )
				jQuery( '#coauthors-list .coauthor-row .coauthors-author-options' ).removeClass( 'hidden' );
		}
	}

	// Prevent the enter key from triggering a submit
	function coauthors_autosuggest_keydown( e ) {
		if ( e.keyCode == 13 ) {return false;}
	}

	/*
	 * Blur handler for autosuggest input box
	 * @param event
	 */
	function coauthors_stop_editing( event ) {

		var co = jQuery( this );
		var tag = jQuery( co.next() );

		co.attr( 'value',tag.text() );

		co.hide();
		tag.show();

	//	editing = false;
	}

	/*
	 * Creates the text tag for a co-author
	 * @param string Name of the co-author
	 */
	function coauthors_create_author_tag( author ) {

		var $tag = jQuery( '<span></span>' )
							.text( decodeURIComponent( author.name ) )
							.attr( 'title', coAuthorsPlusStrings.input_box_title )
							.addClass( 'coauthor-tag' )
							// Add Click event to edit
							.on( 'click', coauthors_edit_onclick );
		return $tag;
	}

	function coauthors_create_author_gravatar( author ) {

		var $gravatar = jQuery( '<img/>' )
							.attr( 'alt', author.name )
							.attr( 'src', author.avatar )
							.addClass( 'coauthor-gravatar' )
							;
		return $gravatar;
	}

	/*
	 * Creates the text tag for a co-author
	 * @param string Name of the co-author
	 */
	function coauthors_create_author_hidden_input ( author ) {
		var input = jQuery( '<input />' )
						.attr({
							'type': 'hidden',
							'id': 'coauthors_hidden_input',
							'name': 'coauthors[]',
							'value': decodeURIComponent( author.nicename )
							})
						;

		return input;
	}

	var $coauthors_div = null;

	/**
	 * Initialize the Coauthors UI.
	 *
	 * @param array List of coauthors objects.
	 *  Each coauthor object should have the (string) properties:
	 *    login
	 *    email
	 *    name
	 *    nicename
	 */
	function coauthors_initialize( post_coauthors ) {
		// Add the controls to add co-authors

		$coauthors_div = jQuery( '#coauthors-edit' );

		if ( $coauthors_div.length ) {
			// Create the co-authors table
			var table = jQuery( '<div/>' )
				.attr( 'id', 'coauthors-list' )
				;
			$coauthors_div.append( table );
		}

		// Select co-authors already added to the post
		var addedAlready = [];
		//jQuery('#the-list tr').each(function(){
		var count = 0;
		jQuery.each( post_coauthors, function() {
			coauthors_add_coauthor( this, undefined, true, count );
			count++;
		});

		// Hide the delete button if there's only one co-author
		if ( jQuery( '#coauthors-list .coauthor-row .coauthor-tag' ).length < 2 )
			jQuery( '#coauthors-list .coauthor-row .coauthors-author-options' ).addClass( 'hidden' );


		// Create new author-suggest and append it to a new row
		var newCO = coauthors_create_autosuggest( '', false );
		coauthors_add_to_table( newCO );

		$coauthors_loading = jQuery( '#publishing-action .spinner' ).clone().attr( 'id', 'coauthors-loading' );
		move_loading( newCO );


		// Make co-authors sortable so an editor can control the order of the co-authors
		jQuery( '#coauthors-list' ).sortable({
			axis: 'y',
			handle: '.coauthor-tag',
			placeholder: 'ui-state-highlight',
			items: 'div.coauthor-row:not(div.coauthor-row:last)',
			containment: 'parent',
		});

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
	// Show loading cursor for autocomplete ajax requests
	jQuery( document ).ajaxSend(function( e, xhr, settings ) {
		if ( settings.url.indexOf( coAuthorsPlus_ajax_suggest_link ) != -1 ) {
			// Including existing authors on the AJAX suggest link
			// allows us to filter them out of the search request
			var existing_authors = jQuery( 'input[name="coauthors[]"]' ).map(function(){return jQuery( this ).val();}).get();
			settings.url = settings.url.split( '&existing_authors' )[0];
			settings.url += '&existing_authors=' + existing_authors.join( ',' );
			show_loading();
		}
	});
	// Hide loading cursor when autocomplete ajax requests are finished
	jQuery( document ).ajaxComplete(function( e, xhr, settings ) {
		if ( settings.url.indexOf( coAuthorsPlus_ajax_suggest_link ) != -1 )
			hide_loading();
	});

	if ( 'post-php' == adminpage || 'post-new-php' == adminpage ) {
		var $post_coauthor_logins = jQuery( 'input[name="coauthors[]"]' );
		var $post_coauthor_names = jQuery( 'input[name="coauthorsinput[]"]' );
		var $post_coauthor_emails = jQuery( 'input[name="coauthorsemails[]"]' );
		var $post_coauthor_nicenames = jQuery( 'input[name="coauthorsnicenames[]"]' );
		var $post_coauthoravatars = jQuery( 'input[name="coauthorsavatars[]"]' );

		var post_coauthors = [];

		for ( var i = 0; i < $post_coauthor_logins.length; i++ ) {
			post_coauthors.push({
				login: $post_coauthor_logins[i].value,
				name: $post_coauthor_names[i].value,
				email: $post_coauthor_emails[i].value,
				nicename: $post_coauthor_nicenames[i].value,
				avatar: $post_coauthoravatars[i].value,
			});
		}

		// Remove the read-only co-authors so we don't get craziness
		jQuery( '#coauthors-readonly' ).remove();
		coauthors_initialize( post_coauthors );
	}
	else if ( 'edit-php' == adminpage ) {

		var wpInlineEdit = inlineEditPost.edit;

		// Inline editing
		inlineEditPost.edit = function( id ) {

			wpInlineEdit.apply( this, arguments )

			// get the post ID
			var postId = 0
			if ( typeof( id ) == 'object' )
				postId = parseInt( this.getId( id ) )

			if ( postId > 0 ) {

				var $postRow = jQuery( '#post-' + postId )

				// Move the element to the appropriate position in the view
				// JS hack for core bug: https://core.trac.wordpress.org/ticket/26982
				jQuery( '.quick-edit-row .inline-edit-col-left .inline-edit-col' ).find( '.inline-edit-coauthors' ).remove() // remove any previously added elements
				var el = jQuery( '.inline-edit-group.inline-edit-coauthors', '#edit-' + postId );
				el.detach().appendTo( '.quick-edit-row .inline-edit-col-left .inline-edit-col' ).show();

				// initialize co-authors
				var post_coauthors = jQuery.map( jQuery( '.column-coauthors a', $postRow ), function( el ) {
					return {
						login: jQuery( el ).data( 'user_login' ),
						name: jQuery( el ).data( 'display_name' ),
						email: jQuery( el ).data( 'user_email' ),
						nicename: jQuery( el ).data( 'user_nicename' ),
						avatar: jQuery( el ).data( 'avatar' ),
					}
				});

				coauthors_initialize( post_coauthors );

			}
		}

		// Bulk editing
		var coauthors_initialized_on_bulk_edit = false;
		var wpBulkEdit = inlineEditPost.setBulk;

		inlineEditPost.setBulk = function() {

			wpBulkEdit.apply( this, arguments );

			// Initialize co-authors, but only on the first 'Bulk edit' interaction.
			if ( ! coauthors_initialized_on_bulk_edit ) {
				var bulk_right_column = jQuery( '#bulk-edit .inline-edit-col-right' );
				var coauthors_authors_label = jQuery( '#bulk-edit .bulk-edit-coauthors' );

				// Move the Co-Authors section to the right-hand column of the Bulk section.
				coauthors_authors_label.appendTo( bulk_right_column );
				// Give the right-hand column its 'real' height because of float:left;
				// The Post Format dropdown does not help positioning the Co-Authors section.
				bulk_right_column.find( 'div.inline-edit-col' ).addClass( 'wp-clearfix' );
				// Move the autosuggest input box under the Co-Authors label.
				jQuery( '#coauthors-edit' ).appendTo( coauthors_authors_label );

				coauthors_initialized_on_bulk_edit = true;
				coauthors_initialize( [] );
			}
		}
	}

});

if ( typeof( console ) === 'undefined' ) {
	var console = {}
	console.log = console.error = function() {};
}
