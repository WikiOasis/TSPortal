<template>
	<article class="ts-ar-detail">
		<header v-if="showHeader" class="ts-ar-detail__head">
			<div class="ts-ar-detail__heading">
				<h2 class="ts-ar-detail__title">{{ headline || pageTitle }}</h2>
				<slot name="nav" />
			</div>
			<div class="ts-meta">
				<template v-if="headline">
					<a v-if="review.links.page" :href="review.links.page" target="_blank" rel="noopener noreferrer">{{ pageTitle }}</a>
					<strong v-else>{{ pageTitle }}</strong>
					on
				</template>
				<span class="ts-mono">{{ review.wiki ?? row.wiki }}</span>
				·
				<router-link :to="{ name: 'case', params: { id: row.id } }" class="ts-mono">{{ row.reference }}</router-link>
				· flagged {{ ago( row.filed ) }}
				<template v-if="review.author">
					· by
					<a v-if="review.links.contributions" :href="review.links.contributions" target="_blank" rel="noopener noreferrer">{{ review.author }}</a>
					<template v-else>{{ review.author }}</template>
					<template v-if="author && author.ip"> (not signed in)</template>
					<template v-else-if="author"> ({{ author.edits ?? '?' }} edits<template v-if="author.registered">, joined {{ date( author.registered ) }}</template><template v-if="author.groups && author.groups.length">, {{ author.groups.join( ', ' ) }}</template>)</template>
				</template>
				<template v-if="review.scan_mode === 'page'"> · whole page</template>
				<template v-if="row.assignee"> · with <strong>{{ row.assignee.username }}</strong></template>
			</div>
			<div v-if="hasFacts" class="ts-inline ts-ar-detail__facts">
				<cdx-info-chip v-if="review.facts.reverted" status="success">Already reverted</cdx-info-chip>
				<cdx-info-chip v-if="review.facts.deleted" status="success">Page or revision deleted</cdx-info-chip>
				<cdx-info-chip v-if="review.facts.hidden" status="success">Text hidden</cdx-info-chip>
				<cdx-info-chip v-if="review.facts.current === true" status="warning">Still live</cdx-info-chip>
				<cdx-info-chip v-else-if="review.facts.current === false && !review.facts.reverted">Edited since</cdx-info-chip>
				<cdx-info-chip v-if="review.facts.created_page">Created the page</cdx-info-chip>
				<cdx-info-chip v-if="author && author.blocked" status="warning">Editor is blocked</cdx-info-chip>
			</div>
		</header>

		<p v-if="!showHeader && headline" class="ts-ar-detail__headline">{{ headline }}</p>

		<details class="ts-reasoning" :open="open" @toggle="onToggle">
			<summary class="ts-reasoning__summary">
				<cdx-icon :icon="cdxIconRobot" size="small" />
				<span class="ts-reasoning__title">AI reasoning</span>
				<span class="ts-meta">
					{{ current ? current.label : ( review.state === 'failed' ? 'could not sort this one' : 'not sorted yet' ) }}<template v-if="current && review.confidence !== null && !review.staff_bucket"> · {{ percent( review.confidence ) }} sure</template>
				</span>
				<cdx-icon class="ts-reasoning__chevron" :icon="cdxIconExpand" size="small" />
			</summary>

			<div class="ts-reasoning__body">
				<p v-if="review.state === 'failed' && review.error" class="ts-meta">{{ review.error }}</p>
				<p v-else-if="!current" class="ts-meta">Everything Jev sent is below.</p>
				<p v-if="review.reason">{{ review.reason }}</p>

				<dl v-if="review.page_summary || review.change_summary || review.suggested_action" class="ts-dl">
					<template v-if="review.page_summary">
						<dt>The page</dt>
						<dd>{{ review.page_summary }}</dd>
					</template>
					<template v-if="review.change_summary">
						<dt>What changed</dt>
						<dd>{{ review.change_summary }}</dd>
					</template>
					<template v-if="review.suggested_action">
						<dt>Suggests</dt>
						<dd>{{ ACTION_LABELS[ review.suggested_action ] ?? review.suggested_action }}</dd>
					</template>
				</dl>

				<div v-if="review.signals.length" class="ts-inline ts-reasoning__signals">
					<cdx-info-chip v-for="signal in review.signals" :key="signal">{{ signal }}</cdx-info-chip>
				</div>

				<p v-if="review.staff_bucket" class="ts-meta">
					The AI said {{ modelLabel }}; {{ review.overridden_by ?? 'a person' }} moved it {{ ago( review.overridden_at ) }}.
				</p>
				<p v-else-if="review.confirmed_at" class="ts-meta">
					{{ review.confirmed_by ?? 'A person' }} agreed and took it {{ ago( review.confirmed_at ) }}.
				</p>
			</div>
		</details>

		<div v-if="!showHeader && hasFacts" class="ts-inline">
			<span class="ts-meta">On the wiki now:</span>
			<cdx-info-chip v-if="review.facts.reverted" status="success">Already reverted</cdx-info-chip>
			<cdx-info-chip v-if="review.facts.deleted" status="success">Page or revision deleted</cdx-info-chip>
			<cdx-info-chip v-if="review.facts.hidden" status="success">Text hidden</cdx-info-chip>
			<cdx-info-chip v-if="review.facts.current === true" status="warning">Still live</cdx-info-chip>
			<cdx-info-chip v-else-if="review.facts.current === false && !review.facts.reverted">Edited since</cdx-info-chip>
			<cdx-info-chip v-if="review.facts.created_page">Created the page</cdx-info-chip>
			<cdx-info-chip v-if="author && author.blocked" status="warning">Editor is blocked</cdx-info-chip>
		</div>

		<section class="ts-ar-detail__evidence">
			<div class="ts-ar-detail__evidence-head">
				<h3 class="ts-chart__title">
					{{ review.source === 'page' ? 'The page as flagged' : review.source === 'diff' ? 'The edit' : 'What was flagged' }}
				</h3>
				<a v-if="review.links.revision" :href="review.links.revision" target="_blank" rel="noopener noreferrer" class="ts-meta">
					Open on the wiki ↗
				</a>
			</div>

			<cdx-progress-bar v-if="loading" :inline="true" aria-label="Loading what was flagged" />

			<template v-else-if="evidence">
				<pre v-if="evidence.diff" class="ts-diff" tabindex="0" aria-label="The flagged edit"><div
					v-for="( line, i ) in lines"
					:key="i"
					class="ts-diff__line"
					:class="`ts-diff__line--${ line.kind }`"
				><span
					v-for="( part, j ) in line.segments"
					:key="j"
					:class="part.kind === 'plain' ? null : `ts-diff__mark--${ part.kind }`"
				>{{ part.text }}</span></div></pre>

				<details v-if="evidence.content" class="ts-details" :open="!evidence.diff">
					<summary>{{ evidence.diff ? 'The page after the edit' : 'Text' }}</summary>
					<pre class="ts-diff ts-diff--text" tabindex="0">{{ evidence.content }}</pre>
				</details>

				<p v-if="evidence.truncated" class="ts-meta">Cut short — open it on the wiki to see the rest.</p>
				<p v-if="!evidence.diff && !evidence.content" class="ts-meta">
					{{ review.facts.problem ?? 'Nothing could be fetched from the wiki.' }}
				</p>
			</template>

			<p v-else-if="review.facts.problem" class="ts-meta">{{ review.facts.problem }}</p>
			<p v-else-if="row.summary" class="ts-meta">{{ row.summary }}</p>
		</section>

		<dl class="ts-dl ts-ar-detail__about">
			<template v-if="!showHeader">
				<dt>Editor</dt>
				<dd>
					<template v-if="review.author">
						<a v-if="review.links.contributions" :href="review.links.contributions" target="_blank" rel="noopener noreferrer">
							{{ review.author }}
						</a>
						<span v-else>{{ review.author }}</span>
						<span v-if="author && author.ip" class="ts-meta"> · not signed in</span>
						<span v-else-if="author" class="ts-meta">
							· {{ author.edits ?? '?' }} edits
							<template v-if="author.registered"> · joined {{ date( author.registered ) }}</template>
							<template v-if="author.groups && author.groups.length"> · {{ author.groups.join( ', ' ) }}</template>
						</span>
					</template>
					<span v-else class="ts-meta">Unknown</span>
				</dd>
			</template>
			<template v-if="row.edit_summary">
				<dt>Edit summary</dt>
				<dd>{{ row.edit_summary }}</dd>
			</template>
			<dt>Jev</dt>
			<dd>
				{{ row.category ?? 'No category' }}
				<span class="ts-meta">· harm {{ percent( row.jev.harm ) }} · vandalism {{ percent( row.jev.vandalism ) }}</span>
			</dd>
		</dl>
	</article>
