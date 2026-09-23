<template>
	<main class="ts-page">
		<PageHeader
			title="Activity"
		/>

		<div class="ts-toolbar">
			<cdx-field>
				<template #label>Period</template>
				<cdx-select v-model:selected="range" :menu-items="ranges" @update:selected="onRange" />
			</cdx-field>

			<cdx-field v-if="range === 'custom'">
				<template #label>From</template>
				<cdx-text-input v-model="from" input-type="date" @change="reload" />
			</cdx-field>
			<cdx-field v-if="range === 'custom'">
				<template #label>To</template>
				<cdx-text-input v-model="to" input-type="date" @change="reload" />
			</cdx-field>

			<cdx-field>
				<template #label>Wiki</template>
				<cdx-select v-model:selected="wiki" :menu-items="wikiOptions" @update:selected="reload" />
			</cdx-field>

			<cdx-field>
				<template #label>Source</template>
				<cdx-select v-model:selected="source" :menu-items="sourceOptions" @update:selected="reload" />
			</cdx-field>
		</div>

		<LoadError :error="error" :retry="reload" />
		<cdx-progress-bar v-if="loading" aria-label="Loading activity" />

		<template v-if="data">
			<p class="ts-meta ts-page__period">
				{{ date( data.period.from ) }} to {{ date( data.period.to ) }}, by {{ data.period.bucket }}.
			</p>

			<div class="ts-grid ts-kpis">
				<StatTile label="Received" :figure="data.headline.received" />
				<StatTile label="Closed" :figure="data.headline.closed" />
				<StatTile label="Actions taken" :figure="data.headline.actions" />
				<StatTile
					label="Median time to closure"
					:figure="data.headline.median_close_hours"
					unit="hours"
					:lower-is-better="true"
				/>
			</div>

			<section class="ts-section">
				<h2>What came in</h2>
				<div class="ts-panel">
					<TrendChart
						title="Reports, messages and requests received"
						:data="data.intake.over_time"
						:bucket="data.period.bucket"
					/>
				</div>

				<div class="ts-charts">
					<div class="ts-panel">
						<BarList
							title="By category"
							:subtitle="categorySubtitle"
							:data="data.intake.by_category.rows"
							null-label="Not categorised"
						/>

						<cdx-message
							v-if="data.intake.by_category.uncategorised > 0"
							type="notice"
							:inline="true"
						>
							{{ data.intake.by_category.uncategorised }} of
							{{ data.intake.by_category.cases }} were not able to be categorised.
						</cdx-message>
					</div>

					<div class="ts-panel">
						<BarList title="By kind" :data="typeRows" />
					</div>

					<div class="ts-panel">
						<BarList
							title="By source"
							subtitle="Every source, whichever one is selected above."
							:data="sourceRows"
						/>
						<p v-if="automatedSummary" class="ts-meta">{{ automatedSummary }}</p>
					</div>

					<div class="ts-panel">
						<BarList
							title="By wiki"
							:data="wikiRows"
							null-label="No wiki recorded"
						/>
					</div>
				</div>
			</section>

			<section class="ts-section">
				<h2>How it was handled</h2>

				<div class="ts-charts">
					<div class="ts-panel">
						<h3 class="ts-chart__title">Time to first reply</h3>
						<dl class="ts-dl">
							<div>
								<dt>Median</dt>
								<dd>{{ hours( data.handling.first_response.median_hours ) }}</dd>
							</div>
							<div>
								<dt>P90</dt>
								<dd>{{ hours( data.handling.first_response.p90_hours ) }}</dd>
							</div>
							<div>
								<dt>Unanswered</dt>
								<dd>{{ data.handling.first_response.unanswered }}</dd>
							</div>
						</dl>

						<h3 class="ts-chart__title ts-chart__title--spaced">Time to closure</h3>
						<dl class="ts-dl">
							<div>
								<dt>Median</dt>
								<dd>{{ hours( data.handling.time_to_close.median_hours ) }}</dd>
							</div>
							<div>
								<dt>P90</dt>
								<dd>{{ hours( data.handling.time_to_close.p90_hours ) }}</dd>
							</div>
							<div>
								<dt>Closed in period</dt>
								<dd>{{ data.handling.time_to_close.closed }}</dd>
							</div>
						</dl>
					</div>

					<div class="ts-panel">
						<BarList
							title="Open now, by age"
							:data="backlogRows"
							:limit="6"
							empty-text="Nothing is open."
						/>
						<p v-if="data.handling.backlog.oldest_days" class="ts-meta">
							The oldest open case has been waiting for
							{{ data.handling.backlog.oldest_days }} days.
						</p>
					</div>

					<div class="ts-panel">
						<BarList title="How cases ended" :data="closedRows" />
					</div>
				</div>
			</section>

			<section class="ts-section">
				<h2>What was done</h2>
				<div class="ts-charts">
					<div class="ts-panel">
						<TrendChart
							title="Actions taken"
							:data="data.outcomes.actions_over_time"
							:bucket="data.period.bucket"
						/>
					</div>
					<div class="ts-panel">
						<BarList title="By action" :data="actionTypeRows" />
					</div>
					<div class="ts-panel">
						<BarList
							title="By reason"
							:data="data.outcomes.actions_by_reason"
							null-label="No reason category recorded"
						/>
					</div>
					<div class="ts-panel">
						<BarList
							title="Investigations by outcome"
							:data="outcomeRows"
							null-label="No outcome recorded"
						/>
					</div>
				</div>
			</section>

			<section class="ts-section">
				<h2>Appeals</h2>
				<div class="ts-charts">
					<div class="ts-panel">
						<dl class="ts-dl">
							<div>
								<dt>Received</dt>
								<dd>{{ appeals.received }}</dd>
							</div>
							<div>
								<dt>Decided</dt>
								<dd>{{ appeals.decided }}</dd>
							</div>
							<div>
								<dt>Accepted</dt>
								<dd>{{ appeals.accepted }} of {{ appeals.decided_on_the_merits }}</dd>
							</div>
							<div>
								<dt>Acceptance rate</dt>
								<dd>{{ percent( appeals.accepted_share ) }}</dd>
							</div>
						</dl>
					</div>

					<div class="ts-panel">
						<BarList
							title="Accepted, by what the action was for"
							subtitle="Each bar is the appeals decided; the inner bar is how many accepted."
							label="Decided"
							overlay-label="Accepted"
							:data="appealInfractionRows"
							empty-text="Nothing decided in this period."
						/>
					</div>

					<div class="ts-panel">
						<BarList title="How they ended" :data="appealOutcomeRows" />
					</div>

					<div class="ts-panel">
						<dl class="ts-dl">
							<div>
								<dt>Waiting on a decision</dt>
								<dd>{{ appeals.awaiting_decision }}</dd>
							</div>
							<div>
								<dt>Undecided, and nobody has confirmed which action they are about</dt>
								<dd>{{ appeals.link_needs_checking }}</dd>
							</div>
							<div>
								<dt>Attached to no action at all</dt>
								<dd>{{ appeals.not_linked }}</dd>
							</div>
						</dl>
					</div>
				</div>
			</section>

			<section class="ts-section">
				<h2>Who is carrying it</h2>
				<div class="ts-panel ts-scroll">
					<cdx-table
						caption="Workload"
						:hide-caption="true"
						:columns="peopleColumns"
						:data="data.people"
					>
						<template #empty-state>Nothing is assigned to anybody.</template>
					</cdx-table>
				</div>
			</section>

			<section class="ts-section">
				<h2>CheckUser</h2>

				<cdx-message v-if="!data.options.checkuser_enabled" type="warning">
					No wiki is reporting CheckUser activity.
				</cdx-message>

				<template v-else>
					<div class="ts-grid ts-kpis">
						<StatTile label="Checks run" :figure="{ value: data.checkuser.total }" />
						<StatTile label="Checkers" :figure="{ value: data.checkuser.checkers }" />
						<StatTile
							label="With no reason recorded"
							:figure="{ value: data.checkuser.unexplained }"
							:tone="data.checkuser.unexplained > 0 ? 'warning' : 'plain'"
							:note="share( data.checkuser.unexplained_share )"
							:lower-is-better="true"
						/>
						<StatTile
							label="Wikis reporting"
							:figure="{ value: data.checkuser.by_wiki.length }"
						/>
					</div>

					<div class="ts-charts">
						<div class="ts-panel">
							<TrendChart
								title="Checks over time"
								:data="data.checkuser.over_time"
								:bucket="data.period.bucket"
							/>
						</div>
						<div class="ts-panel">
							<BarList
								title="By checker"
								subtitle="Both figures, because either alone misleads."
								:data="checkerRows"
								label="Checks"
								overlay-label="No reason given"
							/>
							<p class="ts-meta">
								<router-link :to="{ name: 'checkuser', query: { unexplained: 1 } }">
									Read the checks with no reason recorded
								</router-link>
							</p>
						</div>
						<div class="ts-panel">
							<BarList
								title="By kind of check"
								:data="checkTypeRows"
							/>
						</div>
						<div class="ts-panel">
							<BarList
								title="Targets checked more than once"
								subtitle="By fingerprint, so a repeat is countable without the portal holding the address."
								:data="repeatRows"
								empty-text="No target was checked twice."
							/>
						</div>
					</div>
				</template>
			</section>
		</template>
	</main>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
	CdxField, CdxMessage, CdxProgressBar, CdxSelect, CdxTable, CdxTextInput
} from '@wikimedia/codex';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import StatTile from '../components/StatTile.vue';
import TrendChart from '../components/TrendChart.vue';
import BarList from '../components/BarList.vue';
import { api } from '../lib/api.js';
import {
	date, hours, share, APPEAL_OUTCOME_LABELS,
	CHECK_TYPE_LABELS, OUTCOME_LABELS, STATUS_LABELS, TYPE_LABELS
} from '../lib/format.js';
import { SANCTION_TYPES, RETIRED_SANCTION_LABELS } from '../lib/format.js';

