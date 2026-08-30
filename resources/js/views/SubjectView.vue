<template>
	<main class="ts-page">
		<LoadError :error="error" :retry="load" />
		<cdx-progress-bar v-if="loading && !item" aria-label="Loading the account" />

		<template v-if="item">
			<PageHeader :title="item.username" :subtitle="item.central_id ? `Central id ${ item.central_id }` : 'No matching account on the farm'">
				<template #actions>
					<StatusChip kind="standing" :value="item.standing" />

					<cdx-button @click="showOpenFile = true">
						<cdx-icon :icon="cdxIconAdd" size="small" />
						Open a file
					</cdx-button>

					<cdx-button action="progressive" weight="primary" @click="startIssue">
						Take an action
					</cdx-button>
				</template>
			</PageHeader>

			<cdx-message v-if="item.unresolved" type="warning" :allow-user-dismiss="false">
				Nothing on the farm matches this name.
			</cdx-message>

			<div class="ts-split">
				<div>
					<section class="ts-section">
						<h2 class="ts-section__title">Actions on file</h2>
						<div class="ts-panel ts-stack">
							<p v-if="!item.sanctions.length" class="ts-meta">Nothing has ever been taken against this account.</p>

							<div v-for="s in item.sanctions" :key="s.id" class="ts-panel">
								<div class="ts-inline">
									<strong>{{ s.label }}</strong>
									<span class="ts-mono">{{ s.reference }}</span>
									<cdx-info-chip :status="s.active ? 'error' : 'notice'">
										{{ s.active ? 'In force' : ( s.expired ? 'Expired' : 'Lifted' ) }}
									</cdx-info-chip>
									<StatusChip kind="push" :value="s.push_state" />
								</div>

								<dl class="ts-dl" style="margin-top: 0.5rem;">
									<dt v-if="s.investigation_id">Out of</dt>
									<dd v-if="s.investigation_id">
										<router-link
											:to="{ name: 'investigation', params: { id: s.investigation_id } }"
											class="ts-mono"
										>
											{{ s.investigation }}
										</router-link>
									</dd>
									<dt>Where</dt>
									<dd>{{ s.where }}</dd>
									<dt>Given</dt>
									<dd>{{ dateTime( s.issued ) }} by {{ s.issuer || 'unknown' }}</dd>
									<dt>Ends</dt>
									<dd>{{ s.expires ? dateTime( s.expires ) : 'Does not expire on its own' }}</dd>
									<dt>Reason given</dt>
									<dd>{{ s.reason }}</dd>
									<dt v-if="s.internal_reason">Internal</dt>
									<dd v-if="s.internal_reason">{{ s.internal_reason }}</dd>
									<dt v-if="s.lift_reason">Lifted</dt>
									<dd v-if="s.lift_reason">{{ dateTime( s.lifted_at ) }} — {{ s.lift_reason }}</dd>
								</dl>

								<cdx-message
									v-if="s.push_state === 'manual' || s.push_state === 'failed'"
									:type="s.push_state === 'failed' ? 'error' : 'warning'"
									:inline="true"
									:allow-user-dismiss="false"
								>
									{{ s.push_error }}
								</cdx-message>

								<div v-if="s.active" style="margin-top: 0.5rem;">
									<cdx-button @click="startLift( s )">Lift this</cdx-button>
								</div>
							</div>
						</div>
					</section>

					<section class="ts-section">
						<h2 class="ts-section__title">Reports about this account</h2>
						<div class="ts-panel ts-scroll">
							<cdx-table caption="Cases naming this account" :hide-caption="true" :columns="caseColumns" :data="item.cases_about">
								<template #item-reference="{ item: ref }"><span class="ts-mono">{{ ref }}</span></template>
								<template #item-status="{ item: st }"><StatusChip kind="case" :value="st" /></template>
								<template #item-filed="{ item: f }">{{ date( f ) }}</template>
								<template #empty-state>Nobody has reported this account.</template>
							</cdx-table>
						</div>
					</section>

					<section class="ts-section">
						<h2 class="ts-section__title">Filed by this account</h2>
						<div class="ts-panel ts-scroll">
							<cdx-table caption="Cases filed by this account" :hide-caption="true" :columns="caseColumns" :data="item.cases_filed">
								<template #item-reference="{ item: ref }"><span class="ts-mono">{{ ref }}</span></template>
								<template #item-status="{ item: st }"><StatusChip kind="case" :value="st" /></template>
								<template #item-filed="{ item: f }">{{ date( f ) }}</template>
								<template #empty-state>This account has filed nothing.</template>
							</cdx-table>
						</div>
					</section>
				</div>

				<aside class="ts-stack">
					<div class="ts-panel">
						<h2 class="ts-section__title">Details</h2>
						<dl class="ts-dl">
							<dt>Standing</dt>
							<dd><StatusChip kind="standing" :value="item.standing" /></dd>
							<dt>Suspended</dt>
							<dd>{{ item.banned ? 'Yes — cannot sign in' : 'No' }}</dd>
							<dt>Email</dt>
							<dd>{{ item.email || 'not known to the portal' }}</dd>
						</dl>
					</div>

					<div class="ts-panel ts-stack">
						<h2 class="ts-section__title">Files on this account</h2>
						<p v-if="!item.investigations.length" class="ts-meta">
							None. A file can be opened at any time, whether or not anybody has
							reported this account.
						</p>
						<div v-for="f in item.investigations" :key="f.id" class="ts-row">
							<div>
								<router-link
									:to="{ name: 'investigation', params: { id: f.id } }"
									class="ts-mono"
								>
									{{ f.reference }}
								</router-link>
								<div class="ts-row__title">{{ f.title }}</div>
							</div>
							<div class="ts-inline">
								<StatusChip kind="role" :value="f.role" />
								<StatusChip kind="investigation" :value="f.status" />
							</div>
						</div>
					</div>

					<div class="ts-panel ts-stack">
						<h2 class="ts-section__title">Erasure</h2>

						<div v-if="item.removals.length">
							<div v-for="r in item.removals" :key="r.id" class="ts-row">
								<div>
									<router-link :to="{ name: 'data-removals' }" class="ts-mono">
										{{ r.reference }}
									</router-link>
								</div>
								<StatusChip kind="removal" :value="r.state" />
							</div>
						</div>

						<p v-else class="ts-meta">
							Nothing has been asked for. Removing an account's personal data cannot be
							undone.
						</p>

						<cdx-button
							v-if="!item.removals.some( ( r ) => r.outstanding )"
							action="destructive"
							@click="showRemoval = true"
						>
							Remove this account's data
						</cdx-button>
					</div>

					<div class="ts-panel ts-stack">
						<h2 class="ts-section__title">Notes</h2>
						<cdx-text-area v-model="notes" rows="6" placeholder="Context that is not tied to one case." />
						<cdx-button :disabled="savingNotes" @click="saveNotes">Save notes</cdx-button>
					</div>
				</aside>
			</div>

			<IssueActionDialog
				v-model:open="showIssue"
				:accounts="[ { id: item.id, username: item.username } ]"
				:files="liveFiles"
				@issued="onIssued"
			/>

			<cdx-dialog
				v-model:open="showRemoval"
				title="Remove this account's personal data"
				:use-close-button="true"
				:primary-action="{
					label: erasing ? 'Starting…' : 'Remove it now',
					actionType: 'destructive',
					disabled: !removal.reason.trim() || erasing
				}"
				:default-action="{ label: 'Cancel' }"
				@primary="submitRemoval"
				@default="showRemoval = false"
			>
				<div class="ts-stack">
					<cdx-message type="error" :allow-user-dismiss="false">
						<p>
							<strong>{{ item.username }}</strong> will be renamed across every wiki it is
							attached to, its user pages deleted with suppression, and its address, real
							name, IP records and check-user entries removed or blanked.
						</p>
					</cdx-message>

					<cdx-progress-bar v-if="erasing" aria-label="Starting the erasure" />

					<cdx-field>
						<template #label>Under what</template>
						<template #description>
							What entitles the portal to do this. On the record, because the answer to
							"why was this account erased" has to survive the erasure.
						</template>
						<cdx-select v-model:selected="removal.legal_basis" :menu-items="basisOptions" />
					</cdx-field>

					<cdx-field>
						<template #label>Why</template>
						<template #description>
							For the file. Worth saying whether anything is open against this account,
							because an erasure while a report is live loses the report's subject.
						</template>
						<cdx-text-area v-model="removal.reason" rows="3" />
					</cdx-field>

					<cdx-field>
						<template #label>Which data request</template>
						<template #description>Optional. The reference of the request this answers.</template>
						<cdx-text-input v-model="removal.case_reference" placeholder="TS-2026-0520" />
					</cdx-field>
				</div>
			</cdx-dialog>

			<OpenInvestigationDialog
				v-model:open="showOpenFile"
				:subjects="[ item.username ]"
				@opened="onFileOpened"
			/>

			<cdx-dialog
				v-model:open="showLift"
				title="Lift this action"
				:use-close-button="true"
				:primary-action="{ label: 'Lift it', actionType: 'destructive', disabled: !liftReason.trim() }"
				:default-action="{ label: 'Cancel' }"
				@primary="submitLift"
				@default="showLift = false"
			>
				<div class="ts-stack">
					<p>
						{{ lifting?.label }} <span class="ts-mono">{{ lifting?.reference }}</span> stops
						applying. The record stays — this is a withdrawal, not a deletion.
					</p>
					<cdx-field>
						<template #label>Why</template>
						<cdx-text-area v-model="liftReason" rows="3" />
					</cdx-field>
				</div>
			</cdx-dialog>
		</template>
	</main>
