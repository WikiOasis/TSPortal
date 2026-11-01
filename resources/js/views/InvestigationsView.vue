<template>
	<main class="ts-page">
		<PageHeader
			title="Investigations"
			:subtitle="meta.total ? `${ meta.total.toLocaleString() } ${ meta.total === 1 ? 'file matches' : 'files match' }` : ''"
		>
			<template #actions>
				<cdx-button action="progressive" weight="primary" @click="showOpen = true">
					<cdx-icon :icon="cdxIconAdd" size="small" />
					Open a file
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
			search-placeholder="Reference, title or premise"
			@update:search="debouncedReload"
			@change="onFiltersChanged"
		/>

		<LoadError :error="error" :retry="reload" />
		<cdx-progress-bar v-if="loading" aria-label="Loading investigations" />

		<div class="ts-panel ts-scroll">
			<cdx-table
				caption="Investigations"
				:hide-caption="true"
				:columns="columns"
				:data="rows"
			>
				<template #item-reference="{ row }">
					<router-link :to="{ name: 'investigation', params: { id: row.id } }" class="ts-mono">
						{{ row.reference }}
					</router-link>
				</template>

				<template #item-title="{ row }">
					<router-link :to="{ name: 'investigation', params: { id: row.id } }">
						{{ row.title }}
					</router-link>
					<div v-if="row.review_at && row.live" class="ts-meta">
						Review {{ ago( row.review_at ) }}
					</div>
				</template>

				<template #item-status="{ row }">
					<div class="ts-inline">
						<StatusChip kind="investigation" :value="row.status" />
						<StatusChip v-if="row.outcome" kind="outcome" :value="row.outcome" />
					</div>
				</template>

				<template #item-priority="{ item }">
					<StatusChip kind="priority" :value="item" />
				</template>

				<template #item-counts="{ row }">
					<span class="ts-meta">
						{{ row.counts.cases }} report(s) · {{ row.counts.sanctions }} action(s)
					</span>
				</template>

				<template #item-assignee="{ item }">{{ item ? item.username : '—' }}</template>

				<template #item-opened="{ item }">
					<span :title="dateTime( item )">{{ ago( item ) }}</span>
				</template>

				<template #empty-state>
					Nothing matches those filters.
				</template>
			</cdx-table>
		</div>

		<div v-if="meta.last_page > 1" class="ts-pager">
			<cdx-button :disabled="meta.current_page <= 1" @click="go( meta.current_page - 1 )">Previous</cdx-button>
			<span class="ts-meta">Page {{ meta.current_page }} of {{ meta.last_page }} · {{ meta.total }} in total</span>
			<cdx-button :disabled="meta.current_page >= meta.last_page" @click="go( meta.current_page + 1 )">Next</cdx-button>
		</div>

		<OpenInvestigationDialog v-model:open="showOpen" @opened="onOpened" />
	</main>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { CdxButton, CdxIcon, CdxProgressBar, CdxTable } from '@wikimedia/codex';
import { cdxIconAdd, cdxIconClock, cdxIconFlag, cdxIconUserAvatar } from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import FilterBar from '../components/FilterBar.vue';
import LoadError from '../components/LoadError.vue';
import StatusChip from '../components/StatusChip.vue';
import OpenInvestigationDialog from '../components/OpenInvestigationDialog.vue';
import { api } from '../lib/api.js';
import { ago, dateTime } from '../lib/format.js';
import { session } from '../lib/session.js';

const route = useRoute();
const router = useRouter();

const rows = ref( [] );
const meta = ref( { current_page: 1, last_page: 1, total: 0 } );
const loading = ref( false );
const error = ref( null );
const showOpen = ref( false );

const STATUSES = [ 'live', 'open', 'monitoring', 'concluded', 'closed' ];

const defaultFilters = { status: 'live' };

function fromRoute() {
	const q = route.query;
	const out = {};

	if ( q.status !== 'any' ) {
		out.status = STATUSES.includes( q.status ) ? q.status : 'live';
	}
	if ( q.assignee ) {
		out.assignee = String( q.assignee );
	}
	if ( q.due ) {
		out.due = true;
	}

	return out;
}

const filters = ref( fromRoute() );
const search = ref( typeof route.query.q === 'string' ? route.query.q : '' );
const sort = ref( typeof route.query.sort === 'string' ? route.query.sort : ( route.query.due ? 'review' : 'updated' ) );
const page = ref( 1 );
const team = ref( [] );

const columns = [
	{ id: 'reference', label: 'Reference', width: '9rem' },
	{ id: 'title', label: 'What' },
	{ id: 'status', label: 'State', width: '14rem' },
	{ id: 'priority', label: 'Priority', width: '7rem' },
	{ id: 'counts', label: 'On the file', width: '12rem' },
	{ id: 'assignee', label: 'With', width: '9rem' },
	{ id: 'opened', label: 'Opened', width: '9rem' }
];

const statusOptions = [
	{ value: 'live', label: 'Open and watching' },
	{ value: 'open', label: 'Open' },
	{ value: 'monitoring', label: 'Watching' },
	{ value: 'concluded', label: 'Concluded' },
	{ value: 'closed', label: 'Closed' }
];

const sortOptions = [
	{ value: 'updated', label: 'Last change' },
	{ value: 'review', label: 'Review date' },
	{ value: 'priority', label: 'Priority' },
	{ value: 'oldest', label: 'Oldest first' },
	{ value: 'newest', label: 'Newest first' }
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
	{ key: 'due', label: 'Due a second look', icon: cdxIconClock, boolean: true }
] );

async function loadTeam() {
	try {
		team.value = ( await api.staff() ).data.filter( ( u ) => u.active && u.flags.includes( 'ts' ) );
	} catch ( e ) {
		team.value = [];
	}
}

function syncRoute() {
	const f = filters.value;
	const query = {};

	query.status = f.status ?? 'any';
	if ( query.status === 'live' ) {
		delete query.status;
	}
	if ( f.assignee ) {
		query.assignee = f.assignee;
	}
	if ( f.due ) {
		query.due = '1';
	}
	if ( search.value ) {
		query.q = search.value;
	}
	if ( sort.value !== 'updated' ) {
		query.sort = sort.value;
	}

	router.replace( { query } );
}

function onFiltersChanged() {
	page.value = 1;
	syncRoute();
	reload();
}

async function reload() {
	loading.value = true;
	error.value = null;

	const f = filters.value;
	const params = {
		q: search.value,
		assignee: f.assignee === 'me' ? session.user.id : ( f.assignee ?? null ),
		due: f.due ? 1 : null,
		sort: sort.value,
		page: page.value
	};

	if ( f.status === 'live' ) {
		params.live = 1;
	} else if ( f.status ) {
		params.status = f.status;
	}

	try {
		const response = await api.investigations( params );
		rows.value = response.data;
		meta.value = response.meta;
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
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

function onOpened( investigation ) {
	router.push( { name: 'investigation', params: { id: investigation.id } } );
}

onMounted( () => {
	reload();
	loadTeam();
} );
</script>