const route = useRoute();
const router = useRouter();

const data = ref( null );
const loading = ref( false );
const error = ref( null );

const range = ref( route.query.range ?? '90' );
const from = ref( route.query.from ?? '' );
const to = ref( route.query.to ?? '' );
const wiki = ref( route.query.wiki ?? '' );
const source = ref( route.query.source ?? '' );

const ranges = [
	{ value: '30', label: 'Last 30 days' },
	{ value: '90', label: 'Last quarter' },
	{ value: '180', label: 'Last six months' },
	{ value: '365', label: 'Last year' },
	{ value: 'custom', label: 'Choose dates' }
];

const wikiOptions = computed( () => [
	{ value: '', label: 'Every wiki' },
	...( data.value?.options.wikis ?? [] ).map( ( w ) => ( { value: w, label: w } ) )
] );

const SOURCE_LABELS = {
	people: 'Filed by people',
	automated: 'Raised by automated scanning'
};

const sourceOptions = [
	{ value: '', label: 'Every source' },
	...Object.entries( SOURCE_LABELS ).map( ( [ value, label ] ) => ( { value, label } ) )
];

const sourceRows = computed( () => label( data.value?.intake.by_source, SOURCE_LABELS ) );

const automatedSummary = computed( () => {
	const row = ( data.value?.intake.by_source ?? [] ).find( ( r ) => r.key === 'automated' );
	if ( !row || !row.closed ) {
		return '';
	}
	return `${ row.action_taken } of ${ row.closed } closed automated reports ` +
		`(${ percent( row.action_taken / row.closed ) }) led to action.`;
} );

