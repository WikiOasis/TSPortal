<template>
	<main class="ts-page">
		<LoadError :error="error" :retry="load" />
		<cdx-progress-bar v-if="loading && !item" aria-label="Loading the investigation" />

		<template v-if="item">
			<PageHeader :title="item.title" :subtitle="`Investigation · ${ item.reference }`">
				<template #actions>
					<StatusChip kind="investigation" :value="item.status" />
					<StatusChip kind="priority" :value="item.priority" />

					<cdx-button
						v-if="item.live"
						action="destructive"
						@click="showIssue = true"
					>
						<cdx-icon :icon="cdxIconBlock" size="small" />
						Take an action
					</cdx-button>

					<cdx-button
						v-if="item.live"
						action="destructive"
						:disabled="!item.subjects.length"
						@click="openBulk( 'action' )"
					>
						<cdx-icon :icon="cdxIconUserGroup" size="small" />
						Act on several
					</cdx-button>

					<cdx-button v-if="item.live" action="progressive" @click="showConclude = true">
						<cdx-icon :icon="cdxIconCheck" size="small" />
						Conclude
					</cdx-button>
					<cdx-button v-else action="progressive" @click="reopen">Reopen</cdx-button>
				</template>
			</PageHeader>

			<cdx-message v-if="!item.live" type="notice" :allow-user-dismiss="false">
				This file was {{ item.status === 'concluded' ? 'concluded' : 'closed' }}
				{{ ago( item.closed ) }}<template v-if="item.closed_by"> by {{ item.closed_by }}</template>.
				Actions cannot be taken under it until it is reopened.
			</cdx-message>

			<div class="ts-split">
				<div>
					<section class="ts-section">
						<h2 class="ts-section__title">Why this was opened</h2>
						<div class="ts-panel">
							<p v-if="item.premise" class="ts-prose">{{ item.premise }}</p>
							<p v-else class="ts-meta">No premise was written down.</p>
						</div>
					</section>

					<section v-if="item.findings" class="ts-section">
						<h2 class="ts-section__title">What was concluded</h2>
						<div class="ts-panel">
							<div class="ts-inline">
								<StatusChip v-if="item.outcome" kind="outcome" :value="item.outcome" />
								<cdx-info-chip :status="item.outcome_disclosable ? 'notice' : 'warning'">
									{{ item.outcome_disclosable ? 'May be told to reporters' : 'Not to be disclosed' }}
								</cdx-info-chip>
							</div>
							<p class="ts-prose">{{ item.findings }}</p>
						</div>
					</section>

					<section class="ts-section">
						<CaseTimeline
							:entries="timeline"
							:loading="timelineLoading"
							heading="The file, in order"
							:show-reporter-view="false"
						/>
					</section>

					<section class="ts-section">
						<h2 class="ts-section__title">Add to the file</h2>
						<div class="ts-panel ts-stack">
							<cdx-field>
								<template #label>What happened, or what you decided</template>
								<cdx-text-area
									v-model="note.body"
									rows="4"
									placeholder="What you found, who you spoke to, what you decided and why."
								/>
							</cdx-field>

							<div class="ts-inline">
								<cdx-select v-model:selected="note.kind" :menu-items="noteKinds" />
								<cdx-button
									action="progressive"
									weight="primary"
									:disabled="!note.body.trim() || savingNote"
									@click="addNote"
								>
									Save to the file
								</cdx-button>
							</div>
						</div>
					</section>

					<section class="ts-section">
						<h2 class="ts-section__title">Reports on this file</h2>
						<div class="ts-panel ts-stack">
							<p v-if="!item.cases.length" class="ts-meta">
								Nothing has been attached.
							</p>

							<div v-for="c in item.cases" :key="c.id" class="ts-row">
								<div>
									<router-link :to="{ name: 'case', params: { id: c.id } }" class="ts-mono">
										{{ c.reference }}
									</router-link>
									<span class="ts-row__title">{{ c.subject }}</span>
									<span v-if="c.anonymous" class="ts-meta"> · filed anonymously</span>
								</div>
								<div class="ts-inline">
									<StatusChip kind="case" :value="c.status" />
									<cdx-button
										weight="quiet"
										size="small"
										aria-label="Take this report off the file"
										@click="detach( c )"
									>
										<cdx-icon :icon="cdxIconClose" size="small" />
									</cdx-button>
								</div>
							</div>

							<cdx-field>
								<template #label>Attach another report</template>
								<template #description>By its reference. It moves to “being looked into”.</template>
								<div class="ts-inline">
									<cdx-text-input v-model="attachRef" placeholder="TS-2026-0481" />
									<cdx-button :disabled="!attachRef.trim() || attaching" @click="attach">
										Attach
									</cdx-button>
								</div>
							</cdx-field>
						</div>
					</section>

					<section class="ts-section">
						<h2 class="ts-section__title">Actions taken under this file</h2>
						<div class="ts-panel ts-stack">
							<p v-if="!item.sanctions.length" class="ts-meta">
								Nothing has been done yet.
							</p>

							<div v-if="item.live" class="ts-inline">
								<cdx-button action="destructive" @click="showIssue = true">
									Take an action
								</cdx-button>
								<cdx-button
									action="destructive"
									:disabled="!item.subjects.length"
									@click="openBulk( 'action' )"
								>
									<cdx-icon :icon="cdxIconUserGroup" size="small" />
									Act on several at once
								</cdx-button>
							</div>

							<div v-for="s in item.sanctions" :key="s.id" class="ts-row">
								<div>
									<span class="ts-mono">{{ s.reference }}</span>
									<span class="ts-row__title">{{ s.label }}</span>
									<span v-if="s.subject_id" class="ts-meta">
										· <router-link :to="{ name: 'subject', params: { id: s.subject_id } }">
											{{ s.account }}
										</router-link>
									</span>
									<div v-if="s.wikis && s.wikis.length" class="ts-meta">
										{{ s.wikis.join( ', ' ) }}
									</div>
								</div>
								<div class="ts-inline">
									<cdx-info-chip :status="s.active ? 'error' : 'notice'">
										{{ s.active ? 'In force' : 'Not in force' }}
									</cdx-info-chip>
									<StatusChip kind="push" :value="s.push_state" />
								</div>
							</div>

							<cdx-message
								v-for="s in unenforced"
								:key="`warn-${ s.id }`"
								:type="s.push_state === 'manual' ? 'warning' : 'error'"
								:inline="true"
								:allow-user-dismiss="false"
							>
								<span class="ts-mono">{{ s.reference }}</span>: {{ s.push_error }}
							</cdx-message>
						</div>
					</section>

					<section class="ts-section">
						<h2 class="ts-section__title">Erasures</h2>
						<div class="ts-panel ts-stack">
							<p v-if="!item.removals.length" class="ts-meta">
								Nothing has been erased under this file. Erasing an account's personal
								data cannot be undone.
							</p>

							<div v-for="r in item.removals" :key="r.id" class="ts-row">
								<div>
									<router-link :to="{ name: 'data-removals' }" class="ts-mono">{{ r.reference }}</router-link>
									<span class="ts-row__title">{{ r.account }}</span>
								</div>
								<StatusChip kind="removal" :value="r.state" />
							</div>

							<cdx-button
								v-if="item.live"
								action="destructive"
								:disabled="!item.subjects.length"
								@click="openBulk( 'erasure' )"
							>
								<cdx-icon :icon="cdxIconTrash" size="small" />
								Erase accounts on this file
							</cdx-button>
						</div>
					</section>
				</div>

				<aside class="ts-stack">
					<div class="ts-panel ts-stack">
						<h2 class="ts-section__title">Handling</h2>

						<cdx-field>
							<template #label>State</template>
							<cdx-select
								v-model:selected="statusDraft"
								:menu-items="liveStatusOptions"
								:disabled="!item.live"
								@update:selected="changeStatus"
							/>
						</cdx-field>

						<cdx-field>
							<template #label>With</template>
							<cdx-select
								v-model:selected="assigneeDraft"
								:menu-items="assigneeOptions"
								@update:selected="changeAssignee"
							/>
						</cdx-field>

						<cdx-field>
							<template #label>Priority</template>
							<cdx-select
								v-model:selected="priorityDraft"
								:menu-items="priorityOptions"
								@update:selected="changePriority"
							/>
						</cdx-field>

						<cdx-field>
							<template #label>Look again on</template>
							<template #description>
								The only thing that brings a quiet file back.
							</template>
							<cdx-text-input
								v-model="reviewDraft"
								input-type="date"
								@change="changeReview"
							/>
						</cdx-field>

						<dl class="ts-dl">
							<dt>Opened</dt>
							<dd>{{ dateTime( item.opened ) }}<template v-if="item.opened_by"> by {{ item.opened_by }}</template></dd>
							<dt>Last change</dt>
							<dd>{{ ago( item.updated ) }}</dd>
						</dl>
					</div>

					<div class="ts-panel ts-stack">
						<h2 class="ts-section__title">Accounts on the file</h2>

						<p v-if="!item.subjects.length" class="ts-meta">Nobody is named yet.</p>

						<div v-for="s in item.subjects" :key="s.id" class="ts-row">
							<div>
								<router-link :to="{ name: 'subject', params: { id: s.id } }">
									{{ s.username }}
								</router-link>
								<div class="ts-inline">
									<StatusChip kind="role" :value="s.role" />
									<StatusChip kind="standing" :value="s.standing" />
								</div>
								<div v-if="s.unresolved" class="ts-meta">
									No matching account on the farm
								</div>
							</div>
							<cdx-button
								weight="quiet"
								size="small"
								:aria-label="`Take ${ s.username } off the file`"
								@click="removeSubject( s )"
							>
								<cdx-icon :icon="cdxIconClose" size="small" />
							</cdx-button>
						</div>

						<cdx-field>
							<template #label>Name another account</template>
							<template #description>
								It does not have to be an account the portal knows, or an account at all.
							</template>
							<cdx-text-input v-model="newSubject.username" placeholder="Quiet Marlin" />
						</cdx-field>
						<div class="ts-inline">
							<cdx-select v-model:selected="newSubject.role" :menu-items="roleOptions" />
							<cdx-button
								:disabled="!newSubject.username.trim() || addingSubject"
								@click="addSubject"
							>
								Add
							</cdx-button>
							<cdx-button @click="showAddSubjects = true">
								<cdx-icon :icon="cdxIconUserAdd" size="small" />
								Paste a list
							</cdx-button>
						</div>
					</div>
				</aside>
			</div>

			<IssueActionDialog
				v-model:open="showIssue"
				:accounts="actionable"
				:investigation="item"
				@issued="onIssued"
			/>

			<BulkActionDialog
				v-model:open="showBulk"
				:investigation="item"
				:kind="bulkKind"
				@done="onBulkDone"
			/>

			<AddSubjectsDialog
				v-model:open="showAddSubjects"
				:investigation-id="item.id"
				@added="onSubjectsAdded"
			/>

			<cdx-dialog
				v-model:open="showConclude"
				title="Conclude this investigation"
				:use-close-button="true"
				:primary-action="{ label: 'Conclude and answer the reports', actionType: 'progressive' }"
				:default-action="{ label: 'Cancel' }"
				@primary="conclude"
				@default="showConclude = false"
			>
				<div class="ts-stack">
					<cdx-message type="warning" :allow-user-dismiss="false">
						<template v-if="openCases.length">
							This will close {{ openCases.length }} open report{{ openCases.length === 1 ? '' : 's' }}
							({{ openCases.map( ( c ) => c.reference ).join( ', ' ) }}) and tell whoever filed
							{{ openCases.length === 1 ? 'it' : 'them' }}.
						</template>
						<template v-else>
							There are no open reports on this file, so nobody outside the portal is told
							anything.
						</template>
					</cdx-message>

					<cdx-field>
						<template #label>What was the outcome</template>
						<template #description>
							Decides how the reports close: acting closes them as “action taken”, not
							acting as “closed, no action”.
						</template>
						<cdx-select v-model:selected="conclusion.outcome" :menu-items="outcomeOptions" />
					</cdx-field>

					<cdx-field>
						<template #label>Findings</template>
						<template #description>Case notes. Never leaves the portal, whatever else is set here.</template>
						<cdx-text-area v-model="conclusion.findings" rows="4" />
					</cdx-field>

					<cdx-checkbox v-model="conclusion.disclosable">
						The outcome may be told to the people who filed the reports
					</cdx-checkbox>

					<cdx-field :disabled="!conclusion.disclosable">
						<template #label>What to say to them</template>
						<template #description>
							Written for a reader, and sent as a public reply on every report on this
							file. Nothing is sent unless the box above is ticked.
						</template>
						<cdx-text-area
							v-model="conclusion.message"
							rows="3"
							:disabled="!conclusion.disclosable"
							placeholder="We have looked into this and taken action. Thank you for reporting it."
						/>
					</cdx-field>
				</div>
			</cdx-dialog>
		</template>
	</main>
