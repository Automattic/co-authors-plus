import { createReduxStore, register } from '@wordpress/data';
import { applyFilters } from '@wordpress/hooks';

register(
	createReduxStore( 'co-authors-plus/blocks', {
		reducer: ( state = window.coAuthorsBlocks ) => {
			return state;
		},
		selectors: {
			getAuthorPlaceholder: ( state ) =>
				applyFilters(
					'co-authors-plus.author-placeholder',
					state.authorPlaceholder
				),
		},
	} )
);
