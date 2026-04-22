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
 * @param {Array} termIds Array of taxonomy term IDs from the core entity store.
 * @return {Object} Object with `authors` (array of rich author objects) and `isLoading` (boolean).
 */
export default function useCoauthorDetails( termIds ) {
	const [ authors, setAuthors ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const cache = useRef( new Map() );

	useEffect( () => {
		if ( ! termIds || ! termIds.length ) {
			setAuthors( [] );
			return;
		}

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

		let cancelled = false;
		setIsLoading( true );

		apiFetch( {
			path: `/coauthors/v1/authors-by-term-ids?ids=${ uncachedIds.join( ',' ) }`,
			method: 'GET',
		} )
			.then( ( results ) => {
				if ( cancelled ) {
					return;
				}

				results.forEach( ( author ) => {
					const formatted = formatAuthorData( author );
					cache.current.set( formatted.termId, formatted );
				} );

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
	}, [ JSON.stringify( termIds ) ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return { authors, isLoading };
}
