import { imageUrl } from './inlineImages.js';

const ESCAPABLE = '\\*_~|`>#[';

export function escapeHtml( text ) {
	return String( text )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#39;' );
}

const STYLES = [
	{ marker: '***', tag: 'bold-italic' },
	{ marker: '**', tag: 'strong' },
	{ marker: '__', tag: 'u' },
	{ marker: '~~', tag: 's' },
	{ marker: '||', tag: 'spoiler' },
	{ marker: '*', tag: 'em' },
	{ marker: '_', tag: 'em', word: true }
];

const IMAGE = /^!\[([^\]\n]*)\]\(image:([0-9a-f-]{36})\)/i;
const LINK = /^\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/;
const AUTOLINK = /^https?:\/\/[^\s<]*[^\s<.,:;"')\]!?*_~|]/;
const CODE = /^(`+)([^`\n]|[^`\n][\s\S]*?[^`])\1(?!`)/;

function findClose( text, from, marker ) {
	let i = from;
	while ( i < text.length ) {
		if ( text[ i ] === '\\' ) {
			i += 2;
			continue;
		}
		if ( text[ i ] === '`' ) {
			const code = CODE.exec( text.slice( i ) );
			if ( code ) {
				i += code[ 0 ].length;
				continue;
			}
		}
		if ( text.startsWith( marker, i ) ) {
			if ( marker.length === 1 && text[ i + 1 ] === marker ) {
				i += 2;
				continue;
			}
			return i;
		}
		i++;
	}
	return -1;
}

/**
 * @param {string} text
 * @return {Array<Object>}
 */
