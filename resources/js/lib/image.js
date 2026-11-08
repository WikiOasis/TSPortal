function decode( file ) {
	return new Promise( ( resolve, reject ) => {
		const url = URL.createObjectURL( file );
		const img = new Image();

		img.onload = () => {
			URL.revokeObjectURL( url );
			resolve( img );
		};
		img.onerror = () => {
			URL.revokeObjectURL( url );
			reject( new Error( 'That file could not be read as a picture.' ) );
		};
		img.src = url;
	} );
}

function encode( canvas, type, quality ) {
	return new Promise( ( resolve ) => canvas.toBlob( ( blob ) => resolve( blob ), type, quality ) );
}

export async function shrink( file, { maxSide = 2400, maxBytes = 900 * 1024, quality = 0.85 } = {} ) {
	let img;

	try {
		img = await decode( file );
	} catch ( e ) {
		return file;
	}

	const big = Math.max( img.naturalWidth, img.naturalHeight ) > maxSide;

	if ( !big && file.size <= maxBytes ) {
		return file;
	}

	const scale = big ? maxSide / Math.max( img.naturalWidth, img.naturalHeight ) : 1;
	const canvas = document.createElement( 'canvas' );
	canvas.width = Math.round( img.naturalWidth * scale );
	canvas.height = Math.round( img.naturalHeight * scale );
	canvas.getContext( '2d' ).drawImage( img, 0, 0, canvas.width, canvas.height );

	const blob = await encode( canvas, 'image/webp', quality );

	if ( !blob || blob.size >= file.size ) {
		return file;
	}

	const ext = blob.type === 'image/webp' ? 'webp' : blob.type === 'image/jpeg' ? 'jpg' : 'png';

	return new File( [ blob ], `${ file.name.replace( /\.[^.]+$/, '' ) }.${ ext }`, { type: blob.type } );
}

export { decode, encode };
