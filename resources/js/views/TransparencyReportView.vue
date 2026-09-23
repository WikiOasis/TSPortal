<template>
	<main class="ts-page">
		<LoadError :error="error" :retry="reload" />
		<cdx-progress-bar v-if="loading" aria-label="Loading report" />

		<template v-if="report">
			<PageHeader :title="report.title" :subtitle="periodText">
				<template #actions>
					<cdx-button @click="exportCsv">
						<cdx-icon :icon="cdxIconDownload" />
						Download CSV
					</cdx-button>
					<cdx-button
						v-if="report.editable"
						:disabled="busy"
						@click="regenerate"
					>
						Rebuild
					</cdx-button>
					<cdx-button
						v-if="report.editable && session.can( 'admin' )"
						action="progressive"
						weight="primary"
						:disabled="busy"
						@click="confirming = true"
					>
						Publish
					</cdx-button>
				</template>
			</PageHeader>

			<cdx-message v-if="report.status === 'published'" type="success" :allow-user-dismiss="false">
				Published {{ dateTime( report.published_at ) }} by {{ report.published_by }}.
				These figures are now frozen.
			</cdx-message>

			<section v-if="figures" class="ts-section">
				<h2>How these were produced</h2>
				<div class="ts-panel">
					<p>{{ figures.method.suppression }}</p>
					<p v-if="figures.method.automated">{{ figures.method.automated }}</p>
					<p class="ts-meta">
						Covering {{ date( figures.period.from ) }} to {{ date( figures.period.to ) }} —
						{{ figures.period.days }} days. Computed {{ dateTime( figures.method.generated_at ) }}.
					</p>
				</div>
			</section>

			<section v-if="figures" class="ts-section">
				<h2>What came in</h2>
				<div class="ts-charts">
					<div class="ts-panel">
						<h3 class="ts-chart__title">Totals</h3>
						<dl class="ts-dl">
							<div>
								<dt>Received</dt>
								<dd>{{ figures.reports.total.display }}</dd>
							</div>
							<div>
								<dt>With no category</dt>
								<dd>{{ figures.reports.uncategorised.display }}</dd>
							</div>
						</dl>
						<BarList
							title="By kind"
							:data="bars( figures.reports.by_type )"
							:withheld="withheld( figures.reports.by_type )"
						/>
						<BarList
							v-if="figures.reports.by_source"
							title="By source"
							:data="bars( figures.reports.by_source )"
							:withheld="withheld( figures.reports.by_source )"
						/>
					</div>

					<div class="ts-panel">
						<BarList
							title="By category"
							subtitle="A case can be more than one thing, so these add up to more than the total."
							:data="bars( figures.reports.by_category )"
							:withheld="withheld( figures.reports.by_category )"
						/>
					</div>

					<div class="ts-panel">
						<BarList
							title="By area"
							:data="bars( figures.reports.by_group )"
							:withheld="withheld( figures.reports.by_group )"
						/>
					</div>

					<div class="ts-panel">
						<BarList
							title="How they ended"
							:data="bars( figures.reports.how_they_ended )"
							:withheld="withheld( figures.reports.how_they_ended )"
						/>
					</div>

					<div v-if="figures.reports.automated" class="ts-panel">
						<h3 class="ts-chart__title">Raised by automated scanning</h3>
						<dl class="ts-dl">
							<div>
								<dt>Received</dt>
								<dd>{{ figures.reports.automated.total.display }}</dd>
							</div>
						</dl>
						<BarList
							title="How they ended"
							:data="bars( figures.reports.automated.how_they_ended )"
							:withheld="withheld( figures.reports.automated.how_they_ended )"
						/>
					</div>
				</div>
			</section>

			<section v-if="figures" class="ts-section">
				<h2>What was done</h2>
				<div class="ts-charts">
					<div class="ts-panel">
						<h3 class="ts-chart__title">Totals</h3>
						<dl class="ts-dl">
							<div>
								<dt>Actions taken</dt>
								<dd>{{ figures.actions.total.display }}</dd>
							</div>
							<div>
								<dt>Actions lifted</dt>
								<dd>{{ figures.actions.lifted.display }}</dd>
							</div>
							<div>
								<dt>With no reason category recorded</dt>
								<dd>{{ figures.actions.no_reason_recorded.display }}</dd>
							</div>
						</dl>
					</div>
					<div class="ts-panel">
						<BarList
							title="By action"
							:data="bars( figures.actions.by_type )"
							:withheld="withheld( figures.actions.by_type )"
						/>
					</div>
					<div class="ts-panel">
						<BarList
							title="By reason"
							:data="bars( figures.actions.by_reason )"
							:withheld="withheld( figures.actions.by_reason )"
						/>
					</div>
				</div>
			</section>

			<section v-if="figures" class="ts-section">
				<h2>Appeals</h2>

				<div class="ts-charts">
					<div class="ts-panel">
						<dl class="ts-dl">
							<div>
								<dt>Received</dt>
								<dd>{{ figures.appeals.received.display }}</dd>
							</div>
							<div>
								<dt>Decided</dt>
								<dd>{{ figures.appeals.decided.display }}</dd>
							</div>
							<div>
								<dt>Accepted</dt>
								<dd>{{ figures.appeals.accepted.display }}</dd>
							</div>
							<div>
								<dt>Accepted, as a share</dt>
								<dd>
									<template v-if="figures.appeals.accepted_share !== null">
										{{ figures.appeals.accepted_share }}%
									</template>
									<template v-else>Not published — too few decided</template>
								</dd>
							</div>
						</dl>

						<p class="ts-meta">
							The share is of appeals decided on their merits. Appeals that were
							withdrawn, or that turned out to be against nothing, are counted
							above but left out of it: neither says anything about whether the
							original action was right.
						</p>
					</div>

					<div class="ts-panel">
						<BarList
							title="How they ended"
							:data="bars( figures.appeals.by_outcome )"
							:withheld="withheld( figures.appeals.by_outcome )"
						/>
					</div>
				</div>

				<div class="ts-panel">
					<BarList
						title="Accepted, by what the action was for"
						subtitle="Each bar is the appeals decided on their merits; the inner bar is how many were accepted."
						label="Decided"
						overlay-label="Accepted"
						:data="appealRows"
						:withheld="appealsWithheld"
						empty-text="No category had enough appeals decided in this period to publish a rate for."
					/>

					<p v-if="appealsUncategorised" class="ts-meta">
						{{ appealsUncategorised }} decided against actions with no policy ground
						recorded, so they appear in no row above. That is a gap in our own record
						rather than a kind of infraction.
					</p>

					<p v-if="figures.appeals.not_linked_to_an_action.value !== 0" class="ts-meta">
						{{ figures.appeals.not_linked_to_an_action.display }} appeals could not be
						matched to any action at all. They are missing from every row above.
					</p>
				</div>
			</section>

			<section v-if="figures" class="ts-section">
				<h2>Data requests</h2>
				<div class="ts-charts">
					<div class="ts-panel">
						<dl class="ts-dl">
							<div>
								<dt>Received</dt>
								<dd>{{ figures.data_requests.received.display }}</dd>
							</div>
							<div>
								<dt>Approved</dt>
								<dd>{{ figures.data_requests.approved.display }}</dd>
							</div>
							<div>
								<dt>Declined</dt>
								<dd>{{ figures.data_requests.declined.display }}</dd>
							</div>
							<div>
								<dt>Erasures completed</dt>
								<dd>{{ figures.data_requests.erasures_completed.display }}</dd>
							</div>
						</dl>
					</div>
					<div class="ts-panel">
						<BarList
							title="What was asked for"
							:data="bars( figures.data_requests.by_kind )"
							:withheld="withheld( figures.data_requests.by_kind )"
						/>
					</div>
				</div>
			</section>

			<section v-if="figures" class="ts-section">
				<h2>CheckUser</h2>
				<div class="ts-charts">
					<div class="ts-panel">
						<dl class="ts-dl">
							<div>
								<dt>Checks run</dt>
								<dd>{{ figures.checkuser.total.display }}</dd>
							</div>
							<div>
								<dt>Checkers</dt>
								<dd>{{ figures.checkuser.checkers.display }}</dd>
							</div>
							<div>
								<dt>With no reason recorded</dt>
								<dd>
									{{ figures.checkuser.without_a_reason.display }}
									<span v-if="figures.checkuser.without_a_reason_share !== null" class="ts-meta">
										({{ figures.checkuser.without_a_reason_share }}%)
									</span>
								</dd>
							</div>
							<div>
								<dt>Wikis reporting</dt>
								<dd>{{ figures.checkuser.wikis_reporting }}</dd>
							</div>
						</dl>
						<p class="ts-meta">
							Only wikis that report their CheckUser log are counted here.
						</p>
					</div>
					<div class="ts-panel">
						<BarList
							title="By kind of check"
							:data="bars( figures.checkuser.by_type )"
							:withheld="withheld( figures.checkuser.by_type )"
						/>
					</div>
				</div>
			</section>

			<section class="ts-section">
				<h2>Notes</h2>
				<div class="ts-panel">
					<cdx-field>
						<template #label>What the figures do not say on their own</template>
						<template #description>
							A report that is only a table invites every reader to supply their own
							explanation for a spike.
						</template>
						<cdx-text-area v-model="notes" rows="6" />
					</cdx-field>
					<div class="ts-inline" style="margin-top: 0.75rem;">
						<cdx-button :disabled="saving || notes === ( report.notes ?? '' )" @click="saveNotes">
							{{ saving ? 'Saving…' : 'Save notes' }}
						</cdx-button>
					</div>
				</div>
			</section>
		</template>

		<cdx-dialog
			v-model:open="confirming"
			title="Publish this report?"
			:use-close-button="true"
			:primary-action="{ label: 'Publish', actionType: 'progressive', disabled: busy }"
			:default-action="{ label: 'Not yet' }"
			@primary="publish"
			@default="confirming = false"
		>
			<p>
				Publishing freezes these figures. They cannot be recomputed afterwards, because
				other people will quote them — a correction is a new report that says what it
				corrects.
			</p>
		</cdx-dialog>
	</main>