</template>

<script setup>
import { computed, inject, onMounted, reactive, ref, watch } from 'vue';
import {
	CdxButton, CdxDialog, CdxField, CdxIcon, CdxInfoChip, CdxMessage,
	CdxProgressBar, CdxSelect, CdxTable, CdxTextArea, CdxTextInput
} from '@wikimedia/codex';
import { cdxIconAdd } from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import StatusChip from '../components/StatusChip.vue';
import OpenInvestigationDialog from '../components/OpenInvestigationDialog.vue';
import IssueActionDialog from '../components/IssueActionDialog.vue';
import { api } from '../lib/api.js';
import { date, dateTime, LEGAL_BASES } from '../lib/format.js';
import { session } from '../lib/session.js';

const props = defineProps( { id: { type: [ String, Number ], required: true } } );
const notify = inject( 'notify' );

const item = ref( null );
const loading = ref( false );
const error = ref( null );
const notes = ref( '' );
const savingNotes = ref( false );

const showIssue = ref( false );
const showLift = ref( false );
const showRemoval = ref( false );

const erasing = ref( false );
const showOpenFile = ref( false );
const lifting = ref( null );
const liftReason = ref( '' );

const basisOptions = LEGAL_BASES;

const removal = reactive( {
	legal_basis: 'gdpr-17',
	reason: '',
	case_reference: ''
} );

