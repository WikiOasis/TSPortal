<template>
	<main class="ts-page">
		<LoadError :error="error" :retry="load" />
		<cdx-progress-bar v-if="loading && !item" aria-label="Loading the case" />

		<template v-if="item">
			<PageHeader :title="item.subject" :subtitle="`${ TYPE_LABELS[ item.type ] ?? item.type } · ${ item.reference }`">
				<template #actions>
					<cdx-button
						v-if="canConcludeAppeal"
						action="progressive"
						weight="primary"
						@click="showConcludeAppeal = true"
					>
						<cdx-icon :icon="cdxIconCheck" size="small" />
						Conclude this appeal
					</cdx-button>
					<cdx-button
						v-else-if="canOpenInvestigation"
						action="progressive"
						weight="primary"
						@click="showOpenFile = true"
					>
						<cdx-icon :icon="cdxIconAdd" size="small" />
						Open an investigation
					</cdx-button>

					<cdx-menu-button
						v-if="moreMenu.length"
						v-model:selected="moreChoice"
						:menu-items="moreMenu"
						aria-label="More for this case"
						@update:selected="onMore"
					>
						<cdx-icon :icon="cdxIconEllipsis" />
					</cdx-menu-button>
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

			<cdx-message v-if="item.sync.error" type="warning" :allow-user-dismiss="false">
				The wiki has not been able to make an update to this case:
				{{ item.sync.error }}
			</cdx-message>

			<div class="ts-split">
				<div>
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

					<AppealPanel
						v-if="item.appeal"
						:case-id="item.id"
						:appeal="item.appeal"
						@changed="load"
					/>

					<section class="ts-section">
						<h2 class="ts-section__title">{{ item.appeal ? 'What they said' : 'What was said' }}</h2>
						<div class="ts-panel ts-stack">
							<p v-if="item.summary" class="ts-summary">{{ item.summary }}</p>
							<p v-else class="ts-meta">No summary was given.</p>

							<div v-if="item.about.length" class="ts-inline">
								<span class="ts-meta">About</span>
								<cdx-info-chip v-for="name in item.about" :key="name">{{ name }}</cdx-info-chip>
							</div>

							<div v-if="( item.pages ?? [] ).length" class="ts-inline">
								<span class="ts-meta">Pages</span>
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

							<div class="ts-case-said__foot">
								<div class="ts-inline">
									<cdx-info-chip
										v-for="category in ( item.categories ?? [] )"
										:key="category.id"
									>
										{{ category.label }}
									</cdx-info-chip>
									<cdx-button weight="quiet" size="small" @click="editingCategories = true">
										<cdx-icon v-if="!( item.categories ?? [] ).length" :icon="cdxIconTag" size="small" />
										{{ ( item.categories ?? [] ).length ? 'Change' : 'Add a category' }}
									</cdx-button>
								</div>

								<details v-if="extraAnswers.length" class="ts-details ts-case-said__answers">
									<summary>Everything they submitted</summary>
									<dl class="ts-dl">
										<template v-for="[ key, value ] in extraAnswers" :key="key">
											<dt>{{ key }}</dt>
											<dd>{{ renderAnswer( value ) }}</dd>
										</template>
									</dl>
								</details>
							</div>
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

					<DataRequestPanel
						v-if="item.data_request"
						:case-id="item.id"
						:request="item.data_request"
						:account="item.reporter ? item.reporter.username : null"
						@changed="load"
					/>

					<section class="ts-section">
						<ActivityFeed
							kind="case"
							:entries="timeline"
							:loading="timelineLoading"
							:replyable="!item.duplicate_of"
							:item="item"
							@changed="onTransacted"
						/>
					</section>

					<section v-if="!item.duplicate_of" class="ts-section">
						<ActivityComposer
							:key="item.id"
							kind="case"
							:item="item"
							:team="team"
							@done="onTransacted"
						/>
					</section>
				</div>

				<aside class="ts-stack ts-case-aside">
					<div class="ts-panel">
						<h2 class="ts-section__title">Handling</h2>
						<dl class="ts-dl">
							<dt>Status</dt>
							<dd><StatusChip kind="case" :value="item.status" /></dd>
							<dt>With</dt>
							<dd class="ts-inline">
								<span>{{ item.assignee ? item.assignee.username : 'Nobody' }}</span>
								<cdx-button
									v-if="!item.assignee || item.assignee.id !== session.user.id"
									weight="quiet"
									action="progressive"
									size="small"
									@click="claim"
								>
									Take this
								</cdx-button>
							</dd>
							<dt>Priority</dt>
							<dd><StatusChip kind="priority" :value="item.priority" /></dd>
							<template v-if="item.investigation">
								<dt>Investigation</dt>
								<dd>
									<div class="ts-inline">
										<router-link
											:to="{ name: 'investigation', params: { id: item.investigation.id } }"
											class="ts-mono"
										>
											{{ item.investigation.reference }}
										</router-link>
										<StatusChip kind="investigation" :value="item.investigation.status" />
									</div>
									<div class="ts-meta">
										{{ item.investigation.title }}<template v-if="item.investigation.live"> · closes this with it</template>
									</div>
								</dd>
							</template>
							<template v-if="item.duplicates && item.duplicates.length">
								<dt>Merged in</dt>
								<dd>
									<template v-for="( d, i ) in item.duplicates" :key="d.id">
										<template v-if="i">, </template>
										<router-link :to="{ name: 'case', params: { id: d.id } }" class="ts-mono">
											{{ d.reference }}
										</router-link>
									</template>
								</dd>
							</template>
							<dt>Filed</dt>
							<dd>{{ dateTime( item.filed ) }}</dd>
							<dt>Wiki</dt>
							<dd>{{ item.wiki || 'not recorded' }}</dd>
						</dl>
						<p class="ts-meta ts-case-aside__foot">
							Updated {{ ago( item.updated ) }} ·
							{{ item.sync.at ? `mirrored ${ ago( item.sync.at ) }` : 'not mirrored yet' }}
						</p>
					</div>

					<div v-if="accounts.length || ( item.anonymous && !item.automated )" class="ts-panel">
						<h2 class="ts-section__title">Accounts</h2>
						<ul class="ts-accounts">
							<li v-for="account in accounts" :key="account.id" class="ts-accounts__row">
								<router-link
									:to="{ name: 'subject', params: { id: account.id } }"
									class="ts-accounts__name"
								>
									{{ account.username }}
								</router-link>
								<span class="ts-accounts__chips">
									<StatusChip kind="role" :value="account.role" />
									<StatusChip kind="standing" :value="account.standing" />
								</span>
							</li>
							<li v-if="item.anonymous && !item.automated" class="ts-accounts__row">
								<span class="ts-accounts__name ts-meta">Anonymous reporter</span>
								<span class="ts-meta">replies will not reach them</span>
							</li>
						</ul>
					</div>

					<div v-if="item.sanctions.length" class="ts-panel">
						<h2 class="ts-section__title">Came out of this</h2>
						<ul class="ts-accounts">
							<li v-for="s in item.sanctions" :key="s.id" class="ts-accounts__row">
								<router-link :to="{ name: 'item', params: { reference: s.reference } }" class="ts-accounts__name">
									<span class="ts-mono">{{ s.reference }}</span> {{ s.label }}
								</router-link>
								<StatusChip kind="force" :value="s.active ? 'in-force' : 'lifted'" />
							</li>
						</ul>
					</div>
				</aside>
			</div>
			<ConcludeAppealDialog
				v-if="item.appeal"
				v-model:open="showConcludeAppeal"
				:case-id="item.id"
				:appeal="item.appeal"
				:anonymous="item.anonymous"
				@concluded="load"
			/>

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
	CdxTextArea
} from '@wikimedia/codex';
import {
	cdxIconAdd, cdxIconCheck, cdxIconCopy, cdxIconEllipsis, cdxIconRobot, cdxIconSearch, cdxIconTag
} from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import StatusChip from '../components/StatusChip.vue';
import ActivityFeed from '../components/ActivityFeed.vue';
import ActivityComposer from '../components/ActivityComposer.vue';
import DataRequestPanel from '../components/DataRequestPanel.vue';
import AppealPanel from '../components/AppealPanel.vue';
import ConcludeAppealDialog from '../components/ConcludeAppealDialog.vue';
import OpenInvestigationDialog from '../components/OpenInvestigationDialog.vue';
import MarkDuplicateDialog from '../components/MarkDuplicateDialog.vue';
import AutoReviewDetail from '../components/AutoReviewDetail.vue';
import { api } from '../lib/api.js';
import { BUCKETS } from '../lib/autoreview.js';
import { remember } from '../lib/recents.js';
import { ago, dateTime, fileSize, TYPE_LABELS } from '../lib/format.js';
import { session } from '../lib/session.js';

