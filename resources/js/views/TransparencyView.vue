<template>
	<main class="ts-page">
		<PageHeader
			title="Transparency reports"
		>
			<template #actions>
				<cdx-button action="progressive" weight="primary" @click="opening = true">
					<cdx-icon :icon="cdxIconAdd" />
					New report
				</cdx-button>
			</template>
		</PageHeader>

		<LoadError :error="error" :retry="reload" />
		<cdx-progress-bar v-if="loading" aria-label="Loading reports" />

		<div class="ts-panel ts-scroll">
			<cdx-table
				caption="Transparency reports"
				:hide-caption="true"
				:columns="columns"
				:data="rows"
			>
				<template #item-reference="{ item, row }">
					<router-link :to="{ name: 'transparency-report', params: { id: row.id } }" class="ts-mono">
						{{ item }}
					</router-link>
				</template>
				<template #item-period="{ row }">
					{{ date( row.period_start ) }} – {{ date( row.period_end ) }}
				</template>
				<template #item-status="{ item }">
					<cdx-info-chip :status="item === 'published' ? 'success' : 'notice'">
						{{ item === 'published' ? 'Published' : 'Draft' }}
					</cdx-info-chip>
				</template>
				<template #item-threshold="{ item }">
					{{ item > 0 ? `Counts of ${ item } or fewer withheld` : 'Exact counts' }}
				</template>
				<template #item-published_at="{ item, row }">
					{{ item ? `${ date( item ) } by ${ row.published_by }` : '—' }}
				</template>
				<template #empty-state>
					No reports yet. A report covers a period and freezes its figures on the day it
					is published, so the numbers cannot move afterwards.
				</template>
			</cdx-table>
		</div>

		<cdx-dialog
			v-model:open="opening"
			title="New transparency report"
			:use-close-button="true"
			:primary-action="{ label: creating ? 'Building…' : 'Build it', actionType: 'progressive', disabled: !valid || creating }"
			:default-action="{ label: 'Cancel' }"
			@primary="create"
			@default="opening = false"
		>
			<p class="ts-meta">
				The figures are computed once and stored. A draft can be rebuilt as often as you
				like; once it is published its numbers never change again.
			</p>

			<cdx-field :status="fieldError ? 'error' : 'default'" :messages="{ error: fieldError }">
				<template #label>Title</template>
				<cdx-text-input v-model="form.title" placeholder="Trust &amp; Safety, April to June 2026" />
			</cdx-field>

			<div class="ts-inline">
				<cdx-field>
					<template #label>From</template>
					<cdx-text-input v-model="form.period_start" input-type="date" />
				</cdx-field>
				<cdx-field>
					<template #label>To</template>
					<cdx-text-input v-model="form.period_end" input-type="date" />
				</cdx-field>
			</div>

			<cdx-message v-if="quarters.length" type="notice" :inline="true">
				<span>Or take a recent quarter:</span>
				<cdx-button
					v-for="quarter in quarters"
					:key="quarter.title"
					weight="quiet"
					size="small"
					@click="fill( quarter )"
				>
					{{ quarter.label }}
				</cdx-button>
			</cdx-message>
		</cdx-dialog>
	</main>
</template>

<script setup>
import { computed, inject, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
	CdxButton, CdxDialog, CdxField, CdxIcon, CdxInfoChip, CdxMessage,
	CdxProgressBar, CdxTable, CdxTextInput
} from '@wikimedia/codex';
import { cdxIconAdd } from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import { api } from '../lib/api.js';
import { date } from '../lib/format.js';

const router = useRouter();
const notify = inject( 'notify' );

const rows = ref( [] );
const loading = ref( false );
const error = ref( null );
const opening = ref( false );
const creating = ref( false );
const fieldError = ref( '' );

const form = reactive( { title: '', period_start: '', period_end: '' } );

const columns = [
	{ id: 'reference', label: 'Reference', width: '9rem' },
	{ id: 'title', label: 'Title' },
	{ id: 'period', label: 'Period', width: '14rem' },
	{ id: 'status', label: 'Status', width: '8rem' },
	{ id: 'threshold', label: 'Small counts', width: '14rem' },
	{ id: 'published_at', label: 'Published', width: '12rem' }
];

const valid = computed( () =>
	form.title.trim() !== '' && form.period_start !== '' && form.period_end !== '' );

const quarters = computed( () => {
	const now = new Date();
	const out = [];

	for ( let back = 1; back <= 4; back++ ) {
		const end = new Date( Date.UTC( now.getUTCFullYear(), Math.floor( now.getUTCMonth() / 3 ) * 3 - ( back - 1 ) * 3, 0 ) );
		const start = new Date( Date.UTC( end.getUTCFullYear(), end.getUTCMonth() - 2, 1 ) );
		const quarter = Math.floor( start.getUTCMonth() / 3 ) + 1;

		out.push( {
			label: `Q${ quarter } ${ start.getUTCFullYear() }`,
			title: `Trust & Safety, Q${ quarter } ${ start.getUTCFullYear() }`,
			period_start: start.toISOString().slice( 0, 10 ),
			period_end: end.toISOString().slice( 0, 10 )
		} );
	}

	return out;
} );

function fill( quarter ) {
	form.title = quarter.title;
	form.period_start = quarter.period_start;
	form.period_end = quarter.period_end;
}

async function reload() {
	loading.value = true;
	error.value = null;
	try {
		rows.value = ( await api.transparencyReports() ).data;
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

async function create() {
	if ( !valid.value ) {
		return;
	}
	creating.value = true;
	fieldError.value = '';

	try {
		const report = await api.openTransparencyReport( { ...form } );
		opening.value = false;
		router.push( { name: 'transparency-report', params: { id: report.id } } );
	} catch ( e ) {
		fieldError.value = e.errors?.period_end?.[ 0 ] ?? e.message;
		notify( e.message, 'error' );
	} finally {
		creating.value = false;
	}
}

onMounted( reload );
</script>
