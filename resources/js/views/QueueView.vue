<template>
	<main class="ts-page">
		<PageHeader
			title="Queue"
			:subtitle="meta.total ? `${ meta.total.toLocaleString() } ${ meta.total === 1 ? 'item matches' : 'items match' }` : ''"
		>
			<template #actions>
				<cdx-button v-if="selected.length" @click="clearSelection">
					Clear {{ selected.length }} selected
				</cdx-button>
			</template>
		</PageHeader>

		<FilterBar
			v-model="filters"
			v-model:search="search"
			v-model:sort="sort"
			:fields="fields"
			:defaults="defaultFilters"
			:sort-options="sortOptions"
			:searchable="true"
			search-placeholder="Search, or try type:report"
			@update:search="debouncedReload"
			@change="onFiltersChanged"
		/>

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
			<cdx-button :disabled="openingEach" @click="openFileForEach">
				{{ openingEach ? 'Opening…' : 'Open a file for each' }}
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
					<span v-if="row.automated" class="ts-meta">
						·
						<router-link :to="{ name: 'automation', query: { case: row.id } }">review in Automation</router-link>
					</span>
					<span v-else-if="row.anonymous" class="ts-meta"> · filed anonymously</span>
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
					<cdx-info-chip
						v-else-if="row.automated && bucket( row.autoreview?.bucket )"
						:status="bucket( row.autoreview.bucket ).chip"
						:title="row.autoreview.reason ?? undefined"
					>
						Jev · {{ bucket( row.autoreview.bucket ).short.toLowerCase() }}
					</cdx-info-chip>
					<cdx-info-chip v-else-if="row.automated" status="notice">
						Jev
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
	CdxButton, CdxInfoChip, CdxProgressBar, CdxSelect, CdxTable
} from '@wikimedia/codex';
import {
	cdxIconAlert, cdxIconClock, cdxIconFlag, cdxIconSearch, cdxIconUserAvatar, cdxIconUserGroup
} from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import FilterBar from '../components/FilterBar.vue';
import OpenInvestigationDialog from '../components/OpenInvestigationDialog.vue';
import { api } from '../lib/api.js';
import { ago, chipTone, dateTime, PRIORITIES, TYPE_LABELS } from '../lib/format.js';
import { bucket } from '../lib/autoreview.js';
import { session } from '../lib/session.js';

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
const openingEach = ref( false );

const SOURCES = [ 'people', 'automated', 'automated:urgent', 'automated:review', 'automated:unlikely', 'automated:waiting' ];
const KINDS = [ 'report', 'appeal', 'contact', 'data' ];

const defaultFilters = { status: 'open' };

function fromRoute() {
	const q = route.query;
	const out = {};

	if ( q.status !== 'any' ) {
		out.status = typeof q.status === 'string' && q.status ? q.status : 'open';
	}

	const assignee = q.assignee ?? ( q.with === 'me' ? 'me' : null );
	if ( assignee ) {
		out.assignee = String( assignee );
	}
	if ( typeof q.priority === 'string' && q.priority ) {
		out.priority = q.priority;
	}
	if ( q.threat ) {
		out.threat = true;
	}
	if ( q.stale ) {
		out.stale = true;
	}
	if ( SOURCES.includes( q.source ) ) {
		out.source = q.source;
	}
	if ( q.file === 'none' ) {
		out.unfiled = true;
	}

	const kinds = String( q.kind ?? q.type ?? '' ).split( ',' ).filter( ( k ) => KINDS.includes( k ) );
	if ( kinds.length ) {
		out.kind = kinds;
	}

	return out;
}

const filters = ref( fromRoute() );
const search = ref( typeof route.query.q === 'string' ? route.query.q : '' );
const sort = ref( typeof route.query.sort === 'string' ? route.query.sort : 'oldest' );
const page = ref( 1 );

const bulk = reactive( { status: null, assignee: null } );

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

const sourceOptions = [
	{ value: 'people', label: 'Filed by people' },
	{ value: 'automated', label: 'Automated' },
	{ value: 'automated:urgent', label: 'Automated: needs review quickly' },
	{ value: 'automated:review', label: 'Automated: needs review' },
	{ value: 'automated:unlikely', label: 'Automated: unlikely to need review' },
	{ value: 'automated:waiting', label: 'Automated: not sorted yet' }
];

const sortOptions = [
	{ value: 'oldest', label: 'Oldest first' },
	{ value: 'newest', label: 'Newest first' },
	{ value: 'updated', label: 'Last change' },
	{ value: 'priority', label: 'Priority' },
	{ value: 'status', label: 'Where it has got to' },
	{ value: 'reference', label: 'Reference' }
];