const props = defineProps( { id: { type: [ String, Number ], required: true } } );
const notify = inject( 'notify' );

const router = useRouter();

const item = ref( null );
const timeline = ref( [] );
const timelineLoading = ref( false );
const loading = ref( false );
const error = ref( null );
const showOpenFile = ref( false );
const showDuplicate = ref( false );
const showConcludeAppeal = ref( false );
const moreChoice = ref( null );
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

const canConcludeAppeal = computed( () => !!item.value?.appeal && !item.value.appeal.outcome && !item.value.duplicate_of );
const canOpenInvestigation = computed( () => !!item.value && !item.value.investigation && !item.value.duplicate_of );

const moreMenu = computed( () => {
	const data = item.value;
	if ( !data ) {
		return [];
	}
	const items = [];
	if ( canConcludeAppeal.value && canOpenInvestigation.value ) {
		items.push( { value: 'investigate', label: 'Open an investigation', icon: cdxIconAdd } );
	}
	if ( data.investigation ) {
		items.push( { value: 'investigation', label: `Go to ${ data.investigation.reference }`, icon: cdxIconSearch } );
	}
	if ( !data.duplicate_of ) {
		items.push( { value: 'duplicate', label: 'Mark as a duplicate…', icon: cdxIconCopy } );
	}
	items.push( { value: 'categories', label: 'Change categories…', icon: cdxIconTag } );
	return items;
} );

