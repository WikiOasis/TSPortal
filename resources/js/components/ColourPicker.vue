<template>
	<div class="ts-cp">
		<button
			ref="trigger"
			type="button"
			class="ts-cp__trigger"
			:aria-expanded="shown"
			aria-haspopup="dialog"
			:aria-label="label"
			@click="toggle"
		>
			<span class="ts-cp__chip" :style="{ background: modelValue }" />
			<span class="ts-mono">{{ modelValue }}</span>
		</button>

		<Teleport to="body">
			<div
				v-if="shown"
				ref="panel"
				class="ts-cp__panel"
				role="dialog"
				:aria-label="label"
				:style="position"
				@keydown.esc.stop.prevent="close"
				@keydown.stop
			>
				<div
					ref="plane"
					class="ts-cp__plane"
					:style="{ '--ts-cp-hue': hsv.h }"
					role="slider"
					tabindex="0"
					:aria-label="`${ label }: saturation and brightness`"
					@pointerdown="startPlane"
					@pointermove="movePlane"
					@keydown="planeKey"
				>
					<span class="ts-cp__thumb" :style="{ left: `${ hsv.s * 100 }%`, top: `${ ( 1 - hsv.v ) * 100 }%`, background: modelValue }" />
				</div>

				<input
					class="ts-cp__hue"
					type="range"
					min="0"
					max="359"
					:value="hsv.h"
					:aria-label="`${ label }: hue`"
					@input="setHue( Number( $event.target.value ) )"
				>

				<div class="ts-cp__row">
					<span class="ts-cp__chip ts-cp__chip--large" :style="{ background: modelValue }" />
					<input
						v-model="typed"
						class="ts-cp__hex ts-mono"
						spellcheck="false"
						maxlength="7"
						:aria-label="`${ label } as hex`"
						placeholder="#7c5cff"
						@input="onType( typed )"
					>
					<button v-if="canPick" type="button" class="ts-cp__pick" @click="eyedrop">Pick</button>
				</div>

				<div class="ts-cp__swatches">
					<button
						v-for="c in swatches"
						:key="c"
						type="button"
						class="ts-cp__swatch"
						:style="{ background: c }"
						:aria-label="c"
						:title="c"
						@click="set( c )"
					/>
				</div>
			</div>
		</Teleport>
	</div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { HEX } from '../lib/gradient.js';

const props = defineProps( {
	modelValue: { type: String, default: '#7c5cff' },
	label: { type: String, default: 'Colour' }
} );
const emit = defineEmits( [ 'update:modelValue' ] );

const RECENT = 'tsportal:recent-colours';
const BASE = [ '#7c5cff', '#0f93b3', '#2f9e63', '#e8743b', '#e0507a', '#c9a227', '#7b8794', '#101418', '#1a1626', '#ffffff' ];

const shown = ref( false );
const plane = ref( null );
const trigger = ref( null );
const panel = ref( null );
const position = ref( {} );
const typed = ref( props.modelValue );
const hsv = reactive( { h: 0, s: 0, v: 0 } );
const recent = ref( ( () => {
	try {
		return JSON.parse( window.localStorage.getItem( RECENT ) ?? '[]' ).filter( ( c ) => HEX.test( c ) );
	} catch ( e ) {
		return [];
	}
} )() );

const canPick = typeof window !== 'undefined' && 'EyeDropper' in window;
const swatches = computed( () => [ ...new Set( [ ...recent.value, ...BASE ] ) ].slice( 0, 14 ) );

function toHsv( hex ) {
	const [ r, g, b ] = [ 1, 3, 5 ].map( ( i ) => parseInt( hex.slice( i, i + 2 ), 16 ) / 255 );
	const max = Math.max( r, g, b );
	const d = max - Math.min( r, g, b );
	let h = 0;

	if ( d ) {
		h = max === r ? ( ( g - b ) / d ) % 6 : max === g ? ( b - r ) / d + 2 : ( r - g ) / d + 4;
	}

	return { h: Math.round( ( h * 60 + 360 ) % 360 ), s: max ? d / max : 0, v: max };
}

function toHex( { h, s, v } ) {
	const f = ( n ) => {
		const k = ( n + h / 60 ) % 6;
		return v - v * s * Math.max( 0, Math.min( k, 4 - k, 1 ) );
	};

	return `#${ [ f( 5 ), f( 3 ), f( 1 ) ].map( ( x ) => Math.round( x * 255 ).toString( 16 ).padStart( 2, '0' ) ).join( '' ) }`;
}