const liveFiles = computed( () => ( item.value?.investigations ?? [] ).filter( ( f ) => f.live ) );

const caseColumns = [
	{ id: 'reference', label: 'Reference', width: '9rem' },
	{ id: 'subject', label: 'About' },
	{ id: 'status', label: 'Status', width: '10rem' },
	{ id: 'filed', label: 'Filed', width: '8rem' }
];

async function load() {
	loading.value = true;
	error.value = null;
	try {
		item.value = await api.subject( props.id );
		notes.value = item.value.notes ?? '';
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

async function saveNotes() {
	savingNotes.value = true;
	try {
		await api.updateSubject( props.id, { notes: notes.value } );
		notify( 'Notes saved.' );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		savingNotes.value = false;
	}
}

function startIssue() {
	showIssue.value = true;
}

async function submitRemoval() {
	if ( erasing.value ) {
		return;
	}

	erasing.value = true;

	try {
		const response = await api.requestRemoval( props.id, {
			legal_basis: removal.legal_basis,
			reason: removal.reason,
			case_reference: removal.case_reference || null
		} );

		showRemoval.value = false;
		removal.reason = '';
		notify( `${ response.reference } — ${ response.message }` );
		await load();
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		erasing.value = false;
	}
}

function onFileOpened() {
	load().then( () => {
		showIssue.value = true;
	} );
}

async function onIssued() {
	await load();
}

function startLift( sanction ) {
	lifting.value = sanction;
	liftReason.value = '';
	showLift.value = true;
}

async function submitLift() {
	try {
		await api.liftSanction( lifting.value.id, { reason: liftReason.value } );
		showLift.value = false;
		notify( 'Lifted.' );
		await load();
	} catch ( e ) {
		notify( e.message, 'error' );
	}
}

watch( () => props.id, load );
onMounted( load );
</script>
