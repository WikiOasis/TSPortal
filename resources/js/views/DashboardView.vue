<template>
	<main class="ts-page">
		<PageHeader
			title="Dashboard"
			:subtitle="`Welcome back, ${ session.user.username }. `"
		>
			<template #actions>
				<cdx-button @click="load">
					<cdx-icon :icon="cdxIconReload" size="small" />
					Refresh
				</cdx-button>
			</template>
		</PageHeader>

		<LoadError :error="error" :retry="load" />

		<cdx-progress-bar v-if="loading && !data" aria-label="Loading" />

		<template v-if="data">
			<router-link
				v-if="data.threat_to_life && data.threat_to_life.open > 0"
				:to="{ name: 'queue', query: { threat: '1' } }"
				class="ts-panel ts-stat ts-stat--link ts-stat--alert ts-threat"
			>
				<div class="ts-stat__value">{{ data.threat_to_life.open }}</div>
				<div class="ts-stat__label">
					Open threat-to-life report{{ data.threat_to_life.open === 1 ? '' : 's' }}
				</div>
				<div class="ts-stat__detail">
					<template v-if="data.threat_to_life.unassigned">
						{{ data.threat_to_life.unassigned }} have nobody assigned to {{ data.threat_to_life.unassigned === 1 ? 'it' : 'them' }}
					</template>
				</div>
			</router-link>

			<div class="ts-grid ts-kpis">
				<router-link
					:to="{ name: 'queue' }"
					class="ts-panel ts-stat ts-stat--link"
				>
					<div class="ts-stat__value">{{ data.open_items.total }}</div>
					<div class="ts-stat__label">Open</div>
					<div class="ts-stat__detail">
						{{ data.open_items.cases }} in queue ·
						{{ data.open_items.investigations }} investigation{{ data.open_items.investigations === 1 ? '' : 's' }}
						<template v-if="data.open_items.unassigned">
							· {{ data.open_items.unassigned }} unassigned
						</template>
					</div>
				</router-link>

				<router-link
					:to="{ name: 'queue', query: { with: 'me' } }"
					class="ts-panel ts-stat ts-stat--link"
				>
					<div class="ts-stat__value">{{ data.mine.total }}</div>
					<div class="ts-stat__label">Assigned to you</div>
					<div class="ts-stat__detail">
						{{ data.mine.cases }} case{{ data.mine.cases === 1 ? '' : 's' }} ·
						{{ data.mine.investigations }} file{{ data.mine.investigations === 1 ? '' : 's' }}
					</div>
				</router-link>

				<router-link
					:to="{ name: 'queue', query: { stale: '1' } }"
					class="ts-panel ts-stat ts-stat--link"
					:class="{ 'ts-stat--alert': data.due_a_look.total > 0 }"
				>
					<div class="ts-stat__value">{{ data.due_a_look.total }}</div>
					<div class="ts-stat__label">Due a look</div>
					<div class="ts-stat__detail">
						{{ data.due_a_look.stale_cases }} stale for a fortnight ·
						{{ data.due_a_look.overdue_files }} overdue
					</div>
				</router-link>

				<router-link
					:to="{ name: 'sanctions', query: { scope: 'broken' } }"
					class="ts-panel ts-stat ts-stat--link"
					:class="{ 'ts-stat--alert': data.attention.total > 0 }"
				>
					<div class="ts-stat__value">{{ data.attention.total }}</div>
					<div class="ts-stat__label">Needs attention</div>
					<div class="ts-stat__detail">{{ attentionDetail }}</div>
				</router-link>
			</div>

			<div v-if="warnings.length" class="ts-stack ts-section">
				<cdx-message
					v-for="warning in warnings"
					:key="warning.text"
					:type="warning.type"
					:allow-user-dismiss="false"
				>
					<span>{{ warning.text }}</span>
					<router-link v-if="warning.to" :to="warning.to">{{ warning.link }}</router-link>
				</cdx-message>
			</div>

			<div class="ts-split ts-section">
				<section>
					<h2 class="ts-section__title">Just in</h2>
					<div class="ts-panel ts-scroll">
						<cdx-table
							caption="Most recent submissions"
							:hide-caption="true"
							:columns="recentColumns"
							:data="data.recent"
						>
							<template #item-reference="{ row }">
								<router-link :to="{ name: 'case', params: { id: row.id } }" class="ts-mono">
									{{ row.reference }}
								</router-link>
							</template>
							<template #item-type="{ item }">{{ TYPE_LABELS[ item ] ?? item }}</template>
							<template #item-status="{ item }">
								<StatusChip kind="case" :value="item" />
							</template>
							<template #item-filed="{ item }">{{ ago( item ) }}</template>
							<template #empty-state>Nothing has come in yet.</template>
						</cdx-table>
					</div>
				</section>

				<section>
					<h2 class="ts-section__title">Due a second look</h2>
					<div class="ts-panel ts-stack">
						<p v-if="!data.reviews.length" class="ts-meta">
							Nothing seems to need a second look right now. Enjoy the peace.
 						</p>
						<div v-for="r in data.reviews" :key="r.id" class="ts-row">
							<div>
								<router-link :to="{ name: 'investigation', params: { id: r.id } }" class="ts-mono">
									{{ r.reference }}
								</router-link>
								<div class="ts-row__title">{{ r.title }}</div>
							</div>
							<span class="ts-meta">
								{{ r.assignee || 'nobody' }} · due {{ ago( r.due ) }}
							</span>
						</div>
					</div>

					<h2 class="ts-section__title ts-section">Recent activity</h2>
					<div class="ts-panel ts-stack">
						<p v-if="!data.activity.length" class="ts-meta">Nothing yet.</p>
						<div v-for="( entry, i ) in data.activity" :key="i">
							<div><strong>{{ entry.actor }}</strong> — {{ entry.action }}</div>
							<div class="ts-meta">
								<router-link
									v-if="entry.reference && entry.route"
									:to="{ name: entry.route, params: { id: entry.target_id } }"
									class="ts-mono"
								>
									{{ entry.reference }}
								</router-link>
								<span v-else-if="entry.reference" class="ts-mono">{{ entry.reference }}</span>
								<template v-if="entry.reference"> · </template>
								{{ ago( entry.at ) }}
							</div>
						</div>
					</div>
				</section>
			</div>
		</template>
	</main>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { CdxButton, CdxIcon, CdxMessage, CdxProgressBar, CdxTable } from '@wikimedia/codex';