</template>

<script setup>
import { computed } from 'vue';
import { CdxIcon, CdxInfoChip, CdxProgressBar } from '@wikimedia/codex';
import { cdxIconExpand, cdxIconRobot } from '@wikimedia/codex-icons';
import { ACTION_LABELS, bucket, diffLines, percent, reasoningOpen } from '../lib/autoreview.js';
import { ago, date } from '../lib/format.js';

const props = defineProps( {
	row: { type: Object, required: true },
	loading: { type: Boolean, default: false },
	showHeader: { type: Boolean, default: true }
} );

const review = computed( () => props.row.review ?? {
	state: 'pending',
	bucket: null,
	signals: [],
	facts: {},
	links: {},
	confidence: null
} );

const current = computed( () => bucket( review.value.bucket ) );
const modelLabel = computed( () => ( bucket( review.value.model_bucket )?.label ?? 'nothing' ).toLowerCase() );
const author = computed( () => review.value.facts?.author ?? null );
const evidence = computed( () => review.value.evidence ?? null );
const lines = computed( () => diffLines( evidence.value?.diff ) );
const hasFacts = computed( () => {
	const f = review.value.facts ?? {};
	return f.reverted || f.deleted || f.hidden || f.current !== null && f.current !== undefined || f.created_page || author.value?.blocked;
} );
const headline = computed( () => review.value.headline || review.value.reason || '' );
const pageTitle = computed( () => review.value.page_title ?? props.row.subject );
const open = computed( () => !props.showHeader || reasoningOpen.value );

function onToggle( event ) {
	if ( props.showHeader ) {
		reasoningOpen.value = event.target.open;
	}
}
</script>
