<template>
	<div ref="rootRef" class="ts-fbar">
		<cdx-search-input
			v-if="searchable"
			class="ts-fbar__search"
			:model-value="search"
			:placeholder="searchPlaceholder"
			:aria-label="searchPlaceholder"
			@update:model-value="onSearch"
		/>

		<cdx-button
			v-if="sortOptions.length"
			class="ts-fbar__sort"
			:class="{ 'ts-fbar__btn--open': pop && pop.type === 'sort' }"
			@click="openPop( $event, { type: 'sort' } )"
		>
			<cdx-icon :icon="cdxIconSortVertical" size="small" />
			{{ sortLabel }}
		</cdx-button>

		<span v-if="sortOptions.length && chips.length" class="ts-fbar__sep" aria-hidden="true" />

		<span v-for="chip in chips" :key="chip.field.key" class="ts-fchip-wrap">
			<button
				type="button"
				class="ts-fchip ts-fchip--set"
				:class="{ 'ts-fchip--open': pop && pop.key === chip.field.key }"
				@click="openPop( $event, { type: 'values', key: chip.field.key, editing: true } )"
			>
				<cdx-icon v-if="chip.field.icon" :icon="chip.field.icon" size="small" />
				<span v-if="!chip.field.boolean" class="ts-fchip__key">{{ chip.field.label }}</span>
				<span class="ts-fchip__value">{{ chip.text }}</span>
			</button>
			<button
				type="button"
				class="ts-fchip__remove"
				:aria-label="`Remove the ${ chip.field.label } filter`"
				@click="remove( chip.field.key )"
			>
				<cdx-icon :icon="cdxIconClose" size="x-small" />
			</button>
		</span>

		<cdx-button
			v-if="addable.length"
			class="ts-fbar__add"
			weight="quiet"
			:class="{ 'ts-fbar__btn--open': pop && pop.type === 'fields' }"
			@click="openPop( $event, { type: 'fields' } )"
		>
			<cdx-icon :icon="cdxIconAdd" size="small" />
			Filter
		</cdx-button>

		<span class="ts-fbar__spacer" />

		<cdx-button v-if="dirty" weight="quiet" @click="reset">Reset</cdx-button>

		<div
			v-if="pop"
			ref="popRef"
			class="ts-pop"
			:style="{ left: `${ pos.left }px`, top: `${ pos.top }px` }"
			role="dialog"
			@keydown.esc.stop="closePop"
		>
			<template v-if="pop.type === 'text' || ( pop.type === 'values' && activeField?.text )">
				<form class="ts-pop__text" @submit.prevent="commitText">
					<label class="ts-pop__label" :for="`${ uid }-text`">{{ activeField.label }}</label>
					<cdx-text-input
						:id="`${ uid }-text`"
						ref="inputRef"
						v-model="draft"
						:placeholder="activeField.placeholder ?? ''"
					/>
					<cdx-button type="submit" action="progressive" weight="primary" :disabled="!draft.trim()">Apply</cdx-button>
				</form>
			</template>

			<template v-else>
				<cdx-text-input
					v-if="showSearch"
					ref="inputRef"
					v-model="query"
					class="ts-pop__search"
					:placeholder="popPlaceholder"
					:aria-label="popPlaceholder"
					@keydown.down.prevent="move( 1 )"
					@keydown.up.prevent="move( -1 )"
					@keydown.enter.prevent="pick( shown[ cursor ] )"
				/>

				<ul ref="listRef" class="ts-pop__list" role="listbox" :aria-multiselectable="!!activeField?.multi">
					<li
						v-for="( item, i ) in shown"
						:key="String( item.value )"
						role="option"
						:aria-selected="item.selected"
						class="ts-pop__item"
						:class="{ 'ts-pop__item--active': i === cursor, 'ts-pop__item--selected': item.selected }"
						@mouseenter="cursor = i"
						@click="pick( item )"
					>
						<span v-if="activeField?.multi" class="ts-pop__box" :class="{ 'ts-pop__box--on': item.selected }">
							<cdx-icon v-if="item.selected" :icon="cdxIconCheck" size="x-small" />
						</span>
						<cdx-icon v-else-if="item.icon" :icon="item.icon" size="small" />
						<span class="ts-pop__text-label">{{ item.label }}</span>
						<cdx-icon v-if="!activeField?.multi && item.selected" :icon="cdxIconCheck" size="small" class="ts-pop__tick" />
					</li>
					<li v-if="!shown.length" class="ts-pop__empty">Nothing matches.</li>
				</ul>

				<div v-if="pop.type === 'values' && pop.editing" class="ts-pop__foot">
					<button type="button" class="ts-pop__item ts-pop__item--danger" @click="remove( pop.key )">
						<cdx-icon :icon="cdxIconTrash" size="small" />
						<span>Remove filter</span>
					</button>
				</div>
			</template>
		</div>
	</div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { CdxButton, CdxIcon, CdxSearchInput, CdxTextInput } from '@wikimedia/codex';