</template>

<script setup>
import { computed, inject, onMounted, ref, watch } from 'vue';
import {
	CdxButton, CdxDialog, CdxField, CdxIcon, CdxMessage, CdxProgressBar, CdxTextArea
} from '@wikimedia/codex';
import { cdxIconDownload } from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import BarList from '../components/BarList.vue';
import { api } from '../lib/api.js';
import { date, dateTime } from '../lib/format.js';
import { session } from '../lib/session.js';

const props = defineProps( { id: { type: [ String, Number ], required: true } } );
const notify = inject( 'notify' );

const report = ref( null );
const loading = ref( false );
const saving = ref( false );
const busy = ref( false );
const error = ref( null );
const confirming = ref( false );
const notes = ref( '' );

const figures = computed( () => report.value?.figures ?? null );

const periodText = computed( () => report.value
	? `${ date( report.value.period_start ) } to ${ date( report.value.period_end ) } · ${ report.value.reference }`
	: '' );

const appealRows = computed( () => ( figures.value?.appeals?.by_infraction?.rows ?? [] ).map( ( row ) => ( {
	key: row.key,
	label: `${ row.label } — ${ row.share }%`,
	total: Number( row.decided ?? 0 ),
	overlay: Number( row.accepted ?? 0 )
} ) ) );

