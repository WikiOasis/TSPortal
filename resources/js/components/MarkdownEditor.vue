<template>
	<div
		class="ts-mde"
		:class="{ 'ts-mde--over': dragging, 'ts-mde--disabled': disabled }"
		@dragover.prevent="upload && ( dragging = true )"
		@dragleave="dragging = false"
		@drop.prevent="onDrop"
	>
		<div class="ts-mde__toolbar" role="toolbar" aria-label="Formatting">
			<template v-for="( tool, i ) in tools" :key="tool.title">
				<span v-if="tool.divider && i" class="ts-mde__divider" aria-hidden="true" />
				<button
					type="button"
					class="ts-mde__tool"
					:title="tool.title"
					:aria-label="tool.title"
					:disabled="disabled"
					@mousedown.prevent
					@click="tool.run"
				>
					<cdx-icon :icon="tool.icon" size="small" />
				</button>
			</template>
			<span class="ts-mde__gap" />
			<button
				v-if="upload"
				type="button"
				class="ts-mde__tool"
				title="Add a picture (internal only)"
				:disabled="disabled || uploading"
				@mousedown.prevent
				@click="picker.click()"
			>
				<cdx-icon :icon="cdxIconImageAdd" size="small" />
				<span class="ts-mde__tool-text">{{ uploading ? 'Uploading…' : 'Picture' }}</span>
			</button>
			<input
				v-if="upload"
				ref="picker"
				type="file"
				accept="image/png,image/jpeg,image/gif,image/webp"
				multiple
				hidden
				@change="onPick"
			>
		</div>

		<div
			ref="field"
			class="ts-mde__field"
			:contenteditable="disabled ? 'false' : editableMode"
			role="textbox"
			aria-multiline="true"
			:aria-label="label || placeholder"
			:data-placeholder="placeholder"
			:style="{ minHeight: `${ rows * 1.6 + 1.25 }em` }"
			spellcheck="true"
			@input="onInput"
			@keydown="onKeydown"
			@paste="onPaste"
			@compositionstart="composing = true"
			@compositionend="composing = false; onInput()"
		/>

		<div v-if="images.length" class="ts-mde__images">
			<a
				v-for="img in images"
				:key="img.uuid"
				:href="imageUrl( img.uuid )"
				target="_blank"
				rel="noopener"
				class="ts-mde__thumb"
				:title="img.alt"
			>
				<img :src="imageUrl( img.uuid )" :alt="img.alt">
			</a>
		</div>

	</div>
</template>

<script setup>
import { computed, inject, nextTick, onMounted, ref, watch } from 'vue';
import { CdxIcon } from '@wikimedia/codex';
import {
	cdxIconBold, cdxIconCode, cdxIconEyeClosed, cdxIconImageAdd, cdxIconItalic, cdxIconLink,
	cdxIconListBullet, cdxIconQuotes, cdxIconStrikethrough, cdxIconUnderline
} from '@wikimedia/codex-icons';
import { highlight, inline } from '../lib/markdown.js';
import { imageUrl } from '../lib/inlineImages.js';

const props = defineProps( {
	modelValue: { type: String, default: '' },
	placeholder: { type: String, default: '' },
	label: { type: String, default: '' },
	rows: { type: Number, default: 4 },
	disabled: { type: Boolean, default: false },
	upload: { type: Function, default: null }
} );
const emit = defineEmits( [ 'update:modelValue', 'submit' ] );

const notify = inject( 'notify' );
const field = ref( null );
const picker = ref( null );
const uploading = ref( false );
const dragging = ref( false );
const composing = ref( false );

const editableMode = typeof CSS !== 'undefined' && CSS.supports?.( '-webkit-user-modify', 'read-write-plaintext-only' )
	? 'plaintext-only'
	: 'true';

let text = props.modelValue ?? '';
const history = [];
let future = [];
let lastPush = 0;

const images = computed( () => {
	const found = [];
	const walk = ( tokens ) => tokens.forEach( ( t ) => {
		if ( t.type === 'image' ) {
			found.push( t );
		} else if ( t.type === 'style' ) {
			walk( t.children );
		}
	} );
	String( props.modelValue ?? '' ).split( '\n' ).forEach( ( l ) => walk( inline( l ) ) );
	return found;
} );

function offsetOf( node, nodeOffset ) {
	const range = document.createRange();
	range.selectNodeContents( field.value );
	range.setEnd( node, nodeOffset );
	return range.toString().length;
}

function selection() {
	const sel = window.getSelection();
	if ( !sel || !sel.rangeCount || !field.value.contains( sel.anchorNode ) ) {
		return [ text.length, text.length ];
	}
	const a = offsetOf( sel.anchorNode, sel.anchorOffset );
	const f = offsetOf( sel.focusNode, sel.focusOffset );
	return [ Math.min( a, f ), Math.max( a, f ) ];
}

