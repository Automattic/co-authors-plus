import {
	getMediaDimensions,
	getMediaSrc,
	getPlaceholderImageDimensions,
	getSizeKeysIntersection,
	getAvailableSizeSlug,
} from '../blocks/block-coauthor-image/utils';

/**
 * A media object mirrors the REST attachment shape: `media_details.sizes`
 * is keyed by size slug and each entry carries width/height/source_url.
 */
const media = {
	media_details: {
		sizes: {
			full: {
				width: 1000,
				height: 500,
				source_url: 'https://example.com/full.jpg',
			},
			thumbnail: {
				width: 150,
				height: 150,
				source_url: 'https://example.com/thumb.jpg',
			},
			medium: {
				width: 300,
				height: 150,
				source_url: 'https://example.com/medium.jpg',
			},
		},
	},
};

/**
 * `imageDimensions` comes from the block editor settings and is keyed by
 * size slug, each entry carrying the registered width/height/crop.
 */
const imageDimensions = {
	thumbnail: { width: 150, height: 150, crop: true },
	medium: { width: 300, height: 300, crop: false },
	large: { width: 1024, height: 1024, crop: false },
	wide: { width: 400, height: 200, crop: false },
	tall: { width: 200, height: 400, crop: false },
};

describe( 'Utility - getMediaDimensions', () => {
	it( 'returns an empty object when there is no media', () => {
		expect(
			getMediaDimensions( null, imageDimensions, 'full' )
		).toStrictEqual( {} );
		expect(
			getMediaDimensions( undefined, imageDimensions, 'full' )
		).toStrictEqual( {} );
	} );

	it( 'returns the media size dimensions verbatim for the "full" slug', () => {
		expect(
			getMediaDimensions( media, imageDimensions, 'full' )
		).toStrictEqual( { width: 1000, height: 500 } );
	} );

	it( 'returns the registered dimensions when the size is cropped', () => {
		expect(
			getMediaDimensions( media, imageDimensions, 'thumbnail' )
		).toStrictEqual( { width: 150, height: 150 } );
	} );

	it( 'returns the registered dimensions when width equals height', () => {
		// `medium` is not cropped but is registered square (300x300).
		expect(
			getMediaDimensions( media, imageDimensions, 'medium' )
		).toStrictEqual( { width: 300, height: 300 } );
	} );

	it( 'scales by media aspect ratio when registered width > height', () => {
		const wideMedia = {
			media_details: {
				sizes: {
					full: { width: 1000, height: 500 },
					wide: { width: 300, height: 150, source_url: 'x' },
				},
			},
		};
		// media wide is 300x150 → aspect ratio 2. Registered wide is 400x200.
		// width is kept (400), height derived: 400 / 2 = 200.
		expect(
			getMediaDimensions( wideMedia, imageDimensions, 'wide' )
		).toStrictEqual( { width: 400, height: 200 } );
	} );

	it( 'scales by media aspect ratio when registered height >= width', () => {
		// media tall is missing, so it falls back to using the media size that
		// matches the slug. Use a media object that provides a `tall` size.
		const tallMedia = {
			media_details: {
				sizes: {
					full: { width: 1000, height: 500 },
					tall: { width: 400, height: 200, source_url: 'x' },
				},
			},
		};
		// media tall is 400x200 → aspect ratio 2. Registered tall is 200x400.
		// height is kept (400), width derived: 400 * 2 = 800.
		expect(
			getMediaDimensions( tallMedia, imageDimensions, 'tall' )
		).toStrictEqual( { width: 800, height: 400 } );
	} );
} );

describe( 'Utility - getMediaSrc', () => {
	it( 'returns the source_url for an existing size', () => {
		expect( getMediaSrc( media, 'full' ) ).toBe(
			'https://example.com/full.jpg'
		);
		expect( getMediaSrc( media, 'thumbnail' ) ).toBe(
			'https://example.com/thumb.jpg'
		);
	} );

	it( 'returns undefined for an unknown size slug', () => {
		expect( getMediaSrc( media, 'nonexistent' ) ).toBeUndefined();
	} );

	it( 'returns undefined when media is null or undefined', () => {
		expect( getMediaSrc( null, 'full' ) ).toBeUndefined();
		expect( getMediaSrc( undefined, 'full' ) ).toBeUndefined();
	} );

	it( 'returns undefined when media_details is missing', () => {
		expect( getMediaSrc( {}, 'full' ) ).toBeUndefined();
	} );
} );

