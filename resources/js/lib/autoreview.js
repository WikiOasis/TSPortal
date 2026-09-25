import { reactive, watch } from 'vue';

export const BUCKETS = [
	{ value: 'urgent', label: 'Needs review quickly', short: 'Quickly', chip: 'error', key: '1' },
	{ value: 'review', label: 'Needs review', short: 'Review', chip: 'warning', key: '2' },
	{ value: 'unlikely', label: 'Unlikely to need review', short: 'Unlikely', chip: 'success', key: '3' }
];

export const VIEWS = [
	...BUCKETS,
	{ value: 'waiting', label: 'Not sorted yet', short: 'Waiting', chip: 'notice' },
	{ value: 'failed', label: 'Could not be sorted', short: 'Failed', chip: 'notice' }
];

export const ACTION_LABELS = {
	'close-no-action': 'Close, no action',
	'check-revert': 'Check it was reverted',
	investigate: 'Look into it',
	escalate: 'Escalate'
};

export function bucket( value ) {
	return VIEWS.find( ( b ) => b.value === value ) ?? null;
}

export function percent( value ) {
	return value === null || value === undefined ? '—' : `${ Math.round( value * 100 ) }%`;
}

export function diffSegments( line ) {
	return line.split( /(\[\+[\s\S]*?\+\]|\[-[\s\S]*?-\])/ ).filter( ( part ) => part !== '' ).map( ( part ) => {
		if ( part.startsWith( '[+' ) && part.endsWith( '+]' ) ) {
			return { text: part.slice( 2, -2 ), kind: 'added' };
		}
		if ( part.startsWith( '[-' ) && part.endsWith( '-]' ) ) {
			return { text: part.slice( 2, -2 ), kind: 'removed' };
		}
		return { text: part, kind: 'plain' };
	} );
}

export function diffLines( diff ) {
	return ( diff ?? '' ).split( '\n' ).map( ( line ) => {
		let kind = 'context';
		if ( line.startsWith( '+ ' ) ) {
			kind = 'added';
		} else if ( line.startsWith( '- ' ) ) {
			kind = 'removed';
		} else if ( line.startsWith( '@@' ) || line.startsWith( '…' ) ) {
			kind = 'hunk';
		}
		return { kind, segments: diffSegments( kind === 'hunk' ? line : line.slice( 2 ) ) };
	} );
}

const STORAGE_KEY = 'tsportal:autoreview-batch';

function readBatch() {
	try {
		const raw = window.localStorage.getItem( STORAGE_KEY );
		const parsed = raw ? JSON.parse( raw ) : [];
		return Array.isArray( parsed ) ? parsed : [];
	} catch ( e ) {
		return [];
	}
}

export function snapshot( row ) {
	return {
		id: row.id,
		reference: row.reference,
		wiki: row.review?.wiki ?? row.wiki,
		page: row.review?.page_title ?? row.subject,
		author: row.review?.author ?? null,
		bucket: row.review?.bucket ?? null,
		confidence: row.review?.confidence ?? null,
		page_summary: row.review?.page_summary ?? null,
		change_summary: row.review?.change_summary ?? null,
		reason: row.review?.reason ?? null,
		revision: row.review?.links?.revision ?? null
	};
}

export const closeBatch = reactive( {
	items: readBatch(),

	has( id ) {
		return this.items.some( ( item ) => item.id === id );
	},

	add( rows ) {
		const known = new Set( this.items.map( ( item ) => item.id ) );
		for ( const row of rows ) {
			if ( !known.has( row.id ) ) {
				this.items.push( snapshot( row ) );
				known.add( row.id );
			}
		}
	},

	toggle( row ) {
		if ( this.has( row.id ) ) {
			this.remove( [ row.id ] );
			return false;
		}
		this.add( [ row ] );
		return true;
	},

	remove( ids ) {
		const drop = new Set( ids );
		this.items = this.items.filter( ( item ) => !drop.has( item.id ) );
	},

	clear() {
		this.items = [];
	}
} );

watch( () => closeBatch.items, ( items ) => {
	try {
		window.localStorage.setItem( STORAGE_KEY, JSON.stringify( items ) );
	} catch ( e ) { }
}, { deep: true } );

export function isTyping( event ) {
	const target = event.target;
	if ( !target || !( target instanceof Element ) ) {
		return false;
	}
	return target.isContentEditable
		|| [ 'INPUT', 'TEXTAREA', 'SELECT' ].includes( target.tagName )
		|| target.closest( '[role="listbox"], [role="combobox"]' ) !== null;
}

export function agreement( row ) {
	const review = row?.review;
	if ( !review || !review.bucket ) {
		return null;
	}
	if ( review.bucket === 'unlikely' || ( review.suggested_action === 'close-no-action' && review.bucket !== 'urgent' ) ) {
		return { kind: 'batch', label: 'Agree — add to close batch', short: 'Close it' };
	}
	return { kind: 'take', label: 'Agree — take it', short: 'Take it' };
}

const TALLY_KEY = 'tsportal:automation-tally';

function readTally() {
	try {
		const parsed = JSON.parse( window.sessionStorage.getItem( TALLY_KEY ) ?? '{}' );
		return { closed: parsed.closed ?? 0, taken: parsed.taken ?? 0, moved: parsed.moved ?? 0 };
	} catch ( e ) {
		return { closed: 0, taken: 0, moved: 0 };
	}
}

export const tally = reactive( readTally() );

watch( tally, ( value ) => {
	try {
		window.sessionStorage.setItem( TALLY_KEY, JSON.stringify( value ) );
	} catch ( e ) { }
} );