function locate( offset ) {
	const walker = document.createTreeWalker( field.value, NodeFilter.SHOW_TEXT );
	let left = offset;
	let node = walker.nextNode();
	let last = null;
	while ( node ) {
		const chip = node.parentElement?.closest( '.md-image' );
		if ( left <= node.length && !chip ) {
			return [ node, left ];
		}
		if ( left <= node.length && chip ) {
			const after = document.createRange();
			after.setStartAfter( chip );
			return [ after.startContainer, after.startOffset ];
		}
		left -= node.length;
		last = node;
		node = walker.nextNode();
	}
	return last ? [ last, last.length ] : [ field.value, 0 ];
}

function select( start, end = start ) {
	if ( document.activeElement !== field.value ) {
		field.value.focus( { preventScroll: true } );
	}
	const [ sn, so ] = locate( start );
	const [ en, eo ] = locate( end );
	const range = document.createRange();
	range.setStart( sn, so );
	range.setEnd( en, eo );
	const sel = window.getSelection();
	sel.removeAllRanges();
	sel.addRange( range );
}

function read() {
	let value = field.value.textContent ?? '';
	if ( value.endsWith( '\n' ) ) {
		value = value.slice( 0, -1 );
	}
	return value.replace( / /g, ' ' );
}

function paint() {
	field.value.innerHTML = text === '' ? '' : `${ highlight( text ) }\n`;
}

function remember( force = false ) {
	const now = Date.now();
	if ( force || now - lastPush > 600 || !history.length ) {
		history.push( { text, sel: selection() } );
		if ( history.length > 200 ) {
			history.shift();
		}
		future = [];
	} else {
		history[ history.length - 1 ].sel = selection();
	}
	lastPush = now;
}

function commit( next, start, end = start ) {
	remember( true );
	text = next;
	paint();
	select( start, end );
	emit( 'update:modelValue', text );
}

function onInput() {
	if ( composing.value ) {
		return;
	}
	const [ start ] = selection();
	const next = read();
	if ( next !== text ) {
		remember();
		text = next;
	}
	paint();
	if ( document.activeElement === field.value ) {
		select( start );
	}
	emit( 'update:modelValue', text );
}

function replaceSelection( insert, caretFromStart = insert.length, selectLength = 0 ) {
	const [ start, end ] = selection();
	const next = text.slice( 0, start ) + insert + text.slice( end );
	commit( next, start + caretFromStart, start + caretFromStart + selectLength );
}

function wrap( marker, endMarker = marker, empty = '' ) {
	const [ start, end ] = selection();
	const chosen = text.slice( start, end );

	if (
		chosen &&
		text.slice( start - marker.length, start ) === marker &&
		text.slice( end, end + endMarker.length ) === endMarker
	) {
		const next = text.slice( 0, start - marker.length ) + chosen + text.slice( end + endMarker.length );
		commit( next, start - marker.length, end - marker.length );
		return;
	}

	const inner = chosen || empty;
	const next = text.slice( 0, start ) + marker + inner + endMarker + text.slice( end );
	commit( next, start + marker.length, start + marker.length + inner.length );
}

function prefixLines( prefix ) {
	const [ start, end ] = selection();
	const lineStart = text.lastIndexOf( '\n', start - 1 ) + 1;
	const block = text.slice( lineStart, end );
	const lines = block.split( '\n' );
	const all = lines.every( ( l ) => l.startsWith( prefix ) );
	const changed = lines.map( ( l ) => ( all ? l.slice( prefix.length ) : prefix + l ) ).join( '\n' );
	const next = text.slice( 0, lineStart ) + changed + text.slice( end );
	commit( next, lineStart, lineStart + changed.length );
}

function link() {
	const [ start, end ] = selection();
	const chosen = text.slice( start, end );
	if ( /^https?:\/\/\S+$/.test( chosen ) ) {
		const next = `${ text.slice( 0, start ) }[link text](${ chosen })${ text.slice( end ) }`;
		commit( next, start + 1, start + 10 );
		return;
	}
	const label = chosen || 'link text';
	const next = `${ text.slice( 0, start ) }[${ label }](https://)${ text.slice( end ) }`;
	const urlAt = start + label.length + 3;
	commit( next, urlAt + 8 );
}

