<template>
	<div ref="rootRef" class="ts-date">
		<cdx-text-input
			ref="inputRef"
			v-model="draft"
			input-type="text"
			inputmode="numeric"
			autocomplete="off"
			:placeholder="placeholder || 'YYYY-MM-DD'"
			:aria-label="ariaLabel || placeholder"
			:disabled="disabled"
			:status="invalid ? 'error' : 'default'"
			:start-icon="cdxIconCalendar"
			:clearable="clearable"
			@focus="openPop"
			@click="openPop"
			@input="onType"
			@blur="onBlur"
			@keydown.down.prevent="openPop"
			@keydown.esc="close"
		/>

		<div
			v-if="isOpen"
			ref="popRef"
			class="ts-date__pop"
			:style="popStyle"
			role="dialog"
			aria-label="Choose a date"
			@keydown.esc.stop.prevent="close"
		>
			<div class="ts-date__head">
				<cdx-button weight="quiet" aria-label="Previous month" @click="shiftMonth( -1 )">
					<cdx-icon :icon="cdxIconPrevious" />
				</cdx-button>
				<span class="ts-date__month" aria-live="polite">{{ monthLabel }}</span>
				<cdx-button weight="quiet" aria-label="Next month" @click="shiftMonth( 1 )">
					<cdx-icon :icon="cdxIconNext" />
				</cdx-button>
			</div>

			<div class="ts-date__grid" role="grid" @keydown="onKey">
				<span v-for="d in weekdays" :key="d" class="ts-date__dow" role="columnheader">{{ d }}</span>
				<button
					v-for="cell in cells"
					:key="cell.iso"
					type="button"
					role="gridcell"
					class="ts-date__day"
					:class="{
						'ts-date__day--other': !cell.inMonth,
						'ts-date__day--today': cell.iso === todayIso,
						'ts-date__day--selected': cell.iso === modelValue
					}"
					:tabindex="cell.iso === focusIso ? 0 : -1"
					:data-iso="cell.iso"
					:disabled="cell.disabled"
					:aria-selected="cell.iso === modelValue"
					:aria-label="longDate( cell.iso )"
					@click="choose( cell.iso )"
				>
					{{ cell.day }}
				</button>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { CdxButton, CdxIcon, CdxTextInput } from '@wikimedia/codex';
import { cdxIconCalendar, cdxIconNext, cdxIconPrevious } from '@wikimedia/codex-icons';

const props = defineProps( {
	modelValue: { type: String, default: '' },
	placeholder: { type: String, default: 'Pick a date' },
	ariaLabel: { type: String, default: '' },
	min: { type: String, default: '' },
	max: { type: String, default: '' },
	clearable: { type: Boolean, default: true },
	disabled: { type: Boolean, default: false }
} );

const emit = defineEmits( [ 'update:modelValue' ] );

const rootRef = ref( null );
const inputRef = ref( null );
const draft = ref( props.modelValue );
const invalid = ref( false );
const popRef = ref( null );
const isOpen = ref( false );
const viewYear = ref( 0 );
const viewMonth = ref( 0 );
const focusIso = ref( '' );
const popStyle = ref( {} );

