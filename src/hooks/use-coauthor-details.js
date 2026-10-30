/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useState, useEffect, useRef } from '@wordpress/element';

/**
 * Utilities
 */
import { formatAuthorData } from '../utils';

/**
 * Hook to resolve co-author taxonomy term IDs to rich author data.
 *
 * @param {Array}       termIds        Array of taxonomy term IDs from the core entity store.
 * @param {number|null} postIdFallback Post ID to resolve co-authors from when no term
 *                                     IDs are available (see `needsPostIdFallback`). Pass
 *                                     `null` to disable the fallback.
 * @return {Object} Object with `authors` (array of rich author objects) and `isLoading` (boolean).
 */
export default function useCoauthorDetails( termIds, postIdFallback = null ) {
	const [ authors, setAuthors ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const cache = useRef( new Map() );

	useEffect( () => {
		let cancelled = false;

		// Format the endpoint results and cache each by term ID, so a later
		// render that does have the term IDs resolves them from cache.
		const formatAndCache = ( results ) => {
			const formatted = results.map( ( author ) =>
				formatAuthorData( author )
			);
			formatted.forEach( ( author ) => {
				cache.current.set( author.termId, author );
			} );
			return formatted;
		};

		// Path 1: resolve known term IDs — the default for a stock install where
		// the `coauthors` entity value is the author taxonomy's term-ID array.
		if ( termIds && termIds.length ) {
			const uncachedIds = termIds.filter(
				( id ) => ! cache.current.has( id )
			);

			// If everything is cached, build the result immediately.
			if ( 0 === uncachedIds.length ) {
				setAuthors(
					termIds
						.map( ( id ) => cache.current.get( id ) )
						.filter( Boolean )
				);
				return;
			}

			setIsLoading( true );

			apiFetch( {
				path: `/coauthors/v1/authors-by-term-ids?ids=${ uncachedIds.join(
					','
				) }`,
				method: 'GET',
			} )
				.then( ( results ) => {
					if ( cancelled ) {
						return;
					}

					formatAndCache( results );

					setAuthors(
						termIds
							.map( ( id ) => cache.current.get( id ) )
							.filter( Boolean )
					);
				} )
				.catch( ( error ) => {
					if ( ! cancelled ) {
						console.error( error ); // eslint-disable-line no-console
					}
				} )
				.finally( () => {
					if ( ! cancelled ) {
						setIsLoading( false );
					}
				} );

			return () => {
				cancelled = true;
			};
		}

		// Path 2: no usable term IDs, but the post still has co-authors whose
		// stored shape we couldn't read (e.g. a third-party REST override returns
		// author objects instead of the taxonomy's term-ID array). Resolve them
		// by post ID so the panel still populates. `postIdFallback` is only set in
		// that case — see `needsPostIdFallback`.
		if ( postIdFallback ) {
			setIsLoading( true );

			apiFetch( {
				path: `/coauthors/v1/authors/${ postIdFallback }`,
				method: 'GET',
			} )
				.then( ( results ) => {
					if ( cancelled ) {
						return;
					}

					setAuthors( formatAndCache( results ) );
				} )
				.catch( ( error ) => {
					if ( ! cancelled ) {
						console.error( error ); // eslint-disable-line no-console
					}
				} )
				.finally( () => {
					if ( ! cancelled ) {
						setIsLoading( false );
					}
				} );

			return () => {
				cancelled = true;
			};
		}

		// Genuinely no co-authors.
		setAuthors( [] );

		return () => {
			cancelled = true;
		};
	}, [ JSON.stringify( termIds ), postIdFallback ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return { authors, isLoading };
}