const fields = computed( () => [
	{ key: 'status', label: 'Status', icon: cdxIconFlag, options: statusOptions },
	{
		key: 'assignee',
		label: 'Assigned to',
		icon: cdxIconUserAvatar,
		options: [
			{ value: 'me', label: 'Me' },
			{ value: 'none', label: 'Nobody yet' },
			...team.value.filter( ( u ) => u.id !== session.user.id ).map( ( u ) => ( { value: String( u.id ), label: u.username } ) )
		]
	},
	{ key: 'priority', label: 'Priority', icon: cdxIconAlert, options: PRIORITIES },
	{ key: 'kind', label: 'Kind', icon: cdxIconSearch, options: typeOptions, multi: true },
	{ key: 'source', label: 'Source', icon: cdxIconUserGroup, options: sourceOptions },
	{ key: 'unfiled', label: 'Not on a file yet', icon: cdxIconUserGroup, boolean: true },
	{ key: 'stale', label: 'Quiet for 14 days', icon: cdxIconClock, boolean: true },
	{ key: 'threat', label: 'Threat to life', icon: cdxIconAlert, boolean: true }
] );

const assigneeOptions = computed( () => [
	{ value: null, label: 'Nobody' },
	...team.value.map( ( u ) => ( { value: u.id, label: u.username } ) )
] );

const firstSelected = computed( () => (
	selected.value.length ? rows.value.find( ( r ) => r.id === selected.value[ 0 ] ) ?? null : null
) );

function apiParams() {
	const f = filters.value;
	const [ source, bucketName ] = f.source ? f.source.split( ':' ) : [ null, null ];
	const params = {
		q: search.value,
		type: ( f.kind ?? [] ).join( ',' ) || null,
		assignee: f.assignee === 'me' ? session.user.id : ( f.assignee ?? null ),
		priority: f.priority ?? null,
		threat: f.threat ? 1 : null,
		stale: f.stale ? 1 : null,
		source: source ?? null,
		bucket: bucketName ?? null,
		investigation: f.unfiled ? 'none' : null,
		sort: sort.value,
		page: page.value
	};

	if ( f.status === 'open' ) {
		params.open = 1;
	} else if ( f.status ) {
		params.status = f.status;
	}

	return params;
}

function syncRoute() {
	const f = filters.value;
	const query = {};

	query.status = f.status ?? 'any';
	if ( query.status === 'open' ) {
		delete query.status;
	}
	if ( f.assignee ) {
		query.assignee = f.assignee;
	}
	if ( f.priority ) {
		query.priority = f.priority;
	}
	if ( f.kind?.length ) {
		query.kind = f.kind.join( ',' );
	}
	if ( f.source ) {
		query.source = f.source;
	}
	if ( f.unfiled ) {
		query.file = 'none';
	}
	if ( f.stale ) {
		query.stale = '1';
	}
	if ( f.threat ) {
		query.threat = '1';
	}
	if ( search.value ) {
		query.q = search.value;
	}
	if ( sort.value !== 'oldest' ) {
		query.sort = sort.value;
	}

	router.replace( { query } );
}

async function reload() {
	loading.value = true;
	error.value = null;

	try {
		const response = await api.cases( apiParams() );
		rows.value = response.data;
		meta.value = response.meta;
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

function onFiltersChanged() {
	page.value = 1;
	clearSelection();
	syncRoute();
	reload();
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
	router.push( { name: 'investigation', params: { id: investigation.id }, hash: '#pages' } );
}

async function openFileForEach() {
	openingEach.value = true;
	try {
		const response = await api.openInvestigationsFromCases( { case_ids: selected.value, mode: 'each' } );
		const skipped = response.skipped.length
			? ` ${ response.skipped.length } already had one: ${ response.skipped.map( ( s ) => s.case ).join( ', ' ) }.`
			: '';
		notify( `${ response.opened.length } file${ response.opened.length === 1 ? '' : 's' } opened.${ skipped }` );
		clearSelection();
		await reload();
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		openingEach.value = false;
	}
}

function clearSelection() {
	selected.value = [];
}

let timer = null;
function debouncedReload() {
	clearTimeout( timer );
	timer = setTimeout( onFiltersChanged, 300 );
}

function go( to ) {
	page.value = to;
	reload();
}

onMounted( () => {
	reload();
	loadTeam();
} );
</script>