const pad = ( n ) => String( n ).padStart( 2, '0' );
const toIso = ( d ) => `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
const fromIso = ( iso ) => {
	const [ y, m, d ] = iso.split( '-' ).map( Number );
	return new Date( y, m - 1, d );
};
const addDays = ( iso, n ) => {
	const d = fromIso( iso );
	d.setDate( d.getDate() + n );
	return toIso( d );
};

const todayIso = toIso( new Date() );

const weekStart = 1;
const weekdays = Array.from( { length: 7 }, ( _, i ) => new Intl.DateTimeFormat( undefined, { weekday: 'narrow' } )
	.format( new Date( 2024, 0, 1 + ( ( i + weekStart - 1 ) % 7 ) ) ) );

const monthFormat = new Intl.DateTimeFormat( undefined, { month: 'long', year: 'numeric' } );
const shortFormat = new Intl.DateTimeFormat( undefined, { day: 'numeric', month: 'short', year: 'numeric' } );
const longFormat = new Intl.DateTimeFormat( undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } );

const display = ( iso ) => shortFormat.format( fromIso( iso ) );
const longDate = ( iso ) => longFormat.format( fromIso( iso ) );

const monthLabel = computed( () => monthFormat.format( new Date( viewYear.value, viewMonth.value, 1 ) ) );

const cells = computed( () => {
	const first = new Date( viewYear.value, viewMonth.value, 1 );
	const offset = ( first.getDay() - weekStart + 7 ) % 7;
	const start = new Date( viewYear.value, viewMonth.value, 1 - offset );
	const out = [];

	for ( let i = 0; i < 42; i++ ) {
		const d = new Date( start.getFullYear(), start.getMonth(), start.getDate() + i );
		const iso = toIso( d );
		out.push( {
			iso,
			day: d.getDate(),
			inMonth: d.getMonth() === viewMonth.value,
			disabled: ( props.min && iso < props.min ) || ( props.max && iso > props.max )
		} );
	}

	return out;
} );

function show( iso ) {
	const d = iso ? fromIso( iso ) : new Date();
	viewYear.value = d.getFullYear();
	viewMonth.value = d.getMonth();
}

function openPop() {
	if ( isOpen.value || props.disabled ) {
		return;
	}

	show( props.modelValue );
	focusIso.value = props.modelValue || todayIso;
	place();
	isOpen.value = true;
}

const valid = ( iso ) => /^\d{4}-\d{2}-\d{2}$/.test( iso ) && toIso( fromIso( iso ) ) === iso
	&& !( props.min && iso < props.min ) && !( props.max && iso > props.max );

function onType() {
	const text = draft.value.trim();

	if ( text === '' ) {
		invalid.value = false;
		if ( props.modelValue ) {
			emit( 'update:modelValue', '' );
		}
		return;
	}

	if ( valid( text ) ) {
		invalid.value = false;
		emit( 'update:modelValue', text );
		show( text );
		focusIso.value = text;
	}
}

function onBlur() {
	const text = draft.value.trim();
	invalid.value = text !== '' && !valid( text );
	if ( !invalid.value ) {
		draft.value = props.modelValue;
	}
}

function place() {
	const rect = rootRef.value.getBoundingClientRect();
	const width = 272;
	const height = 340;
	const below = window.innerHeight - rect.bottom;
	const top = below >= height + 8 || rect.top < height + 8
		? Math.min( rect.bottom + 4, Math.max( 8, window.innerHeight - height - 8 ) )
		: rect.top - height - 4;

	popStyle.value = {
		left: `${ Math.max( 8, Math.min( rect.left, window.innerWidth - width - 8 ) ) }px`,
		top: `${ top }px`
	};
}

function onScroll( event ) {
	if ( isOpen.value && !popRef.value?.contains( event.target ) ) {
		close();
	}
}

function close() {
	isOpen.value = false;
}

function choose( iso ) {
	emit( 'update:modelValue', iso );
	draft.value = iso;
	invalid.value = false;
	close();
}

function shiftMonth( by ) {
	const d = new Date( viewYear.value, viewMonth.value + by, 1 );
	viewYear.value = d.getFullYear();
	viewMonth.value = d.getMonth();
}

function focusDay() {
	popRef.value?.querySelector( `[data-iso="${ focusIso.value }"]` )?.focus();
}

function onKey( event ) {
	const moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
	const step = moves[ event.key ];

	if ( step === undefined ) {
		if ( event.key === 'PageDown' || event.key === 'PageUp' ) {
			event.preventDefault();
			const d = fromIso( focusIso.value );
			d.setMonth( d.getMonth() + ( event.key === 'PageDown' ? 1 : -1 ) );
			focusIso.value = toIso( d );
			show( focusIso.value );
			nextTick( focusDay );
		}
		return;
	}

	event.preventDefault();
	focusIso.value = addDays( focusIso.value, step );
	show( focusIso.value );
	nextTick( focusDay );
}

function onOutside( event ) {
	if ( isOpen.value && !rootRef.value?.contains( event.target ) ) {
		close();
	}
}

watch( () => props.modelValue, ( value ) => {
	draft.value = value;
	if ( value ) {
		show( value );
	}
} );

onMounted( () => {
	document.addEventListener( 'pointerdown', onOutside );
	window.addEventListener( 'scroll', onScroll, true );
	window.addEventListener( 'resize', place );
} );

onBeforeUnmount( () => {
	document.removeEventListener( 'pointerdown', onOutside );
	window.removeEventListener( 'scroll', onScroll, true );
	window.removeEventListener( 'resize', place );
} );
</script>
