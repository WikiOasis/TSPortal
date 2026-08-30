<template>
	<main class="ts-page">
		<PageHeader
			title="Investigations"
		>
			<template #actions>
				<cdx-button action="progressive" weight="primary" @click="showOpen = true">
					<cdx-icon :icon="cdxIconAdd" size="small" />
					Open a file
				</cdx-button>
			</template>
		</PageHeader>

		<cdx-message
			v-if="dueCount > 0 && filters.sort !== 'review'"
			type="warning"
			:allow-user-dismiss="false"
		>
			{{ dueCount }} investigation{{ dueCount === 1 ? '' : 's' }} passed the date somebody meant to look
			again.
			<a href="#" @click.prevent="showDue">Show those first</a>.
		</cdx-message>

		<div class="ts-toolbar">
			<cdx-field>
				<template #label>Search</template>
				<cdx-search-input
					v-model="filters.q"
					placeholder="Reference, title or premise"
					@update:model-value="debouncedReload"
				/>
			</cdx-field>

			<cdx-field>
				<template #label>Show</template>
				<cdx-select v-model:selected="filters.status" :menu-items="statusOptions" @update:selected="reload" />
			</cdx-field>

			<cdx-field>
				<template #label>With</template>
				<cdx-select v-model:selected="filters.assignee" :menu-items="assigneeOptions" @update:selected="reload" />
			</cdx-field>

			<cdx-field>
				<template #label>Order</template>
				<cdx-select v-model:selected="filters.sort" :menu-items="sortOptions" @update:selected="reload" />
			</cdx-field>
		</div>

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
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
	CdxButton, CdxField, CdxIcon, CdxMessage, CdxProgressBar,
	CdxSearchInput, CdxSelect, CdxTable
} from '@wikimedia/codex';
import { cdxIconAdd } from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import StatusChip from '../components/StatusChip.vue';
import OpenInvestigationDialog from '../components/OpenInvestigationDialog.vue';
import { api } from '../lib/api.js';
import { ago, dateTime } from '../lib/format.js';

const router = useRouter();

const rows = ref( [] );
const meta = ref( { current_page: 1, last_page: 1, total: 0 } );
const loading = ref( false );
const error = ref( null );
const showOpen = ref( false );

const filters = reactive( {
	q: '',
	status: 'live',
	assignee: null,
	sort: 'updated',
	page: 1
} );

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
	{ value: '', label: 'Everything' },
	{ value: 'open', label: 'Open' },
	{ value: 'monitoring', label: 'Watching' },
	{ value: 'concluded', label: 'Concluded' },
	{ value: 'closed', label: 'Closed' }
];

const assigneeOptions = [
	{ value: null, label: 'Anyone' },
	{ value: 'none', label: 'Nobody yet' }
];

const sortOptions = [
	{ value: 'updated', label: 'Last change' },
	{ value: 'review', label: 'Review date' },
	{ value: 'priority', label: 'Priority' },
	{ value: 'oldest', label: 'Oldest first' },
	{ value: 'newest', label: 'Newest first' }
];

const dueCount = ref( 0 );

async function reload() {
	loading.value = true;
	error.value = null;

	const params = {
		q: filters.q,
		assignee: filters.assignee,
		sort: filters.sort,
		page: filters.page
	};

	if ( filters.status === 'live' ) {
		params.live = 1;
	} else if ( filters.status ) {
		params.status = filters.status;
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

async function loadDue() {
	try {
		const response = await api.investigations( { due: 1, per_page: 5 } );
		dueCount.value = response.meta.total;
	} catch ( e ) {
		dueCount.value = 0;
	}
}

function showDue() {
	filters.status = 'live';
	filters.sort = 'review';
	filters.page = 1;
	reload();
}

let timer = null;
function debouncedReload() {
	clearTimeout( timer );
	timer = setTimeout( () => {
		filters.page = 1;
		reload();
	}, 300 );
}

function go( page ) {
	filters.page = page;
	reload();
}

function onOpened( investigation ) {
	router.push( { name: 'investigation', params: { id: investigation.id } } );
}

onMounted( () => {
	reload();
	loadDue();
} );
</script>
