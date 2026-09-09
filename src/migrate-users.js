/**
 * Drives the batched user migration on the Migrate Users admin page.
 *
 * @package
 */

import { _n, sprintf } from '@wordpress/i18n';

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
			progress.hidden = false;
			result.hidden = true;
			errorNotice.hidden = true;

			let offset = 0;
			let created = 0;
			let skipped = 0;
			let failed = 0;

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

						created += response.data.created || 0;
						skipped += response.data.skipped || 0;
						failed += response.data.failed || 0;

						if ( response.data.done ) {
							result.textContent = buildResultMessage( {
								created,
								skipped,
								failed,
							} );
							result.hidden = false;
							button.disabled = false;
							return;
						}

						const previousOffset = offset;
						if ( 'undefined' !== typeof response.data.offset ) {
							offset = response.data.offset;
						}
						if ( offset <= previousOffset ) {
							// Stop instead of repeating the same batch.
							throw new Error( 'Migration stalled.' );
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
							progressText.textContent = sprintf(
								// translators: %d: number of users left to process.
								_n(
									'%d user remaining.',
									'%d users remaining.',
									response.data.remaining || 0,
									'co-authors-plus'
								),
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

	/**
	 * Build the completion message from the actual batch results.
	 *
	 * @param {Object} data Final migration payload from the server.
	 * @return {string} Complete message for the result region.
	 */
	function buildResultMessage( data ) {
		const parts = [];

		parts.push(
			sprintf(
				// translators: %d: number of guest author profiles created.
				_n(
					'%d guest author profile created.',
					'%d guest author profiles created.',
					data.created || 0,
					'co-authors-plus'
				),
				data.created || 0
			)
		);

		if ( data.skipped > 0 ) {
			parts.push(
				sprintf(
					// translators: %d: number of users that already had a profile.
					_n(
						'%d user already had a profile.',
						'%d users already had a profile.',
						data.skipped,
						'co-authors-plus'
					),
					data.skipped
				)
			);
		}

		if ( data.failed > 0 ) {
			parts.push(
				sprintf(
					// translators: %d: number of users that could not be migrated.
					_n(
						'%d user could not be migrated.',
						'%d users could not be migrated.',
						data.failed,
						'co-authors-plus'
					),
					data.failed
				)
			);
		}

		return parts.join( ' ' );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
