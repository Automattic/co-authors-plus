/**
 * Drives the batched user migration on the Migrate Users admin page.
 *
 * @package
 */

( function () {
	if ( 'undefined' === typeof window || ! window.coAuthorsMigrateUsers ) {
		return;
	}

	const settings = window.coAuthorsMigrateUsers;

	/**
	 * Run the migration once the DOM is ready.
	 */
	function init() {
		const button = document.getElementById( 'coauthors-migrate-users' );
		const progress = document.getElementById(
			'coauthors-migrate-users-progress'
		);
		const result = document.getElementById(
			'coauthors-migrate-users-result'
		);

		if ( ! button || ! progress || ! result ) {
			return;
		}

		const progressBar = progress.querySelector(
			'.coauthors-migrate-users-progress-bar'
		);
		const progressText = progress.querySelector(
			'.coauthors-migrate-users-progress-text'
		);
		const errorNotice = progress.querySelector(
			'.coauthors-migrate-users-error'
		);

		button.addEventListener( 'click', () => {
			button.disabled = true;
			result.hidden = true;
			errorNotice.hidden = true;

			let offset = 0;

			const migrateBatch = () => {
				window
					.fetch( settings.url, {
						method: 'POST',
						credentials: 'same-origin',
						body: new URLSearchParams( {
							action: settings.action,
							_wpnonce: settings.nonce,
							offset: String( offset ),
							batch_size: '25',
						} ),
					} )
					.then( ( response ) => response.json() )
					.then( ( response ) => {
						if (
							! response ||
							! response.success ||
							! response.data
						) {
							throw new Error( 'Migration failed.' );
						}

						if ( response.data.done ) {
							result.textContent = settings.createdMessage;
							button.disabled = false;
							return;
						}

						if ( 'undefined' !== typeof response.data.offset ) {
							offset = response.data.offset;
						}

						if ( progressBar ) {
							const total = response.data.total || 0;
							const value =
								total > 0
									? Math.min(
											100,
											Math.round(
												( offset / total ) * 100
											)
									  )
									: 100;
							progressBar.value = value;
						}

						if ( progressText ) {
							progressText.textContent =
								settings.remainingMessage.replace(
									'%d',
									response.data.remaining || 0
								);
						}

						migrateBatch();
					} )
					.catch( () => {
						errorNotice.hidden = false;
						button.disabled = false;
					} );
			};

			migrateBatch();
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
