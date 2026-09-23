<template>
	<main class="ts-page">
		<PageHeader
			title="Queue"
		>
			<template #actions>
				<cdx-button v-if="selected.length" @click="clearSelection">
					Clear {{ selected.length }} selected
				</cdx-button>
			</template>
		</PageHeader>

		<div class="ts-toolbar">
			<cdx-field class="ts-toolbar__search">
				<template #label>Search</template>
				<template #description>
					Words, or conditions like <code>type:report</code> and <code>with:me</code>.
					<a href="#" @click.prevent="showHelp = !showHelp">
						{{ showHelp ? 'Hide what it accepts' : 'What it accepts' }}
					</a>
				</template>
				<cdx-search-input
					v-model="filters.q"
					placeholder="harassment type:report with:me"
					@update:model-value="debouncedReload"
				/>
			</cdx-field>

			<cdx-field>
				<template #label>Status</template>
				<cdx-select v-model:selected="filters.status" :menu-items="statusOptions" @update:selected="reload" />
			</cdx-field>

			<cdx-field>
				<template #label>Assigned</template>
				<cdx-select v-model:selected="filters.assignee" :menu-items="assigneeFilterOptions" @update:selected="reload" />
			</cdx-field>

			<cdx-field>
				<template #label>Priority</template>
				<cdx-select v-model:selected="filters.priority" :menu-items="priorityFilterOptions" @update:selected="reload" />
			</cdx-field>

			<cdx-field>
				<template #label>Risk</template>
				<cdx-select v-model:selected="filters.threat" :menu-items="threatOptions" @update:selected="reload" />
			</cdx-field>

			<cdx-field>
				<template #label>Source</template>
				<cdx-select v-model:selected="filters.source" :menu-items="sourceOptions" @update:selected="reload" />
			</cdx-field>

			<cdx-field>
				<template #label>File</template>
				<cdx-select v-model:selected="filters.investigation" :menu-items="fileOptions" @update:selected="reload" />
			</cdx-field>

			<cdx-field>
				<template #label>Order</template>
				<cdx-select v-model:selected="filters.sort" :menu-items="sortOptions" @update:selected="reload" />
			</cdx-field>
		</div>

		<div class="ts-kinds">
			<span class="ts-kinds__label">Kinds</span>
			<cdx-checkbox
				v-for="kind in typeOptions"
				:key="kind.value"
				v-model="kinds"
				:input-value="kind.value"
				:inline="true"
				@update:model-value="onKindsChanged"
			>
				{{ kind.label }}
			</cdx-checkbox>
			<cdx-button v-if="kinds.length" weight="quiet" size="small" @click="clearKinds">
				All kinds
			</cdx-button>
		</div>

		<div v-if="showHelp" class="ts-panel ts-scroll">
			<dl class="ts-dl">
				<template v-for="row in help" :key="row.prefix">
					<dt><code>{{ row.prefix }}</code></dt>
					<dd>
						{{ row.what }}
						<button type="button" class="ts-linkish" @click="useExample( row.example )">
							<code>{{ row.example }}</code>
						</button>
					</dd>
				</template>
			</dl>
		</div>

		<LoadError :error="error" :retry="reload" />

		<cdx-progress-bar v-if="loading" aria-label="Loading the queue" />

		<div v-if="selected.length" class="ts-panel ts-bulkbar">
			<span class="ts-bulkbar__count">{{ selected.length }} selected</span>

			<cdx-select
				v-model:selected="bulk.status"
				:menu-items="bulkStatusOptions"
				@update:selected="applyBulkStatus"
			/>
			<cdx-select
				v-model:selected="bulk.assignee"
				:menu-items="assigneeOptions"
				@update:selected="applyBulkAssignee"
			/>
			<cdx-button action="progressive" @click="openFileForSelection">
				Open one file for these
			</cdx-button>
		</div>

		<div class="ts-panel ts-scroll ts-queue">
			<cdx-table
				caption="Open Trust and Safety work"
				:hide-caption="true"
				:columns="columns"
				:data="rows"
				:use-row-selection="true"
				v-model:selected-rows="selected"
			>
				<template #item-reference="{ row }">
					<router-link :to="{ name: 'case', params: { id: row.id } }" class="ts-mono">
						{{ row.reference }}
					</router-link>
				</template>

				<template #item-type="{ item }">{{ TYPE_LABELS[ item ] ?? item }}</template>

				<template #item-subject="{ row }">
					<router-link :to="{ name: 'case', params: { id: row.id } }">
						{{ row.subject }}
					</router-link>
					<span v-if="row.anonymous" class="ts-meta"> · filed anonymously</span>
					<div v-if="row.investigation" class="ts-meta">
						<router-link
							:to="{ name: 'investigation', params: { id: row.investigation.id } }"
							class="ts-mono"
						>
							{{ row.investigation.reference }}
						</router-link>
						— {{ row.investigation.title }}
					</div>
				</template>

				<template #item-category="{ row }">
					<cdx-info-chip v-if="row.threat_to_life" status="error">
						Threat to life
					</cdx-info-chip>
					<cdx-info-chip v-else-if="row.automated" status="notice">
						Automated
					</cdx-info-chip>
					<cdx-info-chip v-else-if="primaryCategory( row )">
						{{ primaryCategory( row ) }}
					</cdx-info-chip>
					<span v-else class="ts-meta">—</span>
				</template>
				<template #item-status="{ row }">
					<cdx-select
						:selected="row.status"
						:menu-items="rowStatusOptions"
						class="ts-inline-select ts-chip-select"
						:class="`ts-chip-select--${ chipTone( 'case', row.status ) }`"
						:disabled="busy === row.id"
						@update:selected="( value ) => changeStatus( row, value )"
					/>
				</template>

				<template #item-priority="{ row }">
					<cdx-select
						:selected="row.priority"
						:menu-items="priorityOptions"
						class="ts-inline-select ts-chip-select"
						:class="`ts-chip-select--${ chipTone( 'priority', row.priority ) }`"
						:disabled="busy === row.id"
						@update:selected="( value ) => changePriority( row, value )"
					/>
				</template>

				<template #item-assignee="{ row }">
					<cdx-select
						:selected="row.assignee ? row.assignee.id : null"
						:menu-items="assigneeOptions"
						class="ts-inline-select"
						:disabled="busy === row.id"
						@update:selected="( value ) => changeAssignee( row, value )"
					/>
				</template>

				<template #item-filed="{ item }">
					<span :title="dateTime( item )">{{ ago( item ) }}</span>
				</template>

				<template #empty-state>
					Nothing matches those filters.
				</template>
			</cdx-table>
		</div>

		<div v-if="meta.last_page > 1" class="ts-pager">
			<cdx-button :disabled="meta.current_page <= 1" @click="go( meta.current_page - 1 )">
				Previous
			</cdx-button>
			<span class="ts-meta">Page {{ meta.current_page }} of {{ meta.last_page }} · {{ meta.total }} in total</span>
			<cdx-button :disabled="meta.current_page >= meta.last_page" @click="go( meta.current_page + 1 )">
				Next
			</cdx-button>
		</div>

		<OpenInvestigationDialog
			v-model:open="showOpenFile"
			:from-case="firstSelected"
			@opened="onFileOpened"
		/>
	</main>