const tools = [
	{ icon: cdxIconBold, title: 'Bold (Ctrl+B)', run: () => wrap( '**', '**', 'bold' ) },
	{ icon: cdxIconItalic, title: 'Italic (Ctrl+I)', run: () => wrap( '*', '*', 'italic' ) },
	{ icon: cdxIconUnderline, title: 'Underline (Ctrl+U)', run: () => wrap( '__', '__', 'underline' ) },
	{ icon: cdxIconStrikethrough, title: 'Strikethrough', run: () => wrap( '~~', '~~', 'struck' ) },
	{ icon: cdxIconCode, title: 'Code (Ctrl+E)', run: () => wrap( '`', '`', 'code' ), divider: true },
	{ icon: cdxIconEyeClosed, title: 'Spoiler', run: () => wrap( '||', '||', 'spoiler' ) },
	{ icon: cdxIconQuotes, title: 'Quote', run: () => prefixLines( '> ' ), divider: true },
	{ icon: cdxIconListBullet, title: 'Bulleted list', run: () => prefixLines( '- ' ) },
	{ icon: cdxIconLink, title: 'Link (Ctrl+K)', run: link, divider: true }
];

function undo() {
	if ( !history.length ) {
		return;
	}
	future.push( { text, sel: selection() } );
	const prev = history.pop();
	text = prev.text;
	paint();
	select( ...prev.sel );
	emit( 'update:modelValue', text );
}

function redo() {
	if ( !future.length ) {
		return;
	}
	history.push( { text, sel: selection() } );
	const next = future.pop();
	text = next.text;
	paint();
	select( ...next.sel );
	emit( 'update:modelValue', text );
}

function newline() {
	const [ start, end ] = selection();
	const lineStart = text.lastIndexOf( '\n', start - 1 ) + 1;
	const line = text.slice( lineStart, start );

	const m = /^(\s*(?:[-*]|\d+\.) |> )(.*)$/.exec( line );
	if ( m && start === end ) {
		if ( m[ 2 ].trim() === '' ) {
			const next = text.slice( 0, lineStart ) + text.slice( start );
			commit( next, lineStart );
			return;
		}
		const num = /^(\s*)(\d+)\. $/.exec( m[ 1 ] );
		const carry = num ? `${ num[ 1 ] }${ Number( num[ 2 ] ) + 1 }. ` : m[ 1 ];
		replaceSelection( `\n${ carry }` );
		return;
	}

	replaceSelection( '\n' );
}

function onKeydown( event ) {
	const mod = event.metaKey || event.ctrlKey;

	if ( event.key === 'Enter' && mod ) {
		event.preventDefault();
		emit( 'submit' );
		return;
	}
	if ( event.key === 'Enter' && !composing.value ) {
		event.preventDefault();
		newline();
		return;
	}
	if ( event.key === 'Tab' && !mod ) {
		return;
	}
	if ( !mod ) {
		return;
	}

	const key = event.key.toLowerCase();
	const actions = {
		b: () => wrap( '**', '**', 'bold' ),
		i: () => wrap( '*', '*', 'italic' ),
		u: () => wrap( '__', '__', 'underline' ),
		e: () => wrap( '`', '`', 'code' ),
		k: link,
		z: () => ( event.shiftKey ? redo() : undo() ),
		y: redo
	};
	if ( actions[ key ] ) {
		event.preventDefault();
		actions[ key ]();
	}
}

function pictures( list ) {
	return Array.from( list ?? [] ).filter( ( f ) => f.type.startsWith( 'image/' ) );
}

async function send( files ) {
	if ( !files.length ) {
		return;
	}
	if ( !props.upload ) {
		notify( 'Pictures can only go in internal notes.', 'error' );
		return;
	}

	uploading.value = true;
	try {
		for ( const file of files ) {
			const image = await props.upload( file );
			const [ start ] = selection();
			const before = text.slice( 0, start );
			const lead = before && !before.endsWith( '\n' ) ? '\n' : '';
			replaceSelection( `${ lead }${ image.token }\n` );
		}
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		uploading.value = false;
	}
}

function onPaste( event ) {
	event.preventDefault();
	const files = pictures( event.clipboardData?.files );
	if ( files.length ) {
		send( files );
		return;
	}
	const pasted = ( event.clipboardData?.getData( 'text/plain' ) ?? '' ).replace( /\r\n?/g, '\n' );
	if ( pasted ) {
		replaceSelection( pasted );
	}
}

function onDrop( event ) {
	dragging.value = false;
	send( pictures( event.dataTransfer?.files ) );
}

function onPick( event ) {
	send( pictures( event.target.files ) );
	event.target.value = '';
}

watch( () => props.modelValue, ( value ) => {
	if ( ( value ?? '' ) === text ) {
		return;
	}
	const focused = document.activeElement === field.value;
	text = value ?? '';
	paint();
	if ( focused ) {
		select( text.length );
	}
} );

onMounted( paint );

function focus( atEnd = true ) {
	nextTick( () => select( atEnd ? text.length : 0 ) );
}

function insert( snippet ) {
	field.value.focus();
	const [ start ] = selection();
	commit( text.slice( 0, start ) + snippet + text.slice( start ), start + snippet.length );
}

defineExpose( { focus, insert } );
</script>
