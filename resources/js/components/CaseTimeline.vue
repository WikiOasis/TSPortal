<template>
	<div class="ts-panel">
		<div class="ts-timeline__toolbar">
			<div class="ts-inline">
				<h2 class="ts-section__title ts-timeline__heading">{{ heading }}</h2>
				<span v-if="entries.length" class="ts-meta">{{ entries.length }} entries</span>
			</div>

			<cdx-toggle-button
				v-if="showReporterView"
				v-model="publicOnly"
				class="ts-timeline__filter"
			>
				<cdx-icon :icon="cdxIconEye" size="small" />
				What the reporter has seen
			</cdx-toggle-button>
		</div>

		<cdx-progress-bar v-if="loading && !entries.length" aria-label="Loading the timeline" />

		<p v-else-if="!visible.length" class="ts-meta ts-timeline__empty">
			{{ publicOnly && showReporterView
				? 'The reporter can\'t see anything here yet.'
				: 'Nothing has been recorded yet.' }}
		</p>

		<ol v-else class="ts-timeline">
			<li
				v-for="( entry, i ) in visible"
				:key="`${ entry.kind }-${ entry.at }-${ i }`"
				class="ts-timeline__item"
				:class="`ts-timeline__item--${ tone( entry ) }`"
			>
				<span class="ts-timeline__marker" aria-hidden="true">
					<cdx-icon :icon="icon( entry )" size="x-small" />
				</span>

				<div class="ts-timeline__card">
					<div class="ts-timeline__head">
						<span class="ts-timeline__title">{{ entry.title }}</span>

						<cdx-info-chip
							v-if="entry.visibility === 'internal'"
							status="info"
							class="ts-timeline__chip"
						>
							Internal
						</cdx-info-chip>

						<router-link
							v-if="link( entry )"
							:to="link( entry )"
							class="ts-mono ts-timeline__link"
						>
							{{ entry.meta.reference || 'open' }}
						</router-link>
					</div>

					<p v-if="entry.body" class="ts-timeline__body">{{ entry.body }}</p>

					<dl v-if="entry.kind === 'action'" class="ts-dl ts-timeline__facts">
						<dt v-if="entry.meta.account">Against</dt>
						<dd v-if="entry.meta.account">{{ entry.meta.account }}</dd>
						<dt v-if="entry.meta.scope">Where</dt>
						<dd v-if="entry.meta.scope">{{ entry.meta.scope }}</dd>
						<dt>Ends</dt>
						<dd>{{ entry.meta.expires ? dateTime( entry.meta.expires ) : 'no end date' }}</dd>
						<dt>On the wiki</dt>
						<dd><StatusChip kind="push" :value="entry.meta.push_state" /></dd>
						<template v-if="entry.meta.internal_reason">
							<dt>Internal</dt>
							<dd>{{ entry.meta.internal_reason }}</dd>
						</template>
					</dl>

					<dl v-else-if="entry.kind === 'data-removal'" class="ts-dl ts-timeline__facts">
						<dt>Account</dt>
						<dd>{{ entry.meta.account }}</dd>
						<dt>Becomes</dt>
						<dd class="ts-mono">{{ entry.meta.became }}</dd>
						<dt>State</dt>
						<dd><StatusChip kind="removal" :value="entry.meta.state" /></dd>
					</dl>

					<div class="ts-timeline__foot">
						<span v-if="entry.actor">{{ entry.actor }}</span>
						<span v-if="entry.actor" aria-hidden="true">·</span>
						<time :datetime="entry.at" :title="dateTime( entry.at )">{{ ago( entry.at ) }}</time>
					</div>
				</div>
			</li>
		</ol>
	</div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { CdxIcon, CdxInfoChip, CdxProgressBar, CdxToggleButton } from '@wikimedia/codex';
import {
	cdxIconAlert, cdxIconArticle, cdxIconBlock, cdxIconCheck, cdxIconClock,
	cdxIconEye, cdxIconFlag, cdxIconHistory, cdxIconSpeechBubble, cdxIconTrash,
	cdxIconUnBlock, cdxIconUserGroup
} from '@wikimedia/codex-icons';
import StatusChip from './StatusChip.vue';
import { ago, dateTime, TIMELINE_KINDS } from '../lib/format.js';

const props = defineProps( {
	entries: { type: Array, default: () => [] },
	loading: { type: Boolean, default: false },
	heading: { type: String, default: 'Timeline' },
	showReporterView: { type: Boolean, default: true }
} );

const publicOnly = ref( false );

const visible = computed( () => (
	publicOnly.value && props.showReporterView
		? props.entries.filter( ( e ) => e.visibility === 'public' )
		: props.entries
) );

const ICONS = {
	filed: cdxIconFlag,
	comment: cdxIconSpeechBubble,
	system: cdxIconHistory,
	audit: cdxIconHistory,
	action: cdxIconBlock,
	'action-lifted': cdxIconUnBlock,
	'action-expired': cdxIconClock,
	investigation: cdxIconUserGroup,
	opened: cdxIconUserGroup,
	note: cdxIconArticle,
	case: cdxIconFlag,
	'data-removal': cdxIconTrash,
	concluded: cdxIconCheck
};

function icon( entry ) {
	return ICONS[ entry.kind ] ?? cdxIconAlert;
}

function tone( entry ) {
	if ( entry.visibility === 'internal' ) {
		return 'internal';
	}
	return TIMELINE_KINDS[ entry.kind ]?.tone ?? 'neutral';
}

function link( entry ) {
	if ( !entry.link?.route || !entry.link?.id ) {
		return null;
	}
	return { name: entry.link.route, params: { id: entry.link.id } };
}
</script>
