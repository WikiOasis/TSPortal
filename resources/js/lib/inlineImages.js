const TOKEN = /!\[([^\]\n]*)\]\(image:([0-9a-f-]{36})\)/gi;

export function hasImages( text ) {
	TOKEN.lastIndex = 0;
	return !!text && TOKEN.test( text );
}

export function imageUrl( uuid ) {
	return `/api/portal/inline-images/${ uuid }`;
}
