jQuery( document ).ready( function( $ ) {
	$( '#coauthors-migrate-users' ).on( 'click', function() {
		var button = $( this );
		var progress = $( '#coauthors-migrate-users-progress' );
		var result = $( '#coauthors-migrate-users-result' );
		var offset = 0;
		var created = 0;
		var skipped = 0;

		button.prop( 'disabled', true );
		progress.prop( 'hidden', false );

		function migrateBatch() {
			$.post( coAuthorsMigrateUsers.url, {
				action: coAuthorsMigrateUsers.action,
				_wpnonce: coAuthorsMigrateUsers.nonce,
				offset: offset,
				batch_size: 25
			} ).done( function( response ) {
				if ( ! response.success ) {
					return showError();
				}
				var data = response.data;
				created += data.created;
				skipped += data.skipped;
				offset = data.offset;
				progress.find( 'progress' ).val( data.total ? ( offset / data.total ) * 100 : 100 );
				progress.find( 'span' ).text( data.remaining + ' users remaining.' );
				if ( data.done ) {
					result.prop( 'hidden', false ).text( created + ' guest authors created. ' + skipped + ' users skipped.' );
					button.prop( 'disabled', false );
					return;
				}
				migrateBatch();
			} ).fail( showError );
		}

		function showError() {
			progress.find( '.notice-error' ).prop( 'hidden', false ).text( 'Migration failed. Please try again.' );
			button.prop( 'disabled', false );
		}

		migrateBatch();
	} );
} );