</template>

<script setup>
import { computed, inject, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
	CdxButton, CdxCheckbox, CdxField, CdxInfoChip, CdxProgressBar, CdxSearchInput,
	CdxSelect, CdxTable
} from '@wikimedia/codex';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import OpenInvestigationDialog from '../components/OpenInvestigationDialog.vue';
import { api } from '../lib/api.js';
import { ago, chipTone, dateTime, PRIORITIES, TYPE_LABELS } from '../lib/format.js';

const route = useRoute();
const router = useRouter();
const notify = inject( 'notify' );

const rows = ref( [] );
const meta = ref( { current_page: 1, last_page: 1, total: 0 } );
const loading = ref( false );
const error = ref( null );
const team = ref( [] );
const busy = ref( null );
const selected = ref( [] );
const showOpenFile = ref( false );
const showHelp = ref( false );
const help = ref( [] );

const kinds = ref(
	typeof route.query.type === 'string' ? route.query.type.split( ',' ).filter( Boolean ) : []
);

const filters = reactive( {
	q: initialSearch(),
	status: 'open',
	assignee: null,
	priority: null,
	threat: route.query.threat ? 1 : null,
	source: [ 'people', 'automated' ].includes( route.query.source ) ? route.query.source : null,
	investigation: route.query.file === 'none' ? 'none' : null,
	sort: 'oldest',
	page: 1
} );