import {
	cdxIconAdd, cdxIconCheck, cdxIconClose, cdxIconSearch, cdxIconSortVertical, cdxIconTrash
} from '@wikimedia/codex-icons';

const props = defineProps( {
	fields: { type: Array, required: true },
	modelValue: { type: Object, required: true },
	defaults: { type: Object, default: () => ( {} ) },
	searchable: { type: Boolean, default: false },
	search: { type: String, default: '' },
	searchPlaceholder: { type: String, default: 'Search' },
	sortOptions: { type: Array, default: () => [] },
	sort: { type: String, default: '' }
} );

const emit = defineEmits( [ 'update:modelValue', 'update:search', 'update:sort', 'change' ] );

const uid = `fb${ Math.random().toString( 36 ).slice( 2, 8 ) }`;

const rootRef = ref( null );
const popRef = ref( null );
const inputRef = ref( null );
const listRef = ref( null );

const pop = ref( null );
const pos = ref( { left: 0, top: 0 } );
const query = ref( '' );
const draft = ref( '' );
const cursor = ref( 0 );

const isSet = ( v ) => v !== undefined && v !== null && v !== '' && !( Array.isArray( v ) && !v.length );

const byKey = computed( () => Object.fromEntries( props.fields.map( ( f ) => [ f.key, f ] ) ) );
const activeField = computed( () => ( pop.value?.key ? byKey.value[ pop.value.key ] : null ) );

const chips = computed( () => props.fields
	.filter( ( f ) => isSet( props.modelValue[ f.key ] ) )
	.map( ( f ) => ( { field: f, text: describe( f, props.modelValue[ f.key ] ) } ) ) );

const addable = computed( () => props.fields.filter( ( f ) => !isSet( props.modelValue[ f.key ] ) ) );

const sortLabel = computed( () => props.sortOptions.find( ( o ) => o.value === props.sort )?.label ?? 'Sort' );

const dirty = computed( () => {
	const keys = new Set( [ ...Object.keys( props.modelValue ), ...Object.keys( props.defaults ) ] );
	for ( const key of keys ) {
		if ( JSON.stringify( props.modelValue[ key ] ?? null ) !== JSON.stringify( props.defaults[ key ] ?? null ) ) {
			return true;
		}
	}
	return false;
} );

function describe( field, value ) {
	if ( field.boolean ) {
		return field.label;
	}
	if ( field.text ) {
		return String( value );
	}
	const labels = ( Array.isArray( value ) ? value : [ value ] )
		.map( ( v ) => field.options.find( ( o ) => o.value === v )?.label ?? String( v ) );

	return labels.length > 2 ? `${ labels.length } selected` : labels.join( ', ' );
}

const showSearch = computed( () => {
	if ( pop.value?.type === 'fields' ) {
		return true;
	}
	return pop.value?.type === 'values' && ( activeField.value?.options?.length ?? 0 ) > 7;
} );

const popPlaceholder = computed( () => ( pop.value?.type === 'fields' ? 'Filter by…' : 'Search…' ) );

const shown = computed( () => {
	const needle = query.value.trim().toLowerCase();
	let items = [];

	if ( pop.value?.type === 'fields' ) {
		items = addable.value.map( ( f ) => ( { value: f.key, label: f.label, icon: f.icon } ) );
	} else if ( pop.value?.type === 'sort' ) {
		items = props.sortOptions.map( ( o ) => ( { ...o, selected: o.value === props.sort } ) );
	} else if ( pop.value?.type === 'values' && activeField.value?.options ) {
		const current = props.modelValue[ activeField.value.key ];
		const list = Array.isArray( current ) ? current : [ current ];
		items = activeField.value.options.map( ( o ) => ( { ...o, selected: list.includes( o.value ) } ) );
	}

	return needle ? items.filter( ( i ) => i.label.toLowerCase().includes( needle ) ) : items;
} );

watch( query, () => {
	cursor.value = 0;
} );

