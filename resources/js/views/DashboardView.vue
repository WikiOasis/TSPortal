<template>
	<main class="ts-page ts-dash">
		<PageHeader :title="greeting" :subtitle="summary">
			<template #actions>
				<cdx-button :disabled="loading" @click="load">
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
				<div v-if="data.threat_to_life.unassigned" class="ts-stat__detail">
					{{ data.threat_to_life.unassigned }} with nobody assigned
				</div>
			</router-link>

			<nav class="ts-strip ts-dash__strip" aria-label="At a glance">
				<router-link
					v-for="tile in tiles"
					:key="tile.key"
					:to="tile.to"
					class="ts-strip__cell ts-stat"
					:class="{
						'ts-stat--alert': tile.alert,
						'ts-strip__cell--empty': !tile.value
					}"
				>
					<div class="ts-stat__label">{{ tile.label }}</div>
					<div class="ts-stat__value">{{ tile.value.toLocaleString() }}</div>
					<div class="ts-stat__detail">{{ tile.detail }}</div>
				</router-link>
			</nav>

			<div v-if="warnings.length" class="ts-stack ts-dash__warnings">
				<cdx-message
					v-for="warning in warnings"
					:key="warning.text"
					:type="warning.type"
					:allow-user-dismiss="false"
				>
					{{ warning.text }}
					<router-link v-if="warning.to" :to="warning.to">{{ warning.link }}</router-link>
				</cdx-message>
			</div>

			<div class="ts-split ts-section">
				<section aria-labelledby="dash-recent">
					<div class="ts-section-head">
						<h2 id="dash-recent" class="ts-section__title">Latest reports</h2>
						<router-link :to="{ name: 'queue' }" class="ts-dash__more">Open the queue</router-link>
					</div>
					<div class="ts-panel ts-scroll ts-dash__table">
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
							<template #item-subject="{ row }">
								<div class="ts-dash__about">{{ row.subject || TYPE_LABELS[ row.type ] || row.type }}</div>
								<div class="ts-meta">
									{{ TYPE_LABELS[ row.type ] ?? row.type }} · {{ row.reporter || 'anonymous' }}
								</div>
							</template>
							<template #item-status="{ item }">
								<StatusChip kind="case" :value="item" />
							</template>
							<template #item-filed="{ item }">
								<time class="ts-meta" :datetime="item">{{ ago( item ) }}</time>
							</template>
							<template #empty-state>Nothing has come in yet.</template>
						</cdx-table>
					</div>
				</section>

				<aside class="ts-dash__aside">
					<section aria-labelledby="dash-reviews">
						<div class="ts-section-head">
							<h2 id="dash-reviews" class="ts-section__title">Due a second look</h2>
							<router-link
								v-if="data.reviews.length"
								:to="{ name: 'investigations' }"
								class="ts-dash__more"
							>
								All files
							</router-link>
						</div>
						<div class="ts-panel">
							<p v-if="!data.reviews.length" class="ts-meta ts-dash__empty">
								No investigation is waiting on a second look.
							</p>
							<ul v-else class="ts-dash__list">
								<li v-for="r in data.reviews" :key="r.id">
									<router-link :to="{ name: 'investigation', params: { id: r.id } }" class="ts-dash__item">
										<span class="ts-dash__item-title">{{ r.title }}</span>
										<span class="ts-meta">
											<span class="ts-mono">{{ r.reference }}</span>
											· {{ r.assignee || 'nobody assigned' }}
											· due {{ ago( r.due ) }}
										</span>
									</router-link>
								</li>
							</ul>
						</div>
					</section>

					<section aria-labelledby="dash-activity">
						<div class="ts-section-head">
							<h2 id="dash-activity" class="ts-section__title">Recent activity</h2>
							<router-link :to="{ name: 'audit' }" class="ts-dash__more">Audit log</router-link>
						</div>
						<div class="ts-panel">
							<p v-if="!data.activity.length" class="ts-meta ts-dash__empty">Nothing has happened yet.</p>
							<ol v-else class="ts-dash__list ts-dash__activity">
								<li v-for="( entry, i ) in data.activity" :key="i">
									<div>
										<strong>{{ entry.actor }}</strong>
										<span class="ts-dash__verb"> · {{ entry.label || entry.action }}</span>
									</div>
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
										<time :datetime="entry.at" :title="dateTime( entry.at )">{{ ago( entry.at ) }}</time>
									</div>
								</li>
							</ol>
						</div>
					</section>
				</aside>
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
import { ago, dateTime, TYPE_LABELS } from '../lib/format.js';
import { session } from '../lib/session.js';

const data = ref( null );
const loading = ref( false );
const error = ref( null );

const recentColumns = [
	{ id: 'reference', label: 'Ref', width: '8rem' },
	{ id: 'subject', label: 'About' },
	{ id: 'status', label: 'Status', width: '9rem' },
	{ id: 'filed', label: 'Filed', width: '7rem' }
];

const plural = ( n, one, many = `${ one }s` ) => `${ n } ${ n === 1 ? one : many }`;

const greeting = computed( () => {
	const hour = new Date().getHours();
	const part = hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening';
	return `Good ${ part }, ${ session.user.username }`;
} );

const summary = computed( () => {
	const d = data.value;
	if ( !d ) {
		return 'Here is where things stand.';
	}
	if ( !d.mine.total ) {
		return 'Nothing is assigned to you right now.';
	}
	return `You have ${ plural( d.mine.total, 'open item' ) } assigned to you.`;
} );

const tiles = computed( () => {
	const d = data.value;
	if ( !d ) {
		return [];
	}

	const open = [ plural( d.open_items.cases, 'case' ), plural( d.open_items.investigations, 'file' ) ];
	if ( d.open_items.unassigned ) {
		open.push( `${ d.open_items.unassigned } unassigned` );
	}

	const list = [
		{
			key: 'mine',
			label: 'Assigned to you',
			value: d.mine.total,
			detail: `${ plural( d.mine.cases, 'case' ) } · ${ plural( d.mine.investigations, 'file' ) }`,
			to: { name: 'queue', query: { with: 'me' } }
		},
		{
			key: 'open',
			label: 'Open',
			value: d.open_items.total,
			detail: open.join( ' · ' ),
			to: { name: 'queue' }
		},
		{
			key: 'due',
			label: 'Due a look',
			value: d.due_a_look.total,
			alert: d.due_a_look.total > 0,
			detail: `${ d.due_a_look.stale_cases } quiet for 14 days · ${ d.due_a_look.overdue_files } overdue`,
			to: { name: 'queue', query: { stale: '1' } }
		}
	];

	if ( d.automation && d.automation.open ) {
		const auto = [ `${ d.automation.review.toLocaleString() } to review`, `${ d.automation.unlikely.toLocaleString() } unlikely` ];
		if ( d.automation.waiting ) {
			auto.push( `${ d.automation.waiting.toLocaleString() } unsorted` );
		}
		list.push( {
			key: 'automation',
			label: 'Automated, urgent',
			value: d.automation.urgent,
			alert: d.automation.unassigned_urgent > 0,
			detail: auto.join( ' · ' ),
			to: { name: 'automation' }
		} );
	}

	list.push( {
		key: 'attention',
		label: 'Needs attention',
		value: d.attention.total,
		alert: d.attention.total > 0,
		detail: attentionDetail.value,
		to: { name: 'sanctions', query: { scope: 'broken' } }
	} );

	return list;
} );

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
