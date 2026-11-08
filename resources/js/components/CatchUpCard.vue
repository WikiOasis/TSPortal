<template>
	<article class="ts-cu-card" :class="{ 'ts-cu-card--peek': peek }">
		<header class="ts-cu-card__head ts-deck__grab">
			<span class="ts-deck__grip" aria-hidden="true" />
			<div class="ts-cu-card__line">
				<router-link :to="{ name: 'case', params: { id: row.id } }" class="ts-mono">{{ row.reference }}</router-link>
				<cdx-info-chip v-if="row.threat_to_life" status="error">Threat to life</cdx-info-chip>
				<cdx-info-chip v-else>{{ TYPE_LABELS[ row.type ] ?? row.type }}</cdx-info-chip>
				<StatusChip v-if="shown" kind="case" :value="shown.status" />
				<StatusChip v-if="shown" kind="priority" :value="shown.priority" />
				<span class="ts-meta ts-cu-card__when" :title="dateTime( row.updated )">{{ ago( row.updated ) }}</span>
			</div>
			<h2 class="ts-cu-card__subject">{{ row.subject }}</h2>
			<p class="ts-meta">
				{{ shown?.assignee ? `With ${ shown.assignee.username }` : ( row.assignee ? `With ${ row.assignee }` : 'Unassigned' ) }}
			</p>
		</header>

		<div v-if="peek" class="ts-cu-card__body ts-cu-card__body--peek">
			<p v-if="row.summary" class="ts-summary">{{ row.summary }}</p>
		</div>

		<div v-else class="ts-cu-card__body" tabindex="-1">
			<LoadError :error="error" :retry="load" />
			<cdx-progress-bar v-if="loading && !item" aria-label="Loading this item" />

			<template v-if="item">
				<section class="ts-cu-card__section">
					<h3 class="ts-section__title">{{ item.appeal ? 'What they said' : 'What was said' }}</h3>
					<p v-if="item.summary" class="ts-summary">{{ item.summary }}</p>
					<p v-else class="ts-meta">No summary was given.</p>
					<div v-if="item.about?.length" class="ts-inline">
						<span class="ts-meta">About</span>
						<cdx-info-chip v-for="name in item.about" :key="name">{{ name }}</cdx-info-chip>
					</div>
				</section>

				<ActivityFeed
					kind="case"
					:entries="timeline"
					:loading="timelineLoading"
					:replyable="!item.duplicate_of"
					:item="item"
					@changed="onChanged"
				/>

				<ActivityComposer
					v-if="!item.duplicate_of"
					:key="item.id"
					kind="case"
					:item="item"
					:team="team"
					@done="onChanged"
				/>
			</template>
		</div>
	</article>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { CdxInfoChip, CdxProgressBar } from '@wikimedia/codex';
import ActivityComposer from './ActivityComposer.vue';
import ActivityFeed from './ActivityFeed.vue';
import LoadError from './LoadError.vue';
import StatusChip from './StatusChip.vue';
import { api } from '../lib/api.js';
import { ago, dateTime, TYPE_LABELS } from '../lib/format.js';

const props = defineProps( {
	row: { type: Object, required: true },
	peek: { type: Boolean, default: false },
	team: { type: Array, default: () => [] }
} );
const emit = defineEmits( [ 'engaged' ] );

const item = ref( null );
const timeline = ref( [] );
const loading = ref( false );
const timelineLoading = ref( false );
const error = ref( null );
const shown = ref( null );

async function loadTimeline() {
	timelineLoading.value = true;

	try {
		timeline.value = ( await api.caseTimeline( props.row.id ) ).data;
	} catch ( e ) {
		timeline.value = [];
	} finally {
		timelineLoading.value = false;
	}
}

async function load() {
	if ( item.value || loading.value ) {
		return;
	}

	loading.value = true;
	error.value = null;

	try {
		item.value = shown.value = ( await api.case( props.row.id ) ).data;
		loadTimeline();
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

function onChanged( data ) {
	if ( data ) {
		item.value = shown.value = data;
	}
	loadTimeline();
	emit( 'engaged' );
}

watch( () => props.peek, ( peek ) => {
	if ( !peek ) {
		load();
	}
} );

onMounted( () => {
	if ( !props.peek ) {
		load();
	}
} );
</script>
