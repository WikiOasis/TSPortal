<template>
	<cdx-dialog
		:open="open"
		title="Search"
		subtitle="Jump to anything.."
		:use-close-button="true"
		class="ts-quicksearch"
		@update:open="close"
	>
		<div class="ts-quicksearch__body">
			<cdx-search-input
				ref="inputRef"
				v-model="q"
				placeholder="Search everything, or a reference like TS-2026-0481"
				aria-label="Search"
				@keydown="onKeydown"
			/>

			<div class="ts-inline ts-quicksearch__kinds">
				<cdx-button
					v-for="kind in KINDS"
					:key="kind.value ?? 'all'"
					size="small"
					:weight="type === kind.value ? 'primary' : 'normal'"
					:action="type === kind.value ? 'progressive' : 'default'"
					@click="type = kind.value"
				>
					{{ kind.label }}
				</cdx-button>
			</div>

			<cdx-progress-bar v-if="loading" aria-label="Searching" />

			<ul v-if="results.length" class="ts-quicksearch__results" role="listbox">
				<li
					v-for="( row, i ) in results"
					:key="`${ row.route }:${ row.id }`"
					role="option"
					:aria-selected="i === active"
					class="ts-quicksearch__result"
					:class="{ 'ts-quicksearch__result--active': i === active }"
					@mouseenter="active = i"
					@click="go( row )"
				>
					<span class="ts-quicksearch__result-kind">{{ row.type_label }}</span>
					<span class="ts-quicksearch__result-label">{{ row.label || row.reference }}</span>
					<span v-if="row.reference" class="ts-mono ts-meta">{{ row.reference }}</span>
				</li>
			</ul>

			<p v-else-if="searched && !loading" class="ts-empty">
				Nothing matches “{{ q.trim() }}”{{ typeLabel ? ` in ${ typeLabel }` : '' }}.
			</p>

			<p v-else class="ts-empty">
				Type a name, a phrase from a report, or a reference number.
			</p>
		</div>
	</cdx-dialog>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { CdxButton, CdxDialog, CdxProgressBar, CdxSearchInput } from '@wikimedia/codex';
import { api } from '../lib/api.js';

const props = defineProps( {
	open: { type: Boolean, default: false }
} );

const emit = defineEmits( [ 'update:open' ] );
const router = useRouter();

const KINDS = [
	{ value: null, label: 'Everything' },
	{ value: 'investigation', label: 'Files' },
	{ value: 'case', label: 'Reports' },
	{ value: 'sanction', label: 'Actions' },
	{ value: 'removal', label: 'Erasures' },
	{ value: 'subject', label: 'Accounts' }
];

const q = ref( '' );
const type = ref( null );
const results = ref( [] );
const loading = ref( false );
const searched = ref( false );
const active = ref( 0 );
const inputRef = ref( null );

const typeLabel = computed( () => KINDS.find( ( k ) => k.value === type.value )?.label ?? null );

let timer = null;
let requestId = 0;

function runSearch() {
	clearTimeout( timer );

	const term = q.value.trim();
	if ( term.length < 2 ) {
		results.value = [];
		searched.value = false;
		loading.value = false;
		return;
	}

	loading.value = true;
	timer = setTimeout( async () => {
		const id = ++requestId;
		try {
			const response = await api.findObjects( term, { type: type.value, limit: 15 } );
			if ( id !== requestId ) {
				return;
			}
			results.value = response.data;
			active.value = 0;
		} catch ( e ) {
			results.value = [];
		} finally {
			if ( id === requestId ) {
				loading.value = false;
				searched.value = true;
			}
		}
	}, 200 );
}

watch( q, runSearch );
watch( type, runSearch );

watch( () => props.open, ( isOpen ) => {
	if ( !isOpen ) {
		return;
	}

	q.value = '';
	type.value = null;
	results.value = [];
	searched.value = false;
	active.value = 0;

	nextTick( () => inputRef.value?.focus?.() );
} );

function onGlobalKeydown( e ) {
	if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 'k' ) {
		e.preventDefault();
		emit( 'update:open', true );
	}
}

onMounted( () => window.addEventListener( 'keydown', onGlobalKeydown ) );
onUnmounted( () => window.removeEventListener( 'keydown', onGlobalKeydown ) );

function close( value ) {
	emit( 'update:open', value );
}

function go( row ) {
	if ( !row.route ) {
		return;
	}

	router.push( row.id !== null && row.id !== undefined
		? { name: row.route, params: { id: row.id } }
		: { name: row.route } );

	close( false );
}

function onKeydown( e ) {
	if ( e.key === 'ArrowDown' ) {
		e.preventDefault();
		if ( results.value.length ) {
			active.value = ( active.value + 1 ) % results.value.length;
		}
	} else if ( e.key === 'ArrowUp' ) {
		e.preventDefault();
		if ( results.value.length ) {
			active.value = ( active.value - 1 + results.value.length ) % results.value.length;
		}
	} else if ( e.key === 'Enter' ) {
		e.preventDefault();
		const row = results.value[ active.value ];
		if ( row ) {
			go( row );
		}
	} else if ( e.key === 'Escape' ) {
		close( false );
	}
}
</script>