function onMore( value ) {
	moreChoice.value = null;
	if ( value === 'investigate' ) {
		showOpenFile.value = true;
	} else if ( value === 'investigation' ) {
		router.push( { name: 'investigation', params: { id: item.value.investigation.id } } );
	} else if ( value === 'duplicate' ) {
		showDuplicate.value = true;
	} else if ( value === 'categories' ) {
		editingCategories.value = true;
	}
}

const accounts = computed( () => {
	const data = item.value;
	if ( !data ) {
		return [];
	}
	const rows = [];
	if ( data.reporter ) {
		rows.push( {
			id: data.reporter.id,
			username: data.reporter.username,
			standing: data.reporter.standing,
			role: data.appeal ? 'appellant' : 'reporter'
		} );
	}
	for ( const s of data.subjects ?? [] ) {
		if ( !rows.some( ( r ) => r.id === s.id ) ) {
			rows.push( { id: s.id, username: s.username, standing: s.standing, role: s.role || 'subject' } );
		}
	}
	return rows;
} );

function squash( value ) {
	return String( value ?? '' ).replace( /\s+/g, ' ' ).trim();
}

const extraAnswers = computed( () => {
	const summary = squash( item.value?.summary );
	return Object.entries( item.value?.answers ?? {} )
		.filter( ( [ , value ] ) => !( typeof value === 'string' && summary && squash( value ) === summary ) );
} );

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

function onTransacted( data ) {
	adopt( data );
	loadTimeline();
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

watch( () => props.id, load );
onMounted( () => {
	load();
	loadTeam();
} );
</script>
