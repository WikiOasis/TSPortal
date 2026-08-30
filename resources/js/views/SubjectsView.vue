<template>
	<main class="ts-page">
		<PageHeader
			title="Accounts"
			subtitle="Everyone Trust &amp; Safety holds something about, including names that resolve to no account."
		/>

		<div class="ts-toolbar">
			<cdx-field>
				<template #label>Search</template>
				<cdx-search-input
					v-model="q"
					placeholder="Username"
					@update:model-value="debouncedReload"
				/>
			</cdx-field>
			<cdx-field>
				<template #label>Standing</template>
				<cdx-select v-model:selected="standing" :menu-items="standingOptions" @update:selected="reload" />
			</cdx-field>
		</div>

		<LoadError :error="error" :retry="reload" />
		<cdx-progress-bar v-if="loading" aria-label="Loading accounts" />

		<div class="ts-panel ts-scroll">
			<cdx-table caption="Accounts on file" :hide-caption="true" :columns="columns" :data="rows">
				<template #item-username="{ row }">
					<router-link :to="{ name: 'subject', params: { id: row.id } }">{{ row.username }}</router-link>
					<span v-if="row.unresolved" class="ts-meta"> · no matching account</span>
				</template>
				<template #item-standing="{ item }"><StatusChip kind="standing" :value="item" /></template>
				<template #item-active_sanctions="{ item }">{{ item }}</template>
				<template #empty-state>
					<template v-if="q.trim()">
						No account on file matches “{{ q.trim() }}”.
						<cdx-button :disabled="resolving" @click="lookUpOnWiki">
							{{ resolving ? 'Checking the wiki…' : 'Look it up on the wiki' }}
						</cdx-button>
					</template>
					<template v-else>No accounts match.</template>
				</template>
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
import { inject, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { CdxButton, CdxField, CdxProgressBar, CdxSearchInput, CdxSelect, CdxTable } from '@wikimedia/codex';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import StatusChip from '../components/StatusChip.vue';
import { api } from '../lib/api.js';

const router = useRouter();
const notify = inject( 'notify' );

const rows = ref( [] );
const meta = ref( { current_page: 1, last_page: 1 } );
const loading = ref( false );
const error = ref( null );
const q = ref( '' );
const standing = ref( null );
const page = ref( 1 );
const resolving = ref( false );

const columns = [
	{ id: 'username', label: 'Account' },
	{ id: 'standing', label: 'Standing', width: '12rem' },
	{ id: 'active_sanctions', label: 'Actions in force', width: '10rem', textAlign: 'number' }
];

const standingOptions = [
	{ value: null, label: 'Any' },
	{ value: 'good', label: 'In good standing' },
	{ value: 'restricted', label: 'Restricted' },
	{ value: 'suspended', label: 'Suspended' }
];

async function reload() {
	loading.value = true;
	error.value = null;
	try {
		const response = await api.subjects( { q: q.value, standing: standing.value, page: page.value } );
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
	timer = setTimeout( () => {
		page.value = 1;
		reload();
	}, 300 );
}

function go( to ) {
	page.value = to;
	reload();
}

async function lookUpOnWiki() {
	resolving.value = true;
	try {
		const subject = await api.resolveSubject( q.value.trim() );
		router.push( { name: 'subject', params: { id: subject.id } } );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		resolving.value = false;
	}
}

onMounted( reload );
</script>
