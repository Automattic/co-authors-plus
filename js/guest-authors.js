jQuery( document ).ready( function( $ ) {
	$( '.reassign-option' ).on( 'click', function() {
		$( '#wpbody-content input#submit' ).addClass( 'button-primary' ).removeAttr( 'disabled' );
	} );

	// Initialize the co-author suggest for reassignment.
	$( '#leave-assigned-to' ).suggest( coAuthorsGuestAuthors.ajaxUrl, {
		minchars: 2,
		delay: 500,
		onSelect: function() {
			var $this = $( this );
			var parts = this.value.split( '∣' );

			if ( parts.length >= 2 ) {
				// Store the user_nicename as the value (used for form submission).
				$this.val( parts[1].trim() );
			}

			// Auto-select the "Reassign to another co-author" radio option.
			$( '#reassign-another' ).trigger( 'click' );
		}
	} );
} );