const peopleColumns = [
	{ id: 'username', label: 'Who' },
	{ id: 'open_cases', label: 'Open cases', width: '8rem' },
	{ id: 'open_files', label: 'Open files', width: '8rem' },
	{ id: 'closed_in_period', label: 'Closed in period', width: '10rem' },
	{ id: 'actions_in_period', label: 'Actions in period', width: '10rem' }
];

const typeRows = computed( () => label( data.value?.intake.by_type, TYPE_LABELS ) );
const closedRows = computed( () => label( data.value?.handling.closed_by_status, STATUS_LABELS ) );
const outcomeRows = computed( () => label( data.value?.outcomes.investigations_by_outcome, OUTCOME_LABELS ) );
const checkTypeRows = computed( () => label( data.value?.checkuser.by_type, CHECK_TYPE_LABELS ) );

const actionTypeRows = computed( () => label(
	data.value?.outcomes.actions_by_type,
	{
		...Object.fromEntries( SANCTION_TYPES.map( ( t ) => [ t.value, t.label ] ) ),
		...RETIRED_SANCTION_LABELS
	}
) );

const wikiRows = computed( () => label( data.value?.intake.by_wiki, {} ) );

const appeals = computed( () => data.value?.outcomes.appeals ?? {
	received: 0, decided: 0, accepted: 0, decided_on_the_merits: 0,
	accepted_share: null, not_linked: 0, awaiting_decision: 0, link_needs_checking: 0
} );

