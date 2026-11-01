import { reactive, watch } from 'vue';
import { api } from './api.js';

const KEY = 'tsportal:preferences';

export const PALETTES = [
	{ value: 'default', label: 'Default', accent: null },
	{ value: 'ocean', label: 'Ocean', accent: '#0f93b3', tint: 6 },
	{ value: 'forest', label: 'Forest', accent: '#2f9e63', tint: 6 },
	{ value: 'sunset', label: 'Sunset', accent: '#e8743b', tint: 6 },
	{ value: 'grape', label: 'Grape', accent: '#8b5cf6', tint: 8 },
	{ value: 'rose', label: 'Rose', accent: '#e0507a', tint: 6 },
	{ value: 'gold', label: 'Gold', accent: '#c9a227', tint: 5 },
	{ value: 'slate', label: 'Slate', accent: '#7b8794', tint: 4 }
];

export const NOTIFY_EVENTS = [
	{ key: 'comment', label: 'Comments and notes', hint: 'Someone adds to the conversation or the file.' },
	{ key: 'status', label: 'Status changes', hint: 'Closed, concluded, escalated, appeals decided.' },
	{ key: 'assignment', label: 'Assignment', hint: 'It is handed to someone, or taken.' },
	{ key: 'priority', label: 'Priority and review dates', hint: 'How urgent it is, or when to look again.' },
	{ key: 'linked', label: 'Linked reports and accounts', hint: 'Cases attached or merged, accounts added.' },
	{ key: 'actions', label: 'Actions taken', hint: 'Warnings, blocks and locks issued or lifted.' },
	{ key: 'other', label: 'Everything else', hint: 'Any other change on something you watch.' }
];

export const AUTO_SUBSCRIBE = [
	{ key: 'assigned', label: 'When it is assigned to me' },
	{ key: 'commented', label: 'When I comment or add a note' },
	{ key: 'opened', label: 'When I open it' }
];

export const TEXT_SIZES = { small: 90, default: 100, large: 112, xlarge: 125 };

const DEFAULTS = {
	theme: 'system',
	density: 'roomy',
	textSize: 'default',
	palette: 'default',
	themes: [],
	css: '',
	notify: {
		events: { comment: true, status: true, assignment: true, priority: true, linked: true, actions: true, other: false },
		auto: { assigned: true, commented: true, opened: true }
	}
};

const SYNCED = [ 'theme', 'density', 'textSize', 'palette', 'themes', 'css', 'notify' ];

const clone = ( value ) => JSON.parse( JSON.stringify( value ) );

function normalise( saved ) {
	const out = { ...clone( DEFAULTS ), ...saved };

	if ( out.palette === 'custom' && saved?.accent ) {
		out.themes = [ ...( out.themes ?? [] ), { id: 'mine', name: 'My theme', accent: saved.accent, tint: Number( saved.tint ?? 6 ) } ];
		out.palette = 'mine';
	}

	out.css = out.css ?? '';

	out.notify = {
		events: { ...DEFAULTS.notify.events, ...( saved?.notify?.events ?? {} ) },
		auto: { ...DEFAULTS.notify.auto, ...( saved?.notify?.auto ?? {} ) }
	};

	return Object.fromEntries( SYNCED.map( ( k ) => [ k, out[ k ] ] ) );
}

function read() {
	try {
		return normalise( JSON.parse( window.localStorage.getItem( KEY ) ?? '{}' ) );
	} catch ( e ) {
		return normalise( {} );
	}
}

export const preferences = reactive( read() );

const SURFACES = {
	'background-color-base': [ '#ffffff', '#101418' ],
	'background-color-neutral-subtle': [ '#f8f9fa', '#202122' ],
	'background-color-interactive-subtle': [ '#f8f9fa', '#202122' ],
	'background-color-neutral': [ '#eaecf0', '#27292d' ],
	'background-color-interactive': [ '#eaecf0', '#27292d' ]
};

const dark = window.matchMedia( '( prefers-color-scheme: dark )' );

function isDark() {
	return preferences.theme === 'dark' || ( preferences.theme === 'system' && dark.matches );
}

export function allThemes() {
	return [
		...PALETTES.filter( ( p ) => p.accent ).map( ( p ) => ( { id: p.value, name: p.label, accent: p.accent, tint: p.tint ?? 0, preset: true } ) ),
		...preferences.themes
	];
}

function activeTheme() {
	return allThemes().find( ( t ) => t.id === preferences.palette ) ?? null;
}

