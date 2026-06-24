/**
 * Get Media Dimensions
 *
 * @param {Object} media
 * @param {Object} imageDimensions
 * @param {string} sizeSlug
 * @return {Object} {width,height}
 */
export function getMediaDimensions( media, imageDimensions, sizeSlug ) {
	if ( ! media ) {
		return {};
	}

	const mediaSize = media.media_details.sizes[ sizeSlug ];

	if ( 'full' === sizeSlug ) {
		return {
			width: mediaSize.width,
			height: mediaSize.height,
		};
	}

	const imageSize = imageDimensions[ sizeSlug ];

	if ( true === imageSize.crop || imageSize.width === imageSize.height ) {
		return {
			width: imageSize.width,
			height: imageSize.height,
		};
	}

	const mediaAspectRatio = mediaSize.width / mediaSize.height;

	if ( imageSize.width > imageSize.height ) {
		return {
			width: imageSize.width,
			height: imageSize.width / mediaAspectRatio,
		};
	}

	return {
		width: imageSize.height * mediaAspectRatio,
		height: imageSize.height,
	};
}

/**
 * Get Media Src
 *
 * @param {Object} media
 * @param {string} sizeSlug
 * @return {string} The media source URL for the requested size.
 */
export function getMediaSrc( media, sizeSlug ) {
	return media?.media_details?.sizes[ sizeSlug ]?.source_url;
}

/**
 * Get Placeholder Image Dimensions
 *
 * @param {Object} imageDimensions
 * @param {string} sizeSlug
 * @return {Object} {width,height}
 */
export function getPlaceholderImageDimensions( imageDimensions, sizeSlug ) {
	const size = imageDimensions[ sizeSlug ];

	if ( true === size.crop || size.width === size.height ) {
		return {
			width: size.width,
			height: size.height,
		};
	}

	if ( size.width > size.height ) {
		return {
			width: size.width,
			height: size.width,
		};
	}

	return {
		width: size.height,
		height: size.height,
	};
}

/**
 * Get Size Keys Intersection
 *
 * @param {Object} media
 * @param {Object} imageDimensions
 * @return {Array} The size keys common to the media and the image dimensions.
 */
export function getSizeKeysIntersection( media, imageDimensions ) {
	if ( ! media ) {
		return Object.keys( imageDimensions );
	}

	const mediaKeys = Object.keys( media.media_details.sizes );
	const sizeKeys = Object.keys( imageDimensions );

	return Array.from(
		new Set( [
			...mediaKeys.filter( ( key ) => sizeKeys.includes( key ) ),
		] )
	);
}

/**
 * Get Available Size Slug
 *
 * @param {Object} media
 * @param {Object} imageDimensions
 * @param {string} sizeSlug
 * @return {string} The size slug to use for the media.
 */
export function getAvailableSizeSlug( media, imageDimensions, sizeSlug ) {
	if ( media && 'full' === sizeSlug ) {
		return sizeSlug;
	}

	const keys = getSizeKeysIntersection( media, imageDimensions );

	if ( sizeSlug && keys.includes( sizeSlug ) ) {
		return sizeSlug;
	}

	return keys[ 0 ];
}
