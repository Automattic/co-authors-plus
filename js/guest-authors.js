jQuery( document ).ready( function( $ ) {
	$( '.reassign-option' ).on( 'click', function() {
		$( '#wpbody-content input#submit' ).addClass( 'button-primary' ).removeAttr( 'disabled' );
	} );

	// Initialize the co-author autocomplete for reassignment.
	$( '#leave-assigned-to-display' ).autocomplete( {
		source: coAuthorsGuestAuthors.ajaxUrl,
		minLength: 2,
		delay: 500,
		select: function( event, ui ) {
			// Show the display name in the visible field.
			$( this ).val( ui.item.label );

			// Store the user_nicename in the hidden field for form submission.
			$( '#leave-assigned-to' ).val( ui.item.value );

			// Auto-select the "Reassign to another co-author" radio option.
			$( '#reassign-another' ).trigger( 'click' );

			return false;
		},
		focus: function( event, ui ) {
			// Show the display name while navigating options.
			$( this ).val( ui.item.label );
			return false;
		}
	} );
} );