const bulk = reactive( { status: null, assignee: null } );

function initialSearch() {
	const parts = [];

	if ( typeof route.query.q === 'string' && route.query.q ) {
		parts.push( route.query.q );
	}
	if ( route.query.with === 'me' ) {
		parts.push( 'with:me' );
	}
	if ( route.query.stale === '1' ) {
		parts.push( 'is:stale' );
	}

	return parts.join( ' ' );
}

const columns = [
	{ id: 'reference', label: 'Ref', width: '7.5rem' },
	{ id: 'type', label: 'Kind', width: '5.5rem' },
	{ id: 'subject', label: 'About' },
	{ id: 'category', label: 'Category', width: '10rem' },
	{ id: 'status', label: 'Status', width: '9rem' },
	{ id: 'priority', label: 'Priority', width: '6.5rem' },
	{ id: 'assignee', label: 'With', width: '8rem' },
	{ id: 'filed', label: 'Filed', width: '6.5rem' }
];

const typeOptions = [
	{ value: 'report', label: 'Reports' },
	{ value: 'appeal', label: 'Appeals' },
	{ value: 'contact', label: 'Messages' },
	{ value: 'data', label: 'Data protection' }
];

const statusOptions = [
	{ value: 'open', label: 'Open' },
	{ value: '', label: 'Any' },
	{ value: 'received', label: 'Received' },
	{ value: 'in-review', label: 'Being read' },
	{ value: 'investigating', label: 'Under investigation' },
	{ value: 'action-taken', label: 'Action taken' },
	{ value: 'closed,rejected', label: 'Closed' },
	{ value: 'duplicate', label: 'Duplicates' }
];

const rowStatusOptions = [
	{ value: 'received', label: 'Received' },
	{ value: 'in-review', label: 'Being read' },
	{ value: 'investigating', label: 'Under investigation' },
	{ value: 'action-taken', label: 'Action taken' },
	{ value: 'closed', label: 'Closed' },
	{ value: 'rejected', label: 'Closed, no action' },
	{ value: 'duplicate', label: 'Duplicate', disabled: true }
];

const bulkStatusOptions = [
	{ value: null, label: 'Set status…' },
	...rowStatusOptions.filter( ( o ) => o.value !== 'duplicate' )
];

const priorityOptions = PRIORITIES;

const threatOptions = [
	{ value: null, label: 'Any' },
	{ value: 1, label: 'Threat to life' }
];

const sourceOptions = [
	{ value: null, label: 'Any' },
	{ value: 'people', label: 'Filed by people' },
	{ value: 'automated', label: 'Automated' }
];

const priorityFilterOptions = [
	{ value: null, label: 'Any' },
	...PRIORITIES
];

const fileOptions = [
	{ value: null, label: 'Any' },
	{ value: 'none', label: 'No file yet' }
];

const sortOptions = [
	{ value: 'oldest', label: 'Oldest first' },
	{ value: 'newest', label: 'Newest first' },
	{ value: 'updated', label: 'Last change' },
	{ value: 'priority', label: 'Priority' },
	{ value: 'status', label: 'Where it has got to' },
	{ value: 'reference', label: 'Reference' }
];

const assigneeFilterOptions = computed( () => [
	{ value: null, label: 'Anyone' },
	{ value: 'none', label: 'Nobody yet' },
	...team.value.map( ( u ) => ( { value: u.id, label: u.username } ) )
] );

const assigneeOptions = computed( () => [
	{ value: null, label: 'Nobody' },
	...team.value.map( ( u ) => ( { value: u.id, label: u.username } ) )
] );

const firstSelected = computed( () => (
	selected.value.length ? rows.value.find( ( r ) => r.id === selected.value[ 0 ] ) ?? null : null
) );