describe( 'Utility - getPlaceholderImageDimensions', () => {
	it( 'returns the registered dimensions when the size is cropped', () => {
		expect(
			getPlaceholderImageDimensions( imageDimensions, 'thumbnail' )
		).toStrictEqual( { width: 150, height: 150 } );
	} );

	it( 'returns the registered dimensions when width equals height', () => {
		expect(
			getPlaceholderImageDimensions( imageDimensions, 'medium' )
		).toStrictEqual( { width: 300, height: 300 } );
	} );

	it( 'returns a square based on width when width > height', () => {
		// wide is 400x200 → square placeholder of 400x400.
		expect(
			getPlaceholderImageDimensions( imageDimensions, 'wide' )
		).toStrictEqual( { width: 400, height: 400 } );
	} );

	it( 'returns a square based on height when height > width', () => {
		// tall is 200x400 → square placeholder of 400x400.
		expect(
			getPlaceholderImageDimensions( imageDimensions, 'tall' )
		).toStrictEqual( { width: 400, height: 400 } );
	} );
} );

describe( 'Utility - getSizeKeysIntersection', () => {
	it( 'returns all imageDimensions keys when there is no media', () => {
		expect(
			getSizeKeysIntersection( null, imageDimensions )
		).toStrictEqual( Object.keys( imageDimensions ) );
	} );

	it( 'returns only keys present in both media and imageDimensions', () => {
		// media has: full, thumbnail, medium. imageDimensions has:
		// thumbnail, medium, large, wide, tall. Intersection: thumbnail, medium.
		expect(
			getSizeKeysIntersection( media, imageDimensions )
		).toStrictEqual( [ 'thumbnail', 'medium' ] );
	} );

	it( 'returns an empty array when there is no overlap', () => {
		const isolatedMedia = {
			media_details: { sizes: { foo: {}, bar: {} } },
		};
		expect(
			getSizeKeysIntersection( isolatedMedia, imageDimensions )
		).toStrictEqual( [] );
	} );

	it( 'returns an empty array when imageDimensions is empty', () => {
		expect( getSizeKeysIntersection( media, {} ) ).toStrictEqual( [] );
	} );

	it( 'keeps the media size order and yields no duplicates', () => {
		// getAvailableSizeSlug() falls back to the first key, so the media's
		// own ordering has to survive. Object.keys() is already unique, which
		// is why no de-duplication step is needed here.
		const orderedMedia = {
			media_details: {
				sizes: { tall: {}, thumbnail: {}, wide: {}, medium: {} },
			},
		};

		const keys = getSizeKeysIntersection( orderedMedia, imageDimensions );

		expect( keys ).toStrictEqual( [
			'tall',
			'thumbnail',
			'wide',
			'medium',
		] );
		expect( keys ).toHaveLength( new Set( keys ).size );
	} );
} );

describe( 'Utility - getAvailableSizeSlug', () => {
	it( 'returns "full" when media is present and slug is "full"', () => {
		expect( getAvailableSizeSlug( media, imageDimensions, 'full' ) ).toBe(
			'full'
		);
	} );

	it( 'returns the requested slug when it is in the intersection', () => {
		expect(
			getAvailableSizeSlug( media, imageDimensions, 'thumbnail' )
		).toBe( 'thumbnail' );
	} );

	it( 'falls back to the first intersection key when slug is unavailable', () => {
		// `large` is registered but media does not provide it; first
		// intersection key is `thumbnail`.
		expect( getAvailableSizeSlug( media, imageDimensions, 'large' ) ).toBe(
			'thumbnail'
		);
	} );

	it( 'falls back to the first intersection key when slug is undefined', () => {
		expect(
			getAvailableSizeSlug( media, imageDimensions, undefined )
		).toBe( 'thumbnail' );
	} );

	it( 'uses all imageDimensions keys when media is absent', () => {
		// Without media the intersection is every registered key, so an
		// unknown slug falls back to the first registered key.
		expect(
			getAvailableSizeSlug( null, imageDimensions, 'nonexistent' )
		).toBe( 'thumbnail' );
	} );

	it( 'returns a registered slug verbatim when media is absent', () => {
		expect( getAvailableSizeSlug( null, imageDimensions, 'large' ) ).toBe(
			'large'
		);
	} );

	it( 'does not short-circuit to "full" when media is absent', () => {
		// With no media, "full" is treated like any other slug and must be
		// present in imageDimensions to be returned; otherwise it falls back.
		expect( getAvailableSizeSlug( null, imageDimensions, 'full' ) ).toBe(
			'thumbnail'
		);
	} );
} );
