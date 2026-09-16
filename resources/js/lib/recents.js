const KEY = 'tsportal:recents';
const LIMIT = 12;

function read() {
	try {
		const raw = window.localStorage.getItem( KEY );
		const list = raw ? JSON.parse( raw ) : [];

		return Array.isArray( list ) ? list : [];
	} catch ( e ) {
		return [];
	}
}

function write( list ) {
	try {
		window.localStorage.setItem( KEY, JSON.stringify( list ) );
	} catch ( e ) { }
}

export function remember( item ) {
	if ( !item || !item.kind || item.id === null || item.id === undefined ) {
		return;
	}

	const entry = {
		kind: item.kind,
		kind_label: item.kind_label ?? null,
		id: item.id,
		title: item.title ?? '',
		reference: item.reference ?? null,
		route: item.route ?? null,
		status: item.status ?? null,
		status_of: item.status_of ?? null,
		visited: new Date().toISOString()
	};

	if ( !entry.title ) {
		return;
	}

	const rest = read().filter(
		( row ) => !( row.kind === entry.kind && String( row.id ) === String( entry.id ) )
	);

	write( [ entry, ...rest ].slice( 0, LIMIT ) );
}

export function recents() {
	return read();
}

export function forget() {
	write( [] );
}