import { cdxIconReload } from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import StatusChip from '../components/StatusChip.vue';
import { api } from '../lib/api.js';
import { ago, TYPE_LABELS } from '../lib/format.js';
import { session } from '../lib/session.js';

const data = ref( null );
const loading = ref( false );
const error = ref( null );

const recentColumns = [
	{ id: 'reference', label: 'Ref', width: '8rem' },
	{ id: 'type', label: 'Kind', width: '6rem' },
	{ id: 'subject', label: 'About' },
	{ id: 'status', label: 'Status', width: '10rem' },
	{ id: 'filed', label: 'Filed', width: '7rem' }
];

const attentionDetail = computed( () => {
	const a = data.value?.attention;
	if ( !a ) {
		return '';
	}

	const parts = [];
	if ( a.partial_actions ) {
		parts.push( `${ a.partial_actions } recorded but not enforced` );
	}
	if ( a.failed_actions ) {
		parts.push( `${ a.failed_actions } action${ a.failed_actions === 1 ? '' : 's' } failed` );
	}
	if ( a.failed_removals ) {
		parts.push( `${ a.failed_removals } erasure${ a.failed_removals === 1 ? '' : 's' } failed` );
	}
	if ( a.stuck_events ) {
		parts.push( `${ a.stuck_events } update${ a.stuck_events === 1 ? '' : 's' } gave up` );
	}
	if ( a.manual_actions ) {
		parts.push( `${ a.manual_actions } needing a person` );
	}

	return parts.length ? parts.join( ' · ' ) : 'Nothing outstanding.';
} );

const warnings = computed( () => {
	const d = data.value;
	if ( !d ) {
		return [];
	}

	const list = [];

	if ( d.attention.partial_actions > 0 ) {
		list.push( {
			type: 'error',
			text: `${ d.attention.partial_actions } suspension(s) were recorded on the wiki without the `
				+ 'global lock being confirmed. Those accounts are listed as suspended but may still '
				+ 'be able to sign in. Consider manually locking such accounts. ',
			to: { name: 'sanctions', query: { scope: 'broken' } },
			link: 'Find them'
		} );
	}

	if ( d.wiki.unknown_actions && d.wiki.unknown_actions.length ) {
		list.push( {
			type: 'error',
			text: `MW_SUPPORTED_ACTIONS names ${ d.wiki.unknown_actions.length } thing(s) that are `
				+ `not currently compatible: ${ d.wiki.unknown_actions.join( ', ' ) }. `
		} );
	}

	if ( !d.wiki.centralauth_lock ) {
		list.push( {
			type: 'warning',
			text: 'Central locking is disabled here. A suspension will explain itself on '
				+ 'the login screen but may not stop the account signing in.'
		} );
	}

	if ( !d.wiki.push_enabled ) {
		list.push( {
			type: 'warning',
			text: `Pushing to WikiOasis is switched off. Jobs are still recorded, and the `
				+ `${ d.wiki.queued } update(s) queued will be sent when it is switched back on.`
		} );
	}

	if ( d.attention.stuck_events > 0 ) {
		list.push( {
			type: 'error',
			text: `${ d.attention.stuck_events } update(s) gave up trying to reach WikiOasis, so nobody `
				+ 'there can see them. Run php artisan tsportal:sync --retry-stuck once it is reachable.'
		} );
	}

	if ( d.attention.removals_waiting > 0 ) {
		list.push( {
			type: 'notice',
			text: `${ d.attention.removals_waiting } erasure(s) are submitted and waiting for `
				+ 'second approval. ',
			to: { name: 'data-removals' },
			link: 'Read them'
		} );
	}

	return list;
} );

async function load() {
	loading.value = true;
	error.value = null;
	try {
		data.value = await api.dashboard();
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

onMounted( load );
</script>