function openPop( event, next ) {
	const same = pop.value && pop.value.type === next.type && pop.value.key === next.key;
	if ( same ) {
		closePop();
		return;
	}

	const anchor = ( event.currentTarget ?? event.target.closest( 'button' ) ).getBoundingClientRect();
	const root = rootRef.value.getBoundingClientRect();
	const width = 16 * 16;

	pos.value = {
		left: Math.max( 0, Math.min( anchor.left - root.left, root.width - width ) ),
		top: anchor.bottom - root.top + 6
	};

	query.value = '';
	cursor.value = Math.max( 0, shownFor( next ).findIndex( ( i ) => i.selected ) );
	pop.value = next;

	const field = next.key ? byKey.value[ next.key ] : null;
	if ( field?.text ) {
		draft.value = String( props.modelValue[ field.key ] ?? '' );
	}

	nextTick( focusInput );
}

function shownFor( next ) {
	if ( next.type === 'sort' ) {
		return props.sortOptions.map( ( o ) => ( { selected: o.value === props.sort } ) );
	}
	if ( next.type === 'values' ) {
		const field = byKey.value[ next.key ];
		const current = props.modelValue[ next.key ];
		return ( field?.options ?? [] ).map( ( o ) => ( { selected: o.value === current } ) );
	}
	return [];
}

function focusInput() {
	const el = inputRef.value;
	( el?.focus ? el : el?.$el?.querySelector?.( 'input' ) )?.focus?.();
}

function closePop() {
	pop.value = null;
}

function move( by ) {
	if ( !shown.value.length ) {
		return;
	}
	cursor.value = ( cursor.value + by + shown.value.length ) % shown.value.length;
	nextTick( () => listRef.value?.children[ cursor.value ]?.scrollIntoView?.( { block: 'nearest' } ) );
}

function update( next ) {
	emit( 'update:modelValue', next );
	emit( 'change', next );
}

function pick( item ) {
	if ( !item ) {
		return;
	}

	if ( pop.value.type === 'sort' ) {
		emit( 'update:sort', item.value );
		emit( 'change', props.modelValue );
		closePop();
		return;
	}

	if ( pop.value.type === 'fields' ) {
		const field = byKey.value[ item.value ];

		if ( field.boolean ) {
			update( { ...props.modelValue, [ field.key ]: true } );
			closePop();
			return;
		}

		const rect = rootRef.value.querySelector( '.ts-fbar__add' )?.getBoundingClientRect();
		const root = rootRef.value.getBoundingClientRect();

		pop.value = { type: 'values', key: field.key, editing: false };
		query.value = '';
		cursor.value = 0;
		if ( field.text ) {
			draft.value = '';
		}
		if ( rect ) {
			pos.value = { ...pos.value, left: Math.max( 0, Math.min( rect.left - root.left, root.width - 256 ) ) };
		}
		nextTick( focusInput );
		return;
	}

	const field = activeField.value;

	if ( field.multi ) {
		const current = Array.isArray( props.modelValue[ field.key ] ) ? props.modelValue[ field.key ] : [];
		const next = current.includes( item.value )
			? current.filter( ( v ) => v !== item.value )
			: [ ...current, item.value ];
		const copy = { ...props.modelValue };

		if ( next.length ) {
			copy[ field.key ] = next;
		} else {
			delete copy[ field.key ];
		}
		update( copy );
		return;
	}

	update( { ...props.modelValue, [ field.key ]: item.value } );
	closePop();
}

function commitText() {
	const value = draft.value.trim();
	if ( !value ) {
		return;
	}
	update( { ...props.modelValue, [ pop.value.key ]: value } );
	closePop();
}

function remove( key ) {
	const copy = { ...props.modelValue };
	delete copy[ key ];
	update( copy );
	closePop();
}

function reset() {
	update( { ...props.defaults } );
	emit( 'update:search', '' );
	closePop();
}

function onSearch( value ) {
	emit( 'update:search', value );
}

function onOutside( event ) {
	if ( !pop.value ) {
		return;
	}
	if ( popRef.value?.contains( event.target ) || event.target.closest?.( '.ts-fchip, .ts-fchip__remove, .ts-fbar__sort, .ts-fbar__add' ) ) {
		return;
	}
	closePop();
}

function onKey( event ) {
	if ( event.key === 'Escape' && pop.value ) {
		closePop();
	}
}

onMounted( () => {
	document.addEventListener( 'pointerdown', onOutside );
	document.addEventListener( 'keydown', onKey );
} );

onBeforeUnmount( () => {
	document.removeEventListener( 'pointerdown', onOutside );
	document.removeEventListener( 'keydown', onKey );
} );
</script>
