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

		<p v-if="!rows.length && !loading" class="ts-empty-state">
			No reports yet. A report covers a period and freezes its figures on the day it is published,
			so the numbers cannot move afterwards.
		</p>

		<ul class="ts-acc">
			<li v-for="row in rows" :key="row.id" class="ts-acc__item">
				<router-link :to="{ name: 'transparency-report', params: { id: row.id } }" class="ts-acc__head ts-tr-row">
					<span class="ts-acc__title">
						<strong>{{ row.title }}</strong>
						<span class="ts-meta">{{ date( row.period_start ) }} – {{ date( row.period_end ) }}</span>
					</span>
					<span class="ts-acc__aside">
						<span v-if="row.published_at" class="ts-meta">
							Published {{ date( row.published_at ) }}<template v-if="row.published_by"> by {{ row.published_by }}</template>
						</span>
						<span v-if="row.threshold > 0" class="ts-meta">Counts of {{ row.threshold }} or fewer withheld</span>
						<cdx-info-chip :status="row.status === 'published' ? 'success' : 'notice'">
							{{ row.status === 'published' ? 'Published' : 'Draft' }}
						</cdx-info-chip>
					</span>
					<cdx-icon :icon="cdxIconNext" size="small" class="ts-tr-row__go" />
				</router-link>
			</li>
		</ul>

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

			<div v-if="quarters.length" class="ts-tr-quarters">
				<cdx-button v-for="quarter in quarters" :key="quarter.title" size="small" @click="fill( quarter )">
					{{ quarter.label }}
				</cdx-button>
			</div>

			<div class="ts-inline ts-tr-dates">
				<cdx-field>
					<template #label>From</template>
					<DatePicker v-model="form.period_start" placeholder="Start date" :max="form.period_end || undefined" :clearable="false" />
				</cdx-field>
				<cdx-field>
					<template #label>To</template>
					<DatePicker v-model="form.period_end" placeholder="End date" :min="form.period_start || undefined" :clearable="false" />
				</cdx-field>
			</div>
		</cdx-dialog>
	</main>
</template>

<script setup>
import { computed, inject, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
	CdxButton, CdxDialog, CdxField, CdxIcon, CdxInfoChip, CdxProgressBar, CdxTextInput
} from '@wikimedia/codex';
import { cdxIconAdd, cdxIconNext } from '@wikimedia/codex-icons';
import DatePicker from '../components/DatePicker.vue';
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
