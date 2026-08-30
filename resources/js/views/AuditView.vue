<template>
	<main class="ts-page">
		<PageHeader
			title="Audit log"
		/>

		<div class="ts-toolbar">
			<cdx-field>
				<template #label>Action</template>
				<cdx-text-input v-model="action" placeholder="case.created" @keydown.enter="reload" />
			</cdx-field>
			<cdx-button @click="reload">Filter</cdx-button>
		</div>

		<LoadError :error="error" :retry="reload" />
		<cdx-progress-bar v-if="loading" aria-label="Loading the audit log" />

		<div class="ts-panel ts-scroll">
			<cdx-table caption="Audit log" :hide-caption="true" :columns="columns" :data="rows">
				<template #item-at="{ item }">{{ dateTime( item ) }}</template>
				<template #item-action="{ row }">{{ row.action_label }}</template>
				<template #item-target="{ row }">
					<router-link
						v-if="row.target && row.target.route"
						:to="{ name: row.target.route, params: { id: row.target.id } }"
						class="ts-mono"
					>
						{{ row.target.reference }}
					</router-link>
					<span v-else-if="row.target" class="ts-mono">{{ row.target.reference }}</span>
					<span v-else-if="row.target_type" class="ts-meta">
						{{ row.target_type }} #{{ row.target_id }}
					</span>
					<span v-else>—</span>

					<div v-if="row.target" class="ts-meta">{{ row.target.type_label }}</div>
				</template>
				<template #item-meta="{ item }">
					<span class="ts-mono">{{ item ? JSON.stringify( item ) : '' }}</span>
				</template>
				<template #empty-state>Nothing recorded yet.</template>
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
import { onMounted, ref } from 'vue';
import { CdxButton, CdxField, CdxProgressBar, CdxTable, CdxTextInput } from '@wikimedia/codex';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import { api } from '../lib/api.js';
import { dateTime } from '../lib/format.js';

const rows = ref( [] );
const meta = ref( { current_page: 1, last_page: 1 } );
const loading = ref( false );
const error = ref( null );
const action = ref( '' );
const page = ref( 1 );

const columns = [
	{ id: 'at', label: 'When', width: '12rem' },
	{ id: 'actor', label: 'Who', width: '12rem' },
	{ id: 'action', label: 'What', width: '12rem' },
	{ id: 'target', label: 'To', width: '12rem' },
	{ id: 'meta', label: 'Detail' }
];

async function reload() {
	loading.value = true;
	error.value = null;
	try {
		const response = await api.audit( { action: action.value, page: page.value } );
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

onMounted( reload );
</script>