export function themeCss( theme, night ) {
	if ( !theme ) {
		return '';
	}

	const { accent, background } = theme;
	const tint = Number( theme.tint ?? 0 );
	const mix = ( color, pct, into ) => `color-mix( in srgb, ${ color }  ${ pct }%, ${ into } )`;
	const text = night ? mix( '#ffffff', 35, accent ) : mix( '#000000', 12, accent );
	const into = background ?? ( night ? '#101418' : '#ffffff' );
	const rules = {
		'color-progressive': text,
		'color-progressive--hover': night ? mix( '#ffffff', 50, accent ) : mix( '#000000', 25, accent ),
		'color-progressive--active': night ? mix( '#ffffff', 60, accent ) : mix( '#000000', 40, accent ),
		'color-progressive--focus': accent,
		'background-color-progressive': accent,
		'background-color-progressive--hover': mix( '#ffffff', 12, accent ),
		'background-color-progressive--active': mix( '#000000', 18, accent ),
		'background-color-progressive-subtle': mix( accent, night ? 16 : 10, into ),
		'background-color-progressive-subtle--hover': mix( accent, night ? 24 : 16, into ),
		'background-color-progressive-subtle--active': mix( accent, night ? 32 : 24, into ),
		'border-color-progressive': accent,
		'border-color-progressive--hover': mix( '#ffffff', 12, accent ),
		'border-color-progressive--active': mix( '#000000', 18, accent ),
		'border-color-progressive--focus': accent,
		'box-shadow-color-progressive--focus': accent
	};

	if ( background ) {
		const ink = night ? '#ffffff' : '#000000';
		rules[ 'background-color-base' ] = background;
		rules[ 'background-color-neutral-subtle' ] = mix( ink, 4, background );
		rules[ 'background-color-interactive-subtle' ] = mix( ink, 4, background );
		rules[ 'background-color-neutral' ] = mix( ink, 9, background );
		rules[ 'background-color-interactive' ] = mix( ink, 9, background );
	} else if ( tint > 0 ) {
		for ( const [ name, [ light, deep ] ] of Object.entries( SURFACES ) ) {
			rules[ name ] = mix( accent, tint, night ? deep : light );
		}
	}

	return `:root:root:root { ${ Object.entries( rules ).map( ( [ k, v ] ) => `--${ k }: ${ v };` ).join( ' ' ) } }`;
}

function style( id ) {
	let el = document.getElementById( id );
	if ( !el ) {
		el = document.createElement( 'style' );
		el.id = id;
		document.head.appendChild( el );
	}
	return el;
}

export function applyPreferences() {
	const root = document.documentElement;

	if ( preferences.theme === 'system' ) {
		root.removeAttribute( 'data-theme' );
	} else {
		root.setAttribute( 'data-theme', preferences.theme );
	}

	root.dataset.density = preferences.density;
	root.style.fontSize = `${ TEXT_SIZES[ preferences.textSize ] ?? 100 }%`;
	const theme = activeTheme();
	style( 'ts-user-theme' ).textContent = themeCss( theme, isDark() );

	if ( theme?.image ) {
		root.dataset.bgImage = '';
		root.style.setProperty( '--ts-bg-image', `url( "${ theme.image }" )` );
		root.style.setProperty( '--ts-bg-opacity', String( 1 - Number( theme.dim ?? 40 ) / 100 ) );
	} else {
		delete root.dataset.bgImage;
		root.style.removeProperty( '--ts-bg-image' );
		root.style.removeProperty( '--ts-bg-opacity' );
	}
	style( 'ts-user-css' ).textContent = preferences.css;
}

export function resetPreferences() {
	Object.assign( preferences, clone( DEFAULTS ) );
}

export function newThemeId() {
	return `t${ Math.random().toString( 36 ).slice( 2, 8 ) }`;
}

export function exportTheme( theme ) {
	const { name, accent, tint, background } = theme;
	return JSON.stringify( { name, accent, tint, background } );
}

export function parseTheme( text ) {
	const data = JSON.parse( text );

	if ( !/^#[0-9a-f]{6}$/i.test( data.accent ?? '' ) ) {
		throw new Error( 'The accent colour in that theme is not valid.' );
	}
	if ( data.background && !/^#[0-9a-f]{6}$/i.test( data.background ) ) {
		throw new Error( 'The background colour in that theme is not valid.' );
	}

	return {
		id: newThemeId(),
		name: String( data.name ?? 'Imported theme' ).slice( 0, 40 ),
		accent: data.accent,
		tint: Math.max( 0, Math.min( 16, Number( data.tint ?? 0 ) ) ),
		...( data.background ? { background: data.background } : {} )
	};
}

let hydrated = false;
let timer = null;
let lastSent = null;

const snapshot = () => clone( Object.fromEntries( SYNCED.map( ( k ) => [ k, preferences[ k ] ] ) ) );

function save() {
	const payload = snapshot();

	try {
		window.localStorage.setItem( KEY, JSON.stringify( payload ) );
	} catch ( e ) { }

	if ( !hydrated || JSON.stringify( payload ) === lastSent ) {
		return;
	}

	clearTimeout( timer );
	timer = setTimeout( () => {
		lastSent = JSON.stringify( payload );
		api.savePreferences( payload ).catch( () => {
			lastSent = null;
		} );
	}, 600 );
}

export function hydratePreferences( user ) {
	hydrated = false;

	const saved = user?.preferences;

	if ( saved && Object.keys( saved ).length ) {
		Object.assign( preferences, normalise( { ...saved, notify: user.notify ?? saved.notify } ) );
	} else if ( user?.notify ) {
		preferences.notify = clone( user.notify );
	}

	hydrated = true;

	if ( saved && Object.keys( saved ).length ) {
		lastSent = JSON.stringify( snapshot() );
	} else {
		save();
	}
}

watch( preferences, () => {
	applyPreferences();
	save();
} );

dark.addEventListener( 'change', applyPreferences );

applyPreferences();