export function inline( text ) {
	const out = [];
	let buffer = '';
	let i = 0;

	const flush = () => {
		if ( buffer ) {
			out.push( { type: 'text', text: buffer } );
			buffer = '';
		}
	};

	while ( i < text.length ) {
		const rest = text.slice( i );
		const ch = text[ i ];
		let m;

		if ( ch === '\\' && i + 1 < text.length && ESCAPABLE.includes( text[ i + 1 ] ) ) {
			flush();
			out.push( { type: 'escape', char: text[ i + 1 ] } );
			i += 2;
			continue;
		}

		if ( ch === '!' && ( m = IMAGE.exec( rest ) ) ) {
			flush();
			out.push( { type: 'image', alt: m[ 1 ], uuid: m[ 2 ].toLowerCase(), raw: m[ 0 ] } );
			i += m[ 0 ].length;
			continue;
		}

		if ( ch === '`' && ( m = CODE.exec( rest ) ) ) {
			flush();
			out.push( { type: 'code', marker: m[ 1 ], text: m[ 2 ] } );
			i += m[ 0 ].length;
			continue;
		}

		if ( ch === '[' && ( m = LINK.exec( rest ) ) ) {
			flush();
			out.push( { type: 'link', label: m[ 1 ], url: m[ 2 ], raw: m[ 0 ] } );
			i += m[ 0 ].length;
			continue;
		}

		if ( ch === 'h' && ( i === 0 || /[\s(]/.test( text[ i - 1 ] ) ) && ( m = AUTOLINK.exec( rest ) ) ) {
			flush();
			out.push( { type: 'link', label: m[ 0 ], url: m[ 0 ], raw: m[ 0 ] } );
			i += m[ 0 ].length;
			continue;
		}

		let styled = null;
		for ( const style of STYLES ) {
			if ( !text.startsWith( style.marker, i ) ) {
				continue;
			}
			const start = i + style.marker.length;

			if ( start >= text.length || /\s/.test( text[ start ] ) ) {
				continue;
			}
			if ( style.word && i > 0 && /\w/.test( text[ i - 1 ] ) ) {
				continue;
			}
			const close = findClose( text, start, style.marker );
			if ( close <= start || /\s/.test( text[ close - 1 ] ) ) {
				continue;
			}
			if ( style.word && /\w/.test( text[ close + style.marker.length ] ?? '' ) ) {
				continue;
			}
			styled = {
				type: 'style',
				tag: style.tag,
				marker: style.marker,
				children: inline( text.slice( start, close ) ),
				length: close + style.marker.length - i
			};
			break;
		}

		if ( styled ) {
			flush();
			out.push( styled );
			i += styled.length;
			continue;
		}

		buffer += ch;
		i++;
	}

	flush();
	return out;
}

function renderInline( tokens ) {
	return tokens.map( ( t ) => {
		switch ( t.type ) {
			case 'text':
				return escapeHtml( t.text );
			case 'escape':
				return escapeHtml( t.char );
			case 'code':
				return `<code>${ escapeHtml( t.text ) }</code>`;
			case 'image':
				return `<a class="ts-inline-image" href="${ imageUrl( t.uuid ) }" target="_blank" rel="noopener">` +
					`<img src="${ imageUrl( t.uuid ) }" alt="${ escapeHtml( t.alt ) }" loading="lazy"></a>`;
			case 'link':
				return `<a href="${ escapeHtml( t.url ) }" target="_blank" rel="nofollow noopener noreferrer">${ escapeHtml( t.label ) }</a>`;
			case 'style': {
				const inner = renderInline( t.children );
				if ( t.tag === 'bold-italic' ) {
					return `<strong><em>${ inner }</em></strong>`;
				}
				if ( t.tag === 'spoiler' ) {
					return `<span class="ts-md-spoiler" role="button" tabindex="0" title="Spoiler — click to show">${ inner }</span>`;
				}
				return `<${ t.tag }>${ inner }</${ t.tag }>`;
			}
		}
		return '';
	} ).join( '' );
}

function blocks( text ) {
	const lines = String( text ?? '' ).replace( /\r\n?/g, '\n' ).split( '\n' );
	const out = [];
	let i = 0;

	while ( i < lines.length ) {
		const line = lines[ i ];

		if ( /^\s*```/.test( line ) ) {
			const body = [];
			i++;
			while ( i < lines.length && !/^\s*```\s*$/.test( lines[ i ] ) ) {
				body.push( lines[ i ] );
				i++;
			}
			i++;
			out.push( { type: 'fence', text: body.join( '\n' ) } );
			continue;
		}

		if ( /^>( |$)/.test( line ) ) {
			const body = [];
			while ( i < lines.length && /^>( |$)/.test( lines[ i ] ) ) {
				body.push( lines[ i ].replace( /^> ?/, '' ) );
				i++;
			}
			out.push( { type: 'quote', lines: body } );
			continue;
		}

		let m;
		if ( ( m = /^(#{1,3}) (.+)$/.exec( line ) ) ) {
			out.push( { type: 'heading', level: m[ 1 ].length, text: m[ 2 ] } );
			i++;
			continue;
		}

		if ( ( m = /^-# (.+)$/.exec( line ) ) ) {
			out.push( { type: 'subtext', text: m[ 1 ] } );
			i++;
			continue;
		}

		if ( /^\s*[-*] \S/.test( line ) || /^\s*\d+\. \S/.test( line ) ) {
			const ordered = /^\s*\d+\. /.test( line );
			const pattern = ordered ? /^\s*\d+\. (.*)$/ : /^\s*[-*] (.*)$/;
			const items = [];
			while ( i < lines.length && pattern.test( lines[ i ] ) ) {
				items.push( pattern.exec( lines[ i ] )[ 1 ] );
				i++;
			}
			out.push( { type: ordered ? 'ol' : 'ul', items } );
			continue;
		}

		if ( line.trim() === '' ) {
			i++;
			continue;
		}

		const para = [];
		while (
			i < lines.length &&
			lines[ i ].trim() !== '' &&
			!/^\s*```/.test( lines[ i ] ) &&
			!/^>( |$)/.test( lines[ i ] ) &&
			!/^(#{1,3}|-#) ./.test( lines[ i ] ) &&
			!/^\s*([-*]|\d+\.) \S/.test( lines[ i ] )
		) {
			para.push( lines[ i ] );
			i++;
		}
		out.push( { type: 'para', lines: para } );
	}

	return out;
}

const lineHtml = ( lines ) => lines.map( ( l ) => renderInline( inline( l ) ) ).join( '<br>' );

export function render( text ) {
	return blocks( text ).map( ( b ) => {
		switch ( b.type ) {
			case 'fence':
				return `<pre class="ts-md-pre"><code>${ escapeHtml( b.text ) }</code></pre>`;
			case 'quote':
				return `<blockquote class="ts-md-quote">${ lineHtml( b.lines ) }</blockquote>`;
			case 'heading':
				return `<p class="ts-md-h${ b.level }">${ renderInline( inline( b.text ) ) }</p>`;
			case 'subtext':
				return `<p class="ts-md-sub">${ renderInline( inline( b.text ) ) }</p>`;
			case 'ul':
			case 'ol':
				return `<${ b.type }>${ b.items.map( ( item ) => `<li>${ renderInline( inline( item ) ) }</li>` ).join( '' ) }</${ b.type }>`;
			default:
				return `<p>${ lineHtml( b.lines ) }</p>`;
		}
	} ).join( '' );
}

export function plain( text ) {
	const walk = ( tokens ) => tokens.map( ( t ) => {
		if ( t.type === 'style' ) {
			return walk( t.children );
		}
		if ( t.type === 'image' ) {
			return `[picture: ${ t.alt || 'image' }]`;
		}
		return t.text ?? t.char ?? t.label ?? '';
	} ).join( '' );

	return String( text ?? '' )
		.split( '\n' )
		.filter( ( l ) => !/^\s*```/.test( l ) )
		.map( ( l ) => walk( inline( l.replace( /^(> ?|#{1,3} |-# |\s*[-*] |\s*\d+\. )/, '' ) ) ) )
		.join( ' ' )
		.replace( /\s+/g, ' ' )
		.trim();
}

const mark = ( text ) => `<span class="md-mark">${ escapeHtml( text ) }</span>`;

function highlightInline( tokens ) {
	return tokens.map( ( t ) => {
		switch ( t.type ) {
			case 'text':
				return escapeHtml( t.text );
			case 'escape':
				return mark( '\\' ) + escapeHtml( t.char );
			case 'code':
				return mark( t.marker ) + `<code>${ escapeHtml( t.text ) }</code>` + mark( t.marker );
			case 'image': {
				const head = `![${ t.alt }]`;
				return `<span class="md-image" contenteditable="false">${ escapeHtml( head ) }` +
					`<span class="md-hidden">${ escapeHtml( t.raw.slice( head.length ) ) }</span></span>`;
			}
			case 'link':
				return `<span class="md-link">${ escapeHtml( t.raw ) }</span>`;
			case 'style': {
				const inner = highlightInline( t.children );
				const tag = t.tag === 'bold-italic' ? 'strong' : t.tag === 'spoiler' ? 'span' : t.tag;
				const cls = t.tag === 'spoiler' ? ' class="md-spoiler"' : t.tag === 'bold-italic' ? ' class="md-bold-italic"' : '';
				return mark( t.marker ) + `<${ tag }${ cls }>${ inner }</${ tag }>` + mark( t.marker );
			}
		}
		return '';
	} ).join( '' );
}

export function highlight( text ) {
	const lines = String( text ?? '' ).split( '\n' );
	let fenced = false;

	return lines.map( ( line ) => {
		if ( /^\s*```/.test( line ) ) {
			fenced = !fenced;
			return `<span class="md-fence">${ escapeHtml( line ) }</span>`;
		}
		if ( fenced ) {
			return `<span class="md-fence-body">${ escapeHtml( line ) }</span>`;
		}

		let m;
		if ( ( m = /^(> ?)(.*)$/.exec( line ) ) && /^>( |$)/.test( line ) ) {
			return `<span class="md-quote">${ mark( m[ 1 ] ) }${ highlightInline( inline( m[ 2 ] ) ) }</span>`;
		}
		if ( ( m = /^(#{1,3} )(.+)$/.exec( line ) ) ) {
			return `<span class="md-h${ m[ 1 ].length - 1 }">${ mark( m[ 1 ] ) }${ highlightInline( inline( m[ 2 ] ) ) }</span>`;
		}
		if ( ( m = /^(-# )(.+)$/.exec( line ) ) ) {
			return `<span class="md-sub">${ mark( m[ 1 ] ) }${ highlightInline( inline( m[ 2 ] ) ) }</span>`;
		}
		if ( ( m = /^(\s*(?:[-*]|\d+\.) )(.*)$/.exec( line ) ) && /\S/.test( m[ 2 ] ) ) {
			return `<span class="md-list">${ escapeHtml( m[ 1 ] ) }</span>${ highlightInline( inline( m[ 2 ] ) ) }`;
		}

		return highlightInline( inline( line ) );
	} ).join( '\n' );
}