const appealsWithheld = computed( () => withheld( figures.value?.appeals?.by_infraction ) );

const appealsUncategorised = computed( () => {
	const gap = figures.value?.appeals?.by_infraction?.no_category?.decided;

	return gap && gap.value !== 0 ? gap.display : '';
} );

function bars( section ) {
	return ( section?.rows ?? [] ).map( ( row ) => ( {
		key: row.key,
		label: row.label,
		total: Number( row.total ?? 0 )
	} ) );
}

function withheld( section ) {
	const count = section?.withheld?.rows ?? 0;
	if ( !count ) {
		return '';
	}

	const total = section.withheld.total;

	return `${ count } ${ count === 1 ? 'category is' : 'categories are' } not shown, each with too ` +
		`few to publish${ total ? `. Between them, ${ total }` : '' }.`;
}

async function reload() {
	loading.value = true;
	error.value = null;
	try {
		report.value = await api.transparencyReport( props.id );
		notes.value = report.value.notes ?? '';
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

async function regenerate() {
	busy.value = true;
	try {
		report.value = await api.regenerateTransparencyReport( props.id );
		notify( 'Rebuilt from the current data.', 'success' );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		busy.value = false;
	}
}

async function publish() {
	busy.value = true;
	try {
		report.value = await api.publishTransparencyReport( props.id );
		confirming.value = false;
		notify( 'Published. These figures are now frozen.', 'success' );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		busy.value = false;
	}
}

async function saveNotes() {
	saving.value = true;
	try {
		report.value = await api.updateTransparencyReport( props.id, { notes: notes.value } );
		notify( 'Notes saved.', 'success' );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		saving.value = false;
	}
}

function exportCsv() {
	window.location.href = `/api/portal/transparency/${ props.id }/export`;
}

watch( () => props.id, reload );
onMounted( reload );
</script>
