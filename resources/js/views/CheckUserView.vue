<template>
	<main class="ts-page">
		<PageHeader
			title="Global CheckUser log"
		/>

		<div class="ts-toolbar">
			<cdx-field>
				<template #label>Wiki</template>
				<cdx-text-input v-model="filters.wiki" placeholder="Any" @change="go( 1 )" />
			</cdx-field>
			<cdx-field>
				<template #label>Checker</template>
				<cdx-text-input v-model="filters.checker" placeholder="Any" @change="go( 1 )" />
			</cdx-field>
			<cdx-field>
				<template #label>Target</template>
				<cdx-text-input
					v-model="filters.target"
					placeholder="Name or fingerprint"
					@change="go( 1 )"
				/>
			</cdx-field>
			<cdx-field>
				<template #label>Show</template>
				<cdx-select v-model:selected="filters.unexplained" :menu-items="scopes" @update:selected="go( 1 )" />
			</cdx-field>
		</div>

		<cdx-message v-if="meta.unexplained > 0 && !filters.unexplained" type="warning" :allow-user-dismiss="false">
			{{ meta.unexplained }} of these were run with no reason recorded.
			<a href="#" @click.prevent="onlyUnexplained">Show just those.</a>
		</cdx-message>

		<LoadError :error="error" :retry="reload" />
		<cdx-progress-bar v-if="loading" aria-label="Loading checks" />

		<div class="ts-panel ts-scroll">
			<cdx-table caption="Checks" :hide-caption="true" :columns="columns" :data="rows">
				<template #item-checked_at="{ item }">{{ dateTime( item ) }}</template>
				<template #item-checker="{ item }">
					<button type="button" class="ts-linkish" @click="filterBy( 'checker', item )">
						{{ item }}
					</button>
				</template>
				<template #item-wiki="{ item }">
					<button type="button" class="ts-linkish ts-mono" @click="filterBy( 'wiki', item )">
						{{ item }}
					</button>
				</template>
				<template #item-target="{ row }">
					<span :class="{ 'ts-mono': !row.target_name }">{{ row.target }}</span>
					<span class="ts-meta"> {{ targetLabel( row.target_kind ) }}</span>
				</template>
				<template #item-reason="{ row }">
					<span v-if="row.reason_given">{{ row.reason }}</span>
					<cdx-info-chip v-else status="warning">No reason recorded</cdx-info-chip>
				</template>
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
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
	CdxButton, CdxField, CdxInfoChip, CdxMessage, CdxProgressBar, CdxSelect, CdxTable, CdxTextInput
} from '@wikimedia/codex';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import { api } from '../lib/api.js';
import { dateTime, CHECK_TARGET_LABELS, CHECK_TYPE_LABELS } from '../lib/format.js';

const route = useRoute();
const router = useRouter();

const rows = ref( [] );
const meta = ref( { current_page: 1, last_page: 1, unexplained: 0 } );
const loading = ref( false );
const error = ref( null );
const page = ref( 1 );

const filters = reactive( {
	wiki: route.query.wiki ?? '',
	checker: route.query.checker ?? '',
	target: route.query.target ?? '',
	unexplained: route.query.unexplained ? '1' : ''
} );

const scopes = [
	{ value: '', label: 'Every check' },
	{ value: '1', label: 'Only those with no reason' }
];

const columns = [
	{ id: 'checked_at', label: 'When', width: '12rem' },
	{ id: 'checker', label: 'Who', width: '12rem' },
	{ id: 'wiki', label: 'Wiki', width: '10rem' },
	{ id: 'type_label', label: 'Kind', width: '12rem' },
	{ id: 'target', label: 'Target', width: '16rem' },
	{ id: 'reason', label: 'Reason given' }
];

function targetLabel( kind ) {
	return CHECK_TARGET_LABELS[ kind ] ?? kind;
}

function typeLabel( type ) {
	return CHECK_TYPE_LABELS[ type ] ?? type;
}

function filterBy( field, value ) {
	filters[ field ] = value;
	go( 1 );
}

function onlyUnexplained() {
	filters.unexplained = '1';
	go( 1 );
}

function go( to ) {
	page.value = to;
	reload();
}

async function reload() {
	loading.value = true;
	error.value = null;

	const params = {
		page: page.value,
		wiki: filters.wiki || undefined,
		checker: filters.checker || undefined,
		target: filters.target || undefined,
		unexplained: filters.unexplained || undefined
	};

	router.replace( { query: { ...params, page: undefined } } );

	try {
		const response = await api.checkUserChecks( params );
		rows.value = response.data.map( ( row ) => ( {
			...row,
			type_label: row.type_label ?? typeLabel( row.type )
		} ) );
		meta.value = response.meta;
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

onMounted( reload );
</script>
