<template>
	<main class="ts-page">
		<PageHeader
			title="Actions"
		/>

		<div class="ts-toolbar">
			<cdx-field>
				<template #label>Show</template>
				<cdx-select v-model:selected="scope" :menu-items="scopeOptions" @update:selected="reload" />
			</cdx-field>
		</div>

		<cdx-message v-if="notEnforced > 0" type="error" :allow-user-dismiss="false">
			{{ notEnforced }} of these were recorded on the wiki without the central lock being
			confirmed. Those accounts are listed as suspended and can still sign in through the API.
		</cdx-message>

		<cdx-message v-if="needsHand > 0" type="warning" :allow-user-dismiss="false">
			{{ needsHand }} of these still need someone to carry them out on the wiki by hand.
		</cdx-message>

		<LoadError :error="error" :retry="reload" />
		<cdx-progress-bar v-if="loading" aria-label="Loading actions" />

		<div class="ts-panel ts-scroll">
			<cdx-table caption="Actions taken" :hide-caption="true" :columns="columns" :data="rows">
				<template #item-reference="{ item }"><span class="ts-mono">{{ item }}</span></template>
				<template #item-subject="{ row }">
					<router-link
						v-if="row.subject_id"
						:to="{ name: 'subject', params: { id: row.subject_id } }"
					>
						{{ row.subject }}
					</router-link>
					<span v-else class="ts-mono">{{ row.where }}</span>
				</template>
				<template #item-active="{ item }">
					<cdx-info-chip :status="item ? 'error' : 'notice'">{{ item ? 'In force' : 'Not in force' }}</cdx-info-chip>
				</template>
				<template #item-push_state="{ row }">
					<div class="ts-stack">
						<StatusChip kind="push" :value="row.push_state" />
						<p v-if="row.push_error" class="ts-meta">{{ row.push_error }}</p>
						<cdx-button
							v-if="row.can_acknowledge"
							size="small"
							:disabled="acknowledging === row.id"
							@click="acknowledge( row )"
						>
							{{ acknowledging === row.id ? 'Marking…' : 'Mark as done by hand' }}
						</cdx-button>
					</div>
				</template>
				<template #item-issued="{ item }">{{ date( item ) }}</template>
				<template #item-expires="{ item }">{{ item ? date( item ) : 'no end date' }}</template>
				<template #empty-state>Nothing matches.</template>
			</cdx-table>
		</div>

		<div v-if="meta.last_page > 1" class="ts-inline" style="margin-top: 1rem;">
			<cdx-button :disabled="meta.current_page <= 1" @click="go( meta.current_page - 1 )">Previous</cdx-button>
			<span class="ts-meta">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
			<cdx-button :disabled="meta.current_page >= meta.last_page" @click="go( meta.current_page + 1 )">Next</cdx-button>
		</div>
	</main>
</template>

<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import {
	CdxButton, CdxField, CdxInfoChip, CdxMessage, CdxProgressBar, CdxSelect, CdxTable
} from '@wikimedia/codex';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import StatusChip from '../components/StatusChip.vue';
import { api } from '../lib/api.js';
import { date } from '../lib/format.js';

const route = useRoute();
const notify = inject( 'notify' );

const rows = ref( [] );
const meta = ref( { current_page: 1, last_page: 1 } );
const loading = ref( false );
const error = ref( null );
const acknowledging = ref( null );
const scope = ref( route.query.scope === 'broken' ? 'broken' : 'active' );
const page = ref( 1 );

const columns = [
	{ id: 'reference', label: 'Reference', width: '9rem' },
	{ id: 'subject', label: 'Account' },
	{ id: 'label', label: 'Action', width: '12rem' },
	{ id: 'active', label: 'Status', width: '9rem' },
	{ id: 'where', label: 'Where', width: '12rem' },
	{ id: 'push_state', label: 'On the wiki', width: '12rem' },
	{ id: 'issued', label: 'Given', width: '8rem' },
	{ id: 'expires', label: 'Ends', width: '8rem' }
];

const scopeOptions = [
	{ value: 'active', label: 'In force' },
	{ value: 'all', label: 'Everything' },
	{ value: 'broken', label: 'Not carried out' },
	{ value: 'partial', label: 'Recorded, not enforced' },
	{ value: 'manual', label: 'Needs doing by hand' },
	{ value: 'failed', label: 'Failed to send' },
	{ value: 'acknowledged', label: 'Done by hand' }
];

const needsHand = computed( () => rows.value.filter( ( r ) => r.push_state === 'manual' ).length );
const notEnforced = computed( () => rows.value.filter( ( r ) => r.push_state === 'partial' ).length );

async function reload() {
	loading.value = true;
	error.value = null;

	const params = { page: page.value };
	if ( scope.value === 'active' ) {
		params.active = 1;
	} else if ( scope.value === 'broken' ) {
		params.push_state = 'partial,failed,manual';
	} else if ( scope.value !== 'all' ) {
		params.push_state = scope.value;
	}

	try {
		const response = await api.sanctions( params );
		rows.value = response.data;
		meta.value = response.meta;
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

function go( to ) {
	page.value = to;
	reload();
}

async function acknowledge( row ) {
	acknowledging.value = row.id;
	try {
		await api.acknowledgeSanction( row.id );
		row.push_state = 'acknowledged';
		row.can_acknowledge = false;
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		acknowledging.value = null;
	}
}

onMounted( reload );
</script>
