<template>
	<main class="ts-page">
		<LoadError :error="error" :retry="load" />
		<cdx-progress-bar v-if="loading && !item" aria-label="Loading the case" />

		<template v-if="item">
			<PageHeader :title="item.subject" :subtitle="`${ TYPE_LABELS[ item.type ] ?? item.type } · ${ item.reference }`">
				<template #actions>
					<StatusChip kind="case" :value="item.status" />
					<StatusChip kind="priority" :value="item.priority" />

					<cdx-button
						v-if="!item.assignee || item.assignee.id !== session.user.id"
						action="progressive"
						@click="claim"
					>
						Take this
					</cdx-button>

					<cdx-button
						v-if="!item.duplicate_of"
						@click="showDuplicate = true"
					>
						<cdx-icon :icon="cdxIconCopy" size="small" />
						Duplicate of…
					</cdx-button>

					<cdx-button
						v-if="!item.investigation"
						action="progressive"
						weight="primary"
						@click="showOpenFile = true"
					>
						<cdx-icon :icon="cdxIconAdd" size="small" />
						Open an investigation
					</cdx-button>
					<cdx-button
						v-else
						@click="$router.push( { name: 'investigation', params: { id: item.investigation.id } } )"
					>
						Go to {{ item.investigation.reference }}
					</cdx-button>
				</template>
			</PageHeader>

			<cdx-message
				v-if="item.threat_to_life"
				type="error"
				:allow-user-dismiss="false"
			>
				<strong>This report indicates a threat to life.</strong>
				Please validate this reported threat to life, and contact emergency services
                as appropriate with internal procedure.
			</cdx-message>

			<section v-if="item.automated && autoReviewRow" class="ts-section ts-ar-case">
				<div class="ts-ar-case__head">
					<h2 class="ts-section__title">
						<cdx-icon :icon="cdxIconRobot" size="small" />
						Raised by Jev, not by a person
					</h2>
					<span class="ts-inline">
						<router-link
							v-if="item.status !== 'closed' && item.status !== 'rejected' && item.status !== 'duplicate'"
							:to="{ name: 'automation', query: { case: item.id } }"
						>
							Review in Automation
						</router-link>
						<cdx-menu-button
							v-model:selected="bucketChoice"
							:menu-items="bucketMenu"
							:disabled="reviewBusy"
							aria-label="Change the AI's bucket"
							@update:selected="onBucketMenu"
						>
							Change bucket
						</cdx-menu-button>
					</span>
				</div>
				<div class="ts-panel">
					<AutoReviewDetail :row="autoReviewRow" :show-header="false" />
				</div>
			</section>

			<cdx-message v-if="item.duplicate_of" type="notice" :allow-user-dismiss="false">
				<p>
					Closed as a duplicate of
					<router-link
						:to="{ name: 'case', params: { id: item.duplicate_of.id } }"
						class="ts-mono"
					>
						{{ item.duplicate_of.reference }}
					</router-link>
					— {{ item.duplicate_of.subject }}.
					<template v-if="item.duplicate_of.marked_by">
						Merged by {{ item.duplicate_of.marked_by }}, {{ ago( item.duplicate_of.marked_at ) }}.
					</template>
					Answer it there.
				</p>
				<p v-if="item.duplicate_of.note" class="ts-meta">{{ item.duplicate_of.note }}</p>
				<cdx-button :disabled="unmerging" @click="unmerge">
					{{ unmerging ? 'Undoing…' : 'Not a duplicate — reopen it' }}
				</cdx-button>
			</cdx-message>

			<cdx-message
				v-if="item.duplicates && item.duplicates.length"
				type="notice"
				:allow-user-dismiss="false"
			>
				{{ item.duplicates.length }} other report{{ item.duplicates.length === 1 ? ' was' : 's were' }}
				merged into this one:
				<template v-for="( d, i ) in item.duplicates" :key="d.id">
					<template v-if="i">, </template>
					<router-link :to="{ name: 'case', params: { id: d.id } }" class="ts-mono">
						{{ d.reference }}
					</router-link>
				</template>.
				Answering this one answers them all.
			</cdx-message>

			<cdx-message v-if="item.anonymous && !item.automated" type="notice" :allow-user-dismiss="true">
				This was filed anonymously. Comments will not reach the reporter.
			</cdx-message>

			<cdx-message v-if="item.sync.error" type="warning" :allow-user-dismiss="false">
				The wiki has not been able to make an update to this case:
				{{ item.sync.error }}
			</cdx-message>

			<cdx-message v-if="item.investigation" type="notice" :allow-user-dismiss="false">
				Being answered as part of
				<router-link :to="{ name: 'investigation', params: { id: item.investigation.id } }" class="ts-mono">
					{{ item.investigation.reference }}
				</router-link>
				— {{ item.investigation.title }}.
				<template v-if="item.investigation.live">
					It will be closed when that investigation is. The reporter is told only that it is being
					investigated into.
				</template>
				<template v-else>
					That investigation is closed, so this can be answered on its own.
				</template>
			</cdx-message>

			<div class="ts-split">
				<div>
					<section class="ts-section">
						<h2 class="ts-section__title">What was said</h2>
						<div class="ts-panel">
							<p v-if="item.summary" class="ts-summary">{{ item.summary }}</p>
							<p v-else class="ts-meta">No summary was given.</p>

							<div v-if="item.about.length" class="ts-inline">
								<span class="ts-meta">About:</span>
								<cdx-info-chip v-for="name in item.about" :key="name">{{ name }}</cdx-info-chip>
							</div>

							<div v-if="( item.pages ?? [] ).length" class="ts-inline ts-case-pages">
								<span class="ts-meta">Pages:</span>
								<cdx-info-chip
									v-for="page in item.pages"
									:key="`${ page.wiki }|${ page.title }`"
									:status="page.deleted ? 'success' : 'notice'"
									:title="page.deleted ? `Deleted under ${ page.deleted.reference }` : undefined"
								>
									{{ page.title }} <span class="ts-mono">({{ page.wiki }})</span>
									<template v-if="page.deleted"> · deleted</template>
								</cdx-info-chip>
								<router-link
									v-if="item.investigation"
									:to="{ name: 'investigation', params: { id: item.investigation.id }, hash: '#pages' }"
								>
									Pages on {{ item.investigation.reference }}
								</router-link>
								<span v-else class="ts-meta">Open an investigation to act on these pages.</span>
							</div>

							<div class="ts-inline" style="margin-top: 0.75rem;">
								<span class="ts-meta">Filed under:</span>
								<cdx-info-chip
									v-for="category in ( item.categories ?? [] )"
									:key="category.id"
									:status="category.primary ? 'notice' : 'notice'"
								>
									{{ category.label }}
								</cdx-info-chip>
								<span v-if="!( item.categories ?? [] ).length" class="ts-meta">
									nothing — this wiki's form did not say, so nobody can count it
								</span>
								<cdx-button weight="quiet" size="small" @click="editingCategories = true">
									Change
								</cdx-button>
							</div>
						</div>
					</section>

					<section v-if="hasAnswers" class="ts-section">
						<h2 class="ts-section__title">The submission</h2>
						<div class="ts-panel">
							<dl class="ts-dl">
								<template v-for="( value, key ) in item.answers" :key="key">
									<dt>{{ key }}</dt>
									<dd>{{ renderAnswer( value ) }}</dd>
								</template>
							</dl>
						</div>
					</section>

					<section v-if="item.attachments.length" class="ts-section">
						<h2 class="ts-section__title">Attachments</h2>
						<div class="ts-panel ts-stack">
							<div v-for="file in item.attachments" :key="file.id">
								<a v-if="file.state === 'stored'" :href="file.url" target="_blank" rel="noopener">
									{{ file.name }}
								</a>
								<strong v-else>{{ file.name }}</strong>
								<span class="ts-meta">
									· {{ file.mime || 'unknown type' }}<template v-if="file.size"> · {{ fileSize( file.size ) }}</template>
								</span>

								<div v-if="file.state === 'pending'" class="ts-meta">
									Named by the reporter; the file itself has not arrived yet.
								</div>
								<div v-else-if="file.state === 'refused'" class="ts-meta">
									Not stored: {{ file.reason || 'refused' }} — ask them to send it another way.
								</div>
							</div>
						</div>
					</section>

					<AppealPanel
						v-if="item.appeal"
						:case-id="item.id"
						:appeal="item.appeal"
						@changed="load"
					/>

					<DataRequestPanel
						v-if="item.data_request"
						:case-id="item.id"
						:request="item.data_request"
						:account="item.reporter ? item.reporter.username : null"
						@changed="load"
					/>

					<section class="ts-section">
						<CaseTimeline
							:entries="timeline"
							:loading="timelineLoading"
							heading="What happened, in order"
						/>
					</section>

					<section class="ts-section">
						<h2 class="ts-section__title">Conversation</h2>

						<div class="ts-panel ts-stack">
							<p v-if="!item.comments.length" class="ts-meta">Nothing has been said yet.</p>

							<div
								v-for="comment in item.comments"
								:key="comment.id"
								class="ts-comment"
								:class="`ts-comment--${ comment.visibility }`"
							>
								<div class="ts-comment__head">
									<span class="ts-comment__author">{{ comment.author || 'System' }}</span>
									<cdx-info-chip :status="comment.visibility === 'internal' ? 'warning' : 'notice'">
										{{ comment.visibility === 'internal' ? 'Internal note' : 'Seen by the reporter' }}
									</cdx-info-chip>
									<span class="ts-meta">{{ dateTime( comment.date ) }}</span>
								</div>
								<div class="ts-comment__body">{{ comment.body }}</div>
							</div>
						</div>

						<div class="ts-panel ts-stack" style="margin-top: 1rem;">
							<cdx-field>
								<template #label>Add to the case</template>
								<cdx-text-area
									v-model="draft"
									placeholder="What you want to say, or what you want the case to remember."
									rows="4"
								/>
							</cdx-field>

							<div class="ts-inline">
								<cdx-button
									:disabled="!draft.trim() || saving"
									@click="postComment( 'internal' )"
								>
									Save as internal note
								</cdx-button>
								<cdx-button
									action="progressive"
									weight="primary"
									:disabled="!draft.trim() || saving || item.anonymous"
									@click="postComment( 'public' )"
								>
									Reply to the reporter
								</cdx-button>
							</div>
							<p class="ts-meta">
								A reply is mirrored to the farm, shows on the reporter's
								Special:SafetyHome, and notifies them. An internal note never
								leaves this portal.
							</p>
						</div>
					</section>
				</div>

				<aside class="ts-stack">
					<div class="ts-panel">
						<h2 class="ts-section__title">Handling</h2>
						<div class="ts-stack">
							<cdx-field>
								<template #label>Status</template>
								<cdx-select
									v-model:selected="statusDraft"
									:menu-items="statusOptions"
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

							<dl class="ts-dl">
								<dt>Filed</dt>
								<dd>{{ dateTime( item.filed ) }}</dd>
								<dt>Last change</dt>
								<dd>{{ ago( item.updated ) }}</dd>
								<dt>Wiki</dt>
								<dd>{{ item.wiki || 'not recorded' }}</dd>
								<dt>Mirrored</dt>
								<dd>{{ item.sync.at ? dateTime( item.sync.at ) : 'not yet' }}</dd>
							</dl>
						</div>
					</div>

					<div v-if="item.reporter" class="ts-panel">
						<h2 class="ts-section__title">Filed by</h2>
						<p>
							<router-link :to="{ name: 'subject', params: { id: item.reporter.id } }">
								{{ item.reporter.username }}
							</router-link>
						</p>
						<StatusChip kind="standing" :value="item.reporter.standing" />
					</div>

					<div v-if="item.subjects.length" class="ts-panel">
						<h2 class="ts-section__title">About these accounts</h2>
						<div class="ts-stack">
							<div v-for="subject in item.subjects" :key="subject.id" class="ts-inline">
								<router-link :to="{ name: 'subject', params: { id: subject.id } }">
									{{ subject.username }}
								</router-link>
								<StatusChip kind="standing" :value="subject.standing" />
							</div>
						</div>
					</div>

					<div v-if="item.sanctions.length" class="ts-panel">
						<h2 class="ts-section__title">Came out of this</h2>
						<ul>
							<li v-for="s in item.sanctions" :key="s.id">
								<span class="ts-mono">{{ s.reference }}</span> — {{ s.label }}
								<span v-if="!s.active" class="ts-meta">(no longer in force)</span>
							</li>
						</ul>
					</div>
				</aside>
			</div>
			<OpenInvestigationDialog
				v-model:open="showOpenFile"
				:from-case="item"
				:subjects="fileSubjects"
				@opened="onFileOpened"
			/>

			<MarkDuplicateDialog
				v-model:open="showDuplicate"
				:report="item"
				@merged="onMerged"
			/>
		</template>
		<cdx-dialog
			v-model:open="editingCategories"
			title="What is this about?"
			:use-close-button="true"
			:primary-action="{ label: savingCategories ? 'Saving…' : 'Save', actionType: 'progressive', disabled: savingCategories }"
			:default-action="{ label: 'Cancel' }"
			@primary="saveCategories"
			@default="editingCategories = false"
		>
			<p class="ts-meta">
				These are counted into transparency reports, so they are the words the report
				will use. The first is the main one. Removing them all is allowed and means
				nobody has said.
			</p>

			<cdx-field>
				<template #label>Categories</template>
				<template #description>One per line, as they should read.</template>
				<cdx-text-area v-model="categoryText" rows="5" />
			</cdx-field>
		</cdx-dialog>
	</main>