</template>

<script setup>
import { computed, inject, onMounted, reactive, ref, watch } from 'vue';
import {
	CdxButton, CdxCheckbox, CdxDialog, CdxField, CdxIcon, CdxInfoChip,
	CdxMessage, CdxProgressBar, CdxSelect, CdxTextArea, CdxTextInput
} from '@wikimedia/codex';
import {
	cdxIconBlock, cdxIconCheck, cdxIconClose, cdxIconTrash, cdxIconUserAdd, cdxIconUserGroup
} from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import StatusChip from '../components/StatusChip.vue';
import CaseTimeline from '../components/CaseTimeline.vue';
import IssueActionDialog from '../components/IssueActionDialog.vue';
import BulkActionDialog from '../components/BulkActionDialog.vue';
import AddSubjectsDialog from '../components/AddSubjectsDialog.vue';
import { api } from '../lib/api.js';
import { remember } from '../lib/recents.js';
import {
	ago, dateTime, NOTE_KINDS, OUTCOMES, PRIORITIES, SUBJECT_ROLES
} from '../lib/format.js';

const props = defineProps( { id: { type: [ String, Number ], required: true } } );
const notify = inject( 'notify' );

const item = ref( null );
const timeline = ref( [] );
const timelineLoading = ref( false );
const loading = ref( false );
const error = ref( null );