watch( () => props.modelValue, ( value ) => {
	if ( HEX.test( value ) && value.toLowerCase() !== toHex( hsv ) ) {
		Object.assign( hsv, toHsv( value ) );
	}
	typed.value = value;
}, { immediate: true } );

function emitHsv() {
	const hex = toHex( hsv );
	typed.value = hex;
	emit( 'update:modelValue', hex );
}

function remember() {
	recent.value = [ props.modelValue.toLowerCase(), ...recent.value.filter( ( c ) => c !== props.modelValue.toLowerCase() ) ].slice( 0, 6 );

	try {
		window.localStorage.setItem( RECENT, JSON.stringify( recent.value ) );
	} catch ( e ) { }
}

function set( hex ) {
	Object.assign( hsv, toHsv( hex ) );
	emitHsv();
	remember();
}

function setHue( h ) {
	hsv.h = h;
	emitHsv();
}

function onType( value ) {
	const v = value.trim().startsWith( '#' ) ? value.trim() : `#${ value.trim() }`;

	if ( HEX.test( v ) ) {
		Object.assign( hsv, toHsv( v ) );
		emit( 'update:modelValue', v.toLowerCase() );
	}
}

function fromPointer( event ) {
	const box = plane.value.getBoundingClientRect();
	hsv.s = Math.max( 0, Math.min( 1, ( event.clientX - box.left ) / box.width ) );
	hsv.v = 1 - Math.max( 0, Math.min( 1, ( event.clientY - box.top ) / box.height ) );
	emitHsv();
}

function startPlane( event ) {
	plane.value.setPointerCapture( event.pointerId );
	fromPointer( event );
}

function movePlane( event ) {
	if ( plane.value.hasPointerCapture( event.pointerId ) ) {
		fromPointer( event );
	}
}

function planeKey( event ) {
	const step = event.shiftKey ? 0.1 : 0.02;
	const moves = { ArrowLeft: [ -step, 0 ], ArrowRight: [ step, 0 ], ArrowUp: [ 0, step ], ArrowDown: [ 0, -step ] };

	if ( moves[ event.key ] ) {
		event.preventDefault();
		hsv.s = Math.max( 0, Math.min( 1, hsv.s + moves[ event.key ][ 0 ] ) );
		hsv.v = Math.max( 0, Math.min( 1, hsv.v + moves[ event.key ][ 1 ] ) );
		emitHsv();
	}
}

async function eyedrop() {
	try {
		const { sRGBHex } = await new window.EyeDropper().open();
		set( sRGBHex );
	} catch ( e ) { }
}

const WIDTH = 248;

function place() {
	if ( !trigger.value ) {
		return;
	}

	const box = trigger.value.getBoundingClientRect();
	const height = panel.value?.offsetHeight ?? 320;
	const below = window.innerHeight - box.bottom;
	const top = below >= height + 12 || below >= box.top ? box.bottom + 6 : box.top - height - 6;

	position.value = {
		top: `${ Math.max( 8, Math.min( top, window.innerHeight - height - 8 ) ) }px`,
		left: `${ Math.max( 8, Math.min( box.left, window.innerWidth - WIDTH - 8 ) ) }px`
	};
}

function onOutside( event ) {
	if ( !panel.value?.contains( event.target ) && !trigger.value?.contains( event.target ) ) {
		shown.value = false;
	}
}

function close() {
	shown.value = false;
	trigger.value?.focus();
}

function toggle() {
	shown.value = !shown.value;
}

watch( shown, async ( open ) => {
	if ( open ) {
		place();
		document.addEventListener( 'pointerdown', onOutside, true );
		window.addEventListener( 'resize', place );
		window.addEventListener( 'scroll', place, true );
		await nextTick();
		place();
		plane.value?.focus();
	} else {
		document.removeEventListener( 'pointerdown', onOutside, true );
		window.removeEventListener( 'resize', place );
		window.removeEventListener( 'scroll', place, true );
		remember();
	}
} );

onBeforeUnmount( () => {
	document.removeEventListener( 'pointerdown', onOutside, true );
	window.removeEventListener( 'resize', place );
	window.removeEventListener( 'scroll', place, true );
} );
</script>