const appealOutcomeRows = computed( () => label(
	data.value?.outcomes.appeals?.by_outcome,
	APPEAL_OUTCOME_LABELS
) );

const appealInfractionRows = computed( () => ( data.value?.outcomes.appeals?.by_infraction ?? [] ).map( ( row ) => ( {
	key: row.key,
	label: `${ row.label } — ${ Math.round( ( row.share ?? 0 ) * 100 ) }%`,
	total: Number( row.decided ?? 0 ),
	overlay: Number( row.accepted ?? 0 )
} ) ) );

function percent( value ) {
	return value === null || value === undefined ? '\u2014' : `${ Math.round( value * 100 ) }%`;
}

const backlogRows = computed( () => ( data.value?.handling.backlog.bands ?? [] )
	.map( ( band ) => ( { key: band.label, label: band.label, total: band.total } ) ) );

const checkerRows = computed( () => ( data.value?.checkuser.by_checker ?? [] )
	.map( ( row ) => ( {
		key: row.checker,
		label: row.checker,
		total: row.total,
		overlay: row.unexplained
	} ) ) );

const repeatRows = computed( () => ( data.value?.checkuser.repeat_targets ?? [] )
	.map( ( row ) => ( {
		key: row.fingerprint,
		label: row.target ?? `${ row.kind } · ${ row.fingerprint }`,
		total: row.total
	} ) ) );

const categorySubtitle = computed( () => {
	const rows = data.value?.intake.by_category;
	if ( !rows ) {
		return '';
	}
	return 'This may add up to move than the ' +
		`${ rows.cases } received due to cases holding multiple categories.`;
} );

function label( rows, labels ) {
	return ( rows ?? [] ).map( ( row ) => ( {
		key: row.key,
		label: row.label ?? labels[ row.key ] ?? row.key,
		total: row.total
	} ) );
}

function onRange( value ) {
	if ( value !== 'custom' ) {
		from.value = '';
		to.value = '';
	}
	reload();
}

function params() {
	if ( range.value === 'custom' ) {
		return {
			from: from.value || undefined,
			to: to.value || undefined,
			wiki: wiki.value || undefined,
			source: source.value || undefined
		};
	}

	const end = new Date();
	const start = new Date( end.getTime() - ( Number( range.value ) - 1 ) * 86400000 );

	return {
		from: start.toISOString().slice( 0, 10 ),
		to: end.toISOString().slice( 0, 10 ),
		wiki: wiki.value || undefined,
		source: source.value || undefined
	};
}

async function reload() {
	loading.value = true;
	error.value = null;

	router.replace( { query: {
		range: range.value,
		from: from.value || undefined,
		to: to.value || undefined,
		wiki: wiki.value || undefined,
		source: source.value || undefined
	} } );

	try {
		data.value = await api.analytics( params() );
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

onMounted( reload );
</script>