const statusDraft = ref( null );
const assigneeDraft = ref( null );
const priorityDraft = ref( null );
const reviewDraft = ref( '' );

const note = reactive( { body: '', kind: 'note' } );
const savingNote = ref( false );

const newSubject = reactive( { username: '', role: 'subject' } );
const addingSubject = ref( false );

const attachRef = ref( '' );
const attaching = ref( false );

const showIssue = ref( false );
const showBulk = ref( false );
const bulkKind = ref( 'action' );
const showAddSubjects = ref( false );
const showConclude = ref( false );
const conclusion = reactive( {
	outcome: null,
	findings: '',
	disclosable: false,
	message: ''
} );

const noteKinds = NOTE_KINDS;
const roleOptions = SUBJECT_ROLES;
const priorityOptions = PRIORITIES;
const outcomeOptions = OUTCOMES;

const liveStatusOptions = [
	{ value: 'open', label: 'Open' },
	{ value: 'monitoring', label: 'Monitoring' }
];

const team = ref( [] );

const assigneeOptions = computed( () => [
	{ value: null, label: 'Nobody' },
	...team.value.map( ( u ) => ( { value: u.id, label: u.username } ) )
] );

const openCases = computed( () => (
	( item.value?.cases ?? [] ).filter( ( c ) => [ 'received', 'in-review', 'investigating' ].includes( c.status ) )
) );