async function reload() {
	loading.value = true;
	error.value = null;

	const params = {
		q: filters.q,
		type: kinds.value.join( ',' ),
		assignee: filters.assignee,
		priority: filters.priority,
		threat: filters.threat,
		source: filters.source,
		investigation: filters.investigation,
		sort: filters.sort,
		page: filters.page
	};

	if ( filters.status === 'open' ) {
		params.open = 1;
	} else if ( filters.status ) {
		params.status = filters.status;
	}

	try {
		const response = await api.cases( params );
		rows.value = response.data;
		meta.value = response.meta;
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

async function loadTeam() {
	try {
		const response = await api.staff();
		team.value = response.data.filter( ( u ) => u.active && u.flags.includes( 'ts' ) );
	} catch ( e ) {
		team.value = [];
	}
}

async function patchRow( row, body, said ) {
	busy.value = row.id;
	try {
		const response = await api.updateCase( row.id, body );
		const index = rows.value.findIndex( ( r ) => r.id === row.id );
		if ( index !== -1 ) {
			rows.value[ index ] = response.data;
		}
		notify( said );
	} catch ( e ) {
		notify( e.message, 'error' );
		await reload();
	} finally {
		busy.value = null;
	}
}

function primaryCategory( row ) {
	const categories = row.categories ?? [];

	return ( categories.find( ( c ) => c.primary ) ?? categories[ 0 ] )?.label ?? null;
}

function changeStatus( row, value ) {
	if ( !value || value === row.status ) {
		return;
	}
	return patchRow( row, { status: value }, `${ row.reference } moved on. The reporter has been told.` );
}

function changePriority( row, value ) {
	if ( !value || value === row.priority ) {
		return;
	}
	return patchRow( row, { priority: value }, `${ row.reference } is now ${ value }.` );
}

function changeAssignee( row, value ) {
	if ( value === ( row.assignee?.id ?? null ) ) {
		return;
	}
	return patchRow( row, { assigned_to: value }, value ? `${ row.reference } reassigned.` : `${ row.reference } unassigned.` );
}

function selectedRows() {
	return rows.value.filter( ( r ) => selected.value.includes( r.id ) );
}

async function applyBulkStatus( value ) {
	if ( !value ) {
		return;
	}
	for ( const row of selectedRows() ) {
		await patchRow( row, { status: value }, `${ row.reference } moved on.` );
	}
	bulk.status = null;
	clearSelection();
}

async function applyBulkAssignee( value ) {
	for ( const row of selectedRows() ) {
		await patchRow( row, { assigned_to: value }, `${ row.reference } reassigned.` );
	}
	bulk.assignee = null;
	clearSelection();
}

function openFileForSelection() {
	showOpenFile.value = true;
}

async function onFileOpened( investigation ) {
	const rest = selectedRows().filter( ( r ) => r.id !== firstSelected.value?.id );

	for ( const row of rest ) {
		try {
			await api.attachCase( investigation.id, { case_id: row.id } );
		} catch ( e ) {
			notify( `${ row.reference } could not be attached: ${ e.message }`, 'error' );
		}
	}

	clearSelection();
	router.push( { name: 'investigation', params: { id: investigation.id } } );
}

function clearSelection() {
	selected.value = [];
}

let timer = null;
function debouncedReload() {
	clearTimeout( timer );
	timer = setTimeout( () => {
		filters.page = 1;
		router.replace( { query: filters.q ? { q: filters.q } : {} } );
		reload();
	}, 300 );
}

function go( page ) {
	filters.page = page;
	reload();
}

function onKindsChanged() {
	filters.page = 1;
	reload();
}

function clearKinds() {
	kinds.value = [];
	onKindsChanged();
}

function useExample( example ) {
	filters.q = filters.q.trim() ? `${ filters.q.trim() } ${ example }` : example;
	filters.page = 1;
	reload();
}

async function loadHelp() {
	try {
		const response = await api.searchHelp();
		help.value = response.data;
	} catch ( e ) {
		help.value = [];
	}
}

onMounted( () => {
	reload();
	loadTeam();
	loadHelp();
} );
</script>
