export const MIN_STOPS = 2;
export const MAX_STOPS = 5;

export const HEX = /^#[0-9a-f]{6}$/i;

export function gradientCss( gradient ) {
	const stops = ( gradient?.stops ?? [] ).filter( ( s ) => HEX.test( s ) );

	if ( stops.length < MIN_STOPS ) {
		return '';
	}

	return `linear-gradient( ${ Number( gradient.angle ?? 135 ) }deg, ${ stops.join( ', ' ) } )`;
}

export function newGradient() {
	return { stops: [ '#7c5cff', '#2bb3c0' ], angle: 135 };
}

export function cleanGradient( gradient ) {
	const stops = ( gradient?.stops ?? [] ).filter( ( s ) => HEX.test( s ) ).slice( 0, MAX_STOPS );

	return stops.length < MIN_STOPS ? null : {
		stops,
		angle: Math.max( 0, Math.min( 360, Math.round( Number( gradient.angle ?? 135 ) ) ) )
	};
}