const unenforced = computed( () => (
	( item.value?.sanctions ?? [] ).filter( ( s ) => s.push_error && s.push_state !== 'pushed' )
) );

const actionable = computed( () => item.value?.subjects ?? [] );

async function onIssued() {
	await load();
}

function openBulk( kind ) {
	bulkKind.value = kind;
	showBulk.value = true;
}

async function onBulkDone() {
	await load();
}

function onSubjectsAdded( response ) {
	adopt( response.data.data ?? response.data );
	loadTimeline();
}

function adopt( data ) {
	item.value = data;
	statusDraft.value = data.live ? data.status : 'open';
	assigneeDraft.value = data.assignee?.id ?? null;
	priorityDraft.value = data.priority;
	reviewDraft.value = data.review_at ? data.review_at.slice( 0, 10 ) : '';
	conclusion.outcome ??= data.outcome ?? null;
	conclusion.findings = conclusion.findings || data.findings || '';
}

async function load() {
	loading.value = true;
	error.value = null;
	try {
		const response = await api.investigation( props.id );
		adopt( response.data );
		remember( {
			kind: 'investigation',
			kind_label: 'Investigation',
			id: response.data.id,
			title: response.data.title,
			reference: response.data.reference,
			route: 'investigation',
			status: response.data.status,
			status_of: 'investigation'
		} );
		loadTimeline();
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

async function loadTimeline() {
	timelineLoading.value = true;
	try {
		const response = await api.investigationTimeline( props.id );
		timeline.value = response.data;
	} catch ( e ) {
		timeline.value = [];
	} finally {
		timelineLoading.value = false;
	}
}

async function loadTeam() {
	try {
		const response = await api.staff();
		team.value = response.data.filter( ( u ) => u.active && u.flags.includes( 'ts' ) );
	} catch ( e ) {
		team.value = [];
	}
}

function revert() {
	adopt( item.value );
}

async function patch( body, said ) {
	try {
		const response = await api.updateInvestigation( props.id, body );
		adopt( response.data );
		loadTimeline();
		notify( said );
	} catch ( e ) {
		revert();
		notify( e.message, 'error' );
	}
}

const changeStatus = ( value ) => patch( { status: value }, 'State changed.' );
const changePriority = ( value ) => patch( { priority: value }, 'Priority changed.' );
const changeAssignee = ( value ) => patch( { assigned_to: value }, 'Reassigned.' );
const changeReview = () => patch( { review_at: reviewDraft.value || null }, 'Review date set.' );

async function addNote() {
	savingNote.value = true;
	try {
		await api.addInvestigationNote( props.id, { body: note.body, kind: note.kind } );
		note.body = '';
		await load();
		notify( 'Saved to the file.' );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		savingNote.value = false;
	}
}

async function addSubject() {
	addingSubject.value = true;
	try {
		const response = await api.addInvestigationSubject( props.id, {
			username: newSubject.username,
			role: newSubject.role
		} );
		adopt( response.data );
		newSubject.username = '';
		loadTimeline();
		notify( 'Added to the file.' );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		addingSubject.value = false;
	}
}

async function removeSubject( subject ) {
	try {
		const response = await api.removeInvestigationSubject( props.id, subject.id );
		adopt( response.data );
		loadTimeline();
		notify( `${ subject.username } taken off the file.` );
	} catch ( e ) {
		notify( e.message, 'error' );
	}
}

async function attach() {
	attaching.value = true;
	try {
		const found = await api.object( attachRef.value.trim().toUpperCase() );

		if ( found.type !== 'SafetyCase' ) {
			notify( `${ found.reference } is a ${ found.type_label.toLowerCase() }, not a report.`, 'error' );
			return;
		}

		const response = await api.attachCase( props.id, { case_id: found.id } );
		adopt( response.data );
		attachRef.value = '';
		loadTimeline();
		notify( `${ found.reference } attached. Whoever filed it now sees “being looked into”.` );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		attaching.value = false;
	}
}

async function detach( c ) {
	try {
		const response = await api.detachCase( props.id, c.id );
		adopt( response.data );
		loadTimeline();
		notify( `${ c.reference } taken off the file.` );
	} catch ( e ) {
		notify( e.message, 'error' );
	}
}

async function conclude() {
	try {
		const response = await api.concludeInvestigation( props.id, {
			outcome: conclusion.outcome,
			findings: conclusion.findings || null,
			disclosable: conclusion.disclosable,
			message: conclusion.message || null
		} );
		adopt( response.data );
		showConclude.value = false;
		loadTimeline();
		notify( 'Concluded, and the reports on it have been answered.' );
	} catch ( e ) {
		notify( e.message, 'error' );
	}
}

async function reopen() {
	try {
		const response = await api.reopenInvestigation( props.id );
		adopt( response.data );
		loadTimeline();
		notify( 'Reopened.' );
	} catch ( e ) {
		notify( e.message, 'error' );
	}
}

watch( () => props.id, load );
onMounted( () => {
	load();
	loadTeam();
} );
</script>