</template>

<script setup>
import { computed, inject, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import {
	CdxButton, CdxDialog, CdxField, CdxIcon, CdxInfoChip, CdxMenuButton, CdxMessage, CdxProgressBar,
	CdxSelect, CdxTextArea
} from '@wikimedia/codex';
import { cdxIconAdd, cdxIconCopy, cdxIconRobot } from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import StatusChip from '../components/StatusChip.vue';
import CaseTimeline from '../components/CaseTimeline.vue';
import DataRequestPanel from '../components/DataRequestPanel.vue';
import AppealPanel from '../components/AppealPanel.vue';
import OpenInvestigationDialog from '../components/OpenInvestigationDialog.vue';
import MarkDuplicateDialog from '../components/MarkDuplicateDialog.vue';
import AutoReviewDetail from '../components/AutoReviewDetail.vue';
import { api } from '../lib/api.js';
import { BUCKETS } from '../lib/autoreview.js';
import { remember } from '../lib/recents.js';
import { ago, dateTime, fileSize, PRIORITIES, TYPE_LABELS } from '../lib/format.js';
import { session } from '../lib/session.js';

const props = defineProps( { id: { type: [ String, Number ], required: true } } );
const notify = inject( 'notify' );

const router = useRouter();

const item = ref( null );
const timeline = ref( [] );
const timelineLoading = ref( false );
const loading = ref( false );
const error = ref( null );
const draft = ref( '' );
const saving = ref( false );
const statusDraft = ref( null );
const assigneeDraft = ref( null );
const priorityDraft = ref( null );
const showOpenFile = ref( false );
const showDuplicate = ref( false );
const unmerging = ref( false );
const team = ref( [] );
const editingCategories = ref( false );
const savingCategories = ref( false );

const categoryText = ref( '' );
const reviewBusy = ref( false );
const bucketChoice = ref( null );

const bucketMenu = computed( () => [
	...BUCKETS.filter( ( b ) => b.value !== item.value?.autoreview?.bucket ).map( ( b ) => ( { value: b.value, label: `Move to ${ b.label.toLowerCase() }` } ) ),
	{ value: 'again', label: 'Ask the AI again' }
] );

function onBucketMenu( value ) {
	bucketChoice.value = null;
	if ( value === 'again' ) {
		sortAgain();
	} else if ( value ) {
		moveBucket( value );
	}
}

const autoReviewRow = computed( () => {
	const data = item.value;
	if ( !data?.automated ) {
		return null;
	}
	const answers = data.answers ?? {};
	const categories = data.categories ?? [];
	return {
		id: data.id,
		reference: data.reference,
		subject: data.subject,
		filed: data.filed,
		wiki: data.wiki,
		category: ( categories.find( ( c ) => c.primary ) ?? categories[ 0 ] )?.label ?? null,
		edit_summary: typeof answers.edit_summary === 'string' ? answers.edit_summary : null,
		jev: {
			harm: answers.jev?.harm ?? null,
			vandalism: answers.jev?.vandalism ?? null
		},
		review: data.autoreview
			? {
				...data.autoreview,
				links: {
					...data.autoreview.links,
					revision: data.autoreview.links.revision ?? answers.revision_url ?? null
				}
			}
			: null
	};
} );

async function moveBucket( value ) {
	reviewBusy.value = true;
	try {
		await api.autoReviewBucket( props.id, value );
		notify( `Moved to ${ BUCKETS.find( ( b ) => b.value === value )?.label.toLowerCase() }.` );
		await load();
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		reviewBusy.value = false;
	}
}

async function sortAgain() {
	reviewBusy.value = true;
	try {
		const response = await api.autoReviewClassify( { case_ids: [ Number( props.id ) ] } );
		notify( response.queued ? 'Sent to be sorted again. Reload in a minute to see the answer.' : 'This report cannot be sorted again while it is closed.' );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		reviewBusy.value = false;
	}
}

const statusOptions = computed( () => {
	const options = [
		{ value: 'received', label: 'Received' },
		{ value: 'in-review', label: 'Being read' },
		{ value: 'investigating', label: 'Under investigation' },
		{ value: 'action-taken', label: 'Action taken' },
		{ value: 'closed', label: 'Closed' },
		{ value: 'rejected', label: 'Closed, no action' }
	];

	if ( item.value?.status === 'duplicate' ) {
		options.push( { value: 'duplicate', label: 'Duplicate', disabled: true } );
	}

	return options;
} );

const priorityOptions = PRIORITIES;

const assigneeOptions = computed( () => [
	{ value: null, label: 'Nobody' },
	...team.value.map( ( u ) => ( { value: u.id, label: u.username } ) )
] );

const hasAnswers = computed( () => item.value && Object.keys( item.value.answers ?? {} ).length > 0 );

const fileSubjects = computed( () => {
	if ( !item.value ) {
		return [];
	}

	const rows = ( item.value.subjects ?? [] ).map( ( s ) => ( {
		username: s.username,
		role: s.role === 'reporter' ? 'reporter' : 'subject'
	} ) );

	if ( item.value.reporter && !item.value.anonymous ) {
		rows.unshift( { username: item.value.reporter.username, role: 'reporter' } );
	}

	return rows;
} );

function renderAnswer( value ) {
	if ( Array.isArray( value ) ) {
		return value.length ? value.map( renderAnswer ).join( ', ' ) : '—';
	}
	if ( value === null || value === undefined || value === '' ) {
		return '—';
	}
	if ( typeof value === 'boolean' ) {
		return value ? 'yes' : 'no';
	}
	if ( typeof value === 'object' ) {
		if ( typeof value.name === 'string' ) {
			return value.name;
		}
		return JSON.stringify( value );
	}
	return String( value );
}

function adopt( data ) {
	item.value = data;
	statusDraft.value = data.status;
	assigneeDraft.value = data.assignee?.id ?? null;
	priorityDraft.value = data.priority;
	categoryText.value = ( data.categories ?? [] ).map( ( c ) => c.label ).join( '\n' );
}

async function saveCategories() {
	savingCategories.value = true;

	const categories = categoryText.value
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.filter( Boolean )
		.map( ( label ) => ( {
			id: label.toLowerCase().replace( /\s+/g, '-' ).slice( 0, 64 ),
			label
		} ) );

	try {
		const response = await api.categoriseCase( props.id, categories );
		adopt( response.data );
		editingCategories.value = false;
		loadTimeline();
		notify( 'Filed under the new categories.' );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		savingCategories.value = false;
	}
}

async function load() {
	loading.value = true;
	error.value = null;
	try {
		const response = await api.case( props.id );
		adopt( response.data );
		remember( {
			kind: 'case',
			kind_label: 'Report',
			id: response.data.id,
			title: response.data.subject,
			reference: response.data.reference,
			route: 'case',
			status: response.data.status,
			status_of: 'case'
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
		const response = await api.caseTimeline( props.id );
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

async function claim() {
	try {
		const response = await api.claimCase( props.id );
		adopt( response.data );
		loadTimeline();
		notify( 'This is yours now.' );
	} catch ( e ) {
		notify( e.message, 'error' );
	}
}

async function patch( body, said ) {
	try {
		const response = await api.updateCase( props.id, body );
		adopt( response.data );
		loadTimeline();
		notify( said );
	} catch ( e ) {
		adopt( item.value );
		notify( e.message, 'error' );
	}
}

function changeStatus( value ) {
	if ( !item.value || value === item.value.status ) {
		return;
	}
	return patch( { status: value }, 'Status changed. The reporter has been told.' );
}

function changePriority( value ) {
	if ( !item.value || value === item.value.priority ) {
		return;
	}
	return patch( { priority: value }, 'Priority changed.' );
}

function changeAssignee( value ) {
	if ( !item.value || value === ( item.value.assignee?.id ?? null ) ) {
		return;
	}
	return patch( { assigned_to: value }, value ? 'Reassigned.' : 'Unassigned.' );
}

function onFileOpened( investigation ) {
	router.push( {
		name: 'investigation',
		params: { id: investigation.id },
		hash: ( item.value?.pages ?? [] ).length ? '#pages' : ''
	} );
}

function onMerged( response ) {
	adopt( response.case.data ?? response.case );
	loadTimeline();
}

async function unmerge() {
	unmerging.value = true;
	try {
		const response = await api.undoDuplicate( props.id, { status: 'in-review' } );
		adopt( response.case.data ?? response.case );
		loadTimeline();
		notify( 'Taken back out of the merge and reopened.' );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		unmerging.value = false;
	}
}

async function postComment( visibility ) {
	saving.value = true;
	try {
		await api.commentOnCase( props.id, { body: draft.value, visibility } );
		draft.value = '';
		await load();
		notify( visibility === 'public' ? 'Sent to the reporter.' : 'Note saved.' );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		saving.value = false;
	}
}

watch( () => props.id, load );
onMounted( () => {
	load();
	loadTeam();
} );
</script>
