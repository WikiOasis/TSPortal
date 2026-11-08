<template>
	<cdx-dialog
		:open="open"
		class="ts-crop-dialog"
		:title="title"
		:use-close-button="true"
		:primary-action="{ label: working ? 'Saving…' : 'Use this picture', actionType: 'progressive', disabled: !ready || working }"
		:default-action="{ label: 'Cancel' }"
		@update:open="$emit( 'update:open', $event )"
		@primary="confirm"
		@default="$emit( 'update:open', false )"
	>
		<div
			class="ts-crop"
			:class="{ 'ts-crop--round': round }"
			:style="{ width: `${ VIEW_W }px`, height: `${ viewH }px` }"
			tabindex="0"
			role="application"
			aria-label="Drag to move the picture, use the slider or scroll to zoom"
			@pointerdown="down"
			@pointermove="move"
			@pointerup="up"
			@pointercancel="up"
			@wheel.prevent="wheel"
			@keydown="key"
		>
			<img v-if="src" :src="src" alt="" draggable="false" :style="imgStyle" @load="loaded">
			<span class="ts-crop__mask" />
		</div>

		<label class="ts-crop__zoom">
			<span>Zoom</span>
			<input v-model.number="zoom" type="range" min="1" max="4" step="0.01" aria-label="Zoom" @input="clamp">
		</label>

		<p class="ts-meta">Drag to position it. Animated pictures are saved as a still.</p>
	</cdx-dialog>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { CdxDialog } from '@wikimedia/codex';
import { encode } from '../lib/image.js';

const props = defineProps( {
	open: { type: Boolean, default: false },
	file: { type: Object, default: null },
	title: { type: String, default: 'Crop your picture' },
	aspect: { type: Number, default: 1 },
	round: { type: Boolean, default: false },
	outputWidth: { type: Number, default: 512 }
} );
const emit = defineEmits( [ 'update:open', 'cropped' ] );

const VIEW_W = 320;
const viewH = computed( () => Math.round( VIEW_W / props.aspect ) );

const src = ref( '' );
const natural = ref( { w: 0, h: 0 } );
const zoom = ref( 1 );
const centre = ref( { x: 0, y: 0 } );
const ready = ref( false );
const working = ref( false );
let image = null;

function onEscape( event ) {
	if ( event.key === 'Escape' ) {
		event.stopPropagation();
		emit( 'update:open', false );
	}
}

watch( () => props.open, ( open ) => {
	if ( open ) {
		window.addEventListener( 'keydown', onEscape, true );
	} else {
		window.removeEventListener( 'keydown', onEscape, true );
	}
}, { immediate: true } );

onBeforeUnmount( () => window.removeEventListener( 'keydown', onEscape, true ) );
let drag = null;

const base = computed( () => ( natural.value.w ? Math.max( VIEW_W / natural.value.w, viewH.value / natural.value.h ) : 1 ) );
const scale = computed( () => base.value * zoom.value );

const imgStyle = computed( () => ( {
	width: `${ natural.value.w * scale.value }px`,
	height: `${ natural.value.h * scale.value }px`,
	transform: `translate( ${ VIEW_W / 2 - centre.value.x * scale.value }px, ${ viewH.value / 2 - centre.value.y * scale.value }px )`
} ) );

function release() {
	if ( src.value ) {
		URL.revokeObjectURL( src.value );
	}
	src.value = '';
	ready.value = false;
}

watch( () => [ props.open, props.file ], () => {
	release();

	if ( props.open && props.file ) {
		src.value = URL.createObjectURL( props.file );
	}
}, { immediate: true } );

onBeforeUnmount( release );

function loaded( event ) {
	image = event.target;
	natural.value = { w: image.naturalWidth, h: image.naturalHeight };
	zoom.value = 1;
	centre.value = { x: natural.value.w / 2, y: natural.value.h / 2 };
	ready.value = true;
}

function clamp() {
	const halfW = VIEW_W / 2 / scale.value;
	const halfH = viewH.value / 2 / scale.value;

	centre.value = {
		x: Math.max( halfW, Math.min( natural.value.w - halfW, centre.value.x ) ),
		y: Math.max( halfH, Math.min( natural.value.h - halfH, centre.value.y ) )
	};
}

function down( event ) {
	event.currentTarget.setPointerCapture( event.pointerId );
	drag = { x: event.clientX, y: event.clientY };
}

function move( event ) {
	if ( !drag ) {
		return;
	}
	centre.value = {
		x: centre.value.x - ( event.clientX - drag.x ) / scale.value,
		y: centre.value.y - ( event.clientY - drag.y ) / scale.value
	};
	drag = { x: event.clientX, y: event.clientY };
	clamp();
}

const up = () => {
	drag = null;
};

function wheel( event ) {
	zoom.value = Math.max( 1, Math.min( 4, zoom.value - event.deltaY * 0.002 ) );
	clamp();
}

function key( event ) {
	const step = 12 / scale.value;
	const moves = { ArrowLeft: [ -step, 0 ], ArrowRight: [ step, 0 ], ArrowUp: [ 0, -step ], ArrowDown: [ 0, step ] };

	if ( moves[ event.key ] ) {
		event.preventDefault();
		centre.value = { x: centre.value.x + moves[ event.key ][ 0 ], y: centre.value.y + moves[ event.key ][ 1 ] };
		clamp();
	}
}

async function confirm() {
	if ( !ready.value ) {
		return;
	}

	working.value = true;

	try {
		const w = props.outputWidth;
		const h = Math.round( w / props.aspect );
		const canvas = document.createElement( 'canvas' );
		canvas.width = w;
		canvas.height = h;

		const sw = VIEW_W / scale.value;
		const sh = viewH.value / scale.value;
		const ctx = canvas.getContext( '2d' );
		ctx.imageSmoothingQuality = 'high';
		ctx.drawImage( image, centre.value.x - sw / 2, centre.value.y - sh / 2, sw, sh, 0, 0, w, h );

		const blob = await encode( canvas, 'image/webp', 0.9 );
		const ext = blob.type === 'image/webp' ? 'webp' : 'png';

		emit( 'cropped', new File( [ blob ], `picture.${ ext }`, { type: blob.type } ) );
		emit( 'update:open', false );
	} finally {
		working.value = false;
	}
}
</script>
