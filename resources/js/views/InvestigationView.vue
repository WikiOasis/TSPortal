<template>
	<main class="ts-page">
		<LoadError :error="error" :retry="load" />
		<cdx-progress-bar v-if="loading && !item" aria-label="Loading the investigation" />

		<template v-if="item">
			<PageHeader :title="item.title" :subtitle="`Investigation · ${ item.reference }`">
				<template #actions>
					<template v-if="item.live">
						<cdx-button action="destructive" @click="showIssue = true">
							<cdx-icon :icon="cdxIconBlock" size="small" />
							Take an action
						</cdx-button>
						<cdx-button action="progressive" weight="primary" @click="showConclude = true">
							<cdx-icon :icon="cdxIconCheck" size="small" />
							Conclude
						</cdx-button>
					</template>
					<cdx-button v-else action="progressive" weight="primary" @click="reopen">Reopen</cdx-button>

					<cdx-menu-button
						v-model:selected="moreChoice"
						:menu-items="moreMenu"
						aria-label="More for this investigation"
						@update:selected="onMore"
					>
						<cdx-icon :icon="cdxIconEllipsis" />
					</cdx-menu-button>
				</template>
			</PageHeader>

			<div class="ts-split">
				<div>
					<section class="ts-section">
						<h2 class="ts-section__title">Why this was opened</h2>
						<div class="ts-panel">
							<RichText v-if="item.premise" class="ts-prose" :text="item.premise" />
							<p v-else class="ts-meta">No premise was written down.</p>
						</div>
					</section>

					<section v-if="item.findings" class="ts-section">
						<h2 class="ts-section__title">What was concluded</h2>
						<div class="ts-panel ts-stack">
							<div v-if="item.outcome" class="ts-inline">
								<StatusChip kind="outcome" :value="item.outcome" />
							</div>
							<RichText class="ts-prose" :text="item.findings" />
						</div>
					</section>

					<section class="ts-section">
						<div class="ts-section-head">
							<h2 class="ts-section__title">Actions taken</h2>
							<cdx-button
								v-if="item.live && item.subjects.length"
								weight="quiet"
								size="small"
								@click="openBulk( 'action' )"
							>
								<cdx-icon :icon="cdxIconUserGroup" size="small" />
								Act on several
							</cdx-button>
						</div>
						<div class="ts-panel">
							<p v-if="!item.sanctions.length" class="ts-meta">
								Nothing has been done yet.
							</p>

							<ul v-else class="ts-accounts">
								<li v-for="s in item.sanctions" :key="s.id" class="ts-accounts__row">
									<div class="ts-accounts__name">
										<div>
											<router-link :to="{ name: 'item', params: { reference: s.reference } }" class="ts-mono">
												{{ s.reference }}
											</router-link>
											{{ s.label }}
											<template v-if="s.subject_id">
												·
												<router-link :to="{ name: 'subject', params: { id: s.subject_id } }">
													{{ s.account }}
												</router-link>
											</template>
										</div>
										<div v-if="s.prompted_by || ( s.pages && s.pages.length ) || ( s.wikis && s.wikis.length )" class="ts-meta">
											<template v-if="s.pages && s.pages.length">{{ s.where }}</template>
											<template v-else-if="s.wikis && s.wikis.length">{{ s.wikis.join( ', ' ) }}</template>
											<template v-if="s.prompted_by"> · for <span class="ts-mono">{{ s.prompted_by }}</span></template>
										</div>
										<div v-if="s.push_error && s.push_state !== 'pushed'" class="ts-accounts__problem">
											<cdx-icon :icon="cdxIconAlert" size="small" />
											{{ s.push_error }}
										</div>
									</div>
									<span class="ts-accounts__chips">
										<StatusChip kind="force" :value="s.active ? 'in-force' : 'lifted'" />
										<StatusChip v-if="s.push_state !== 'pushed'" kind="push" :value="s.push_state" />
									</span>
								</li>
							</ul>
						</div>
					</section>

					<section id="pages" ref="pagesSection" class="ts-section">
						<div class="ts-section-head">
							<h2 class="ts-section__title">Pages</h2>
							<span class="ts-inline">
								<cdx-button
									v-if="pages.length"
									weight="quiet"
									size="small"
									:disabled="refreshingPages"
									@click="refreshPages"
								>
									{{ refreshingPages ? 'Looking up…' : ( pagesPicked.length ? `Look up ${ pagesPicked.length } again` : 'Look up editors again' ) }}
								</cdx-button>
								<cdx-button weight="quiet" size="small" @click="showAddPages = true">
									<cdx-icon :icon="cdxIconAdd" size="small" />
									Add pages
								</cdx-button>
							</span>
						</div>
						<div class="ts-panel ts-stack">
							<p v-if="!pages.length" class="ts-meta">
								None yet. Pages named in the reports land here, and are only deleted from here so their editors are told.
							</p>

							<template v-else>
								<div class="ts-bulk-table__head">
									<span class="ts-meta">
										{{ pages.length }} page{{ pages.length === 1 ? '' : 's' }} ·
										{{ pages.filter( ( p ) => p.deleted ).length }} deleted<template v-if="lookingUp"> ·
											looking up {{ lookingUp }}…</template>
									</span>
									<span class="ts-inline">
										<cdx-button
											weight="quiet"
											size="small"
											:disabled="!livePages.length || !item.live"
											@click="pickAllPages"
										>
											{{ pagesPicked.length === livePages.length && livePages.length ? 'Pick none' : 'Pick all still up' }}
										</cdx-button>
										<cdx-button
											v-if="item.live && pagesPicked.length"
											action="destructive"
											size="small"
											@click="showDeletePages = true"
										>
											<cdx-icon :icon="cdxIconTrash" size="small" />
											Delete {{ pagesPicked.length }} and tell their editors
										</cdx-button>
									</span>
								</div>

								<div class="ts-bulk-table ts-scroll">
									<table>
										<caption class="ts-visually-hidden">Pages on this file</caption>
										<thead>
											<tr>
												<th scope="col" class="ts-bulk-table__pick"><span class="ts-visually-hidden">Picked</span></th>
												<th scope="col">Page</th>
												<th scope="col">Wiki</th>
												<th scope="col">Created by</th>
												<th scope="col">Editors</th>
												<th scope="col">From</th>
												<th scope="col">State</th>
												<th scope="col"><span class="ts-visually-hidden">Remove</span></th>
											</tr>
										</thead>
										<tbody>
											<tr v-for="page in pages" :key="page.id">
												<td data-label="Picked" class="ts-bulk-table__pick">
													<cdx-checkbox
														v-model="pagesPicked"
														:input-value="page.id"
														:disabled="!!page.deleted || !item.live"
													>
														<span class="ts-visually-hidden">{{ page.title }}</span>
													</cdx-checkbox>
												</td>
												<td data-label="Page">
													<strong>{{ page.title }}</strong>
													<div v-if="page.note" class="ts-meta">{{ page.note }}</div>
												</td>
												<td data-label="Wiki" class="ts-mono">{{ page.wiki }}</td>
												<td data-label="Created by">{{ page.creator ?? '—' }}</td>
												<td data-label="Editors">
													<template v-if="page.editors.length">
														{{ page.editors.slice( 0, 4 ).map( ( e ) => e.username ).join( ', ' ) }}
														<span v-if="page.editors.length > 4" class="ts-meta">and {{ page.editors.length - 4 }} more</span>
													</template>
													<span v-else-if="!page.info.fetched && !page.info.error" class="ts-meta">Looking up…</span>
													<span v-else class="ts-meta">—</span>
													<div v-if="page.info.error" class="ts-meta">{{ page.info.error }}</div>
												</td>
												<td data-label="From">
													<template v-for="( c, i ) in page.cases" :key="c.id">
														<template v-if="i">, </template>
														<router-link :to="{ name: 'case', params: { id: c.id } }" class="ts-mono">{{ c.reference }}</router-link>
													</template>
													<span v-if="!page.cases.length" class="ts-meta">Added here</span>
												</td>
												<td data-label="State">
													<template v-if="page.deleted">
														<StatusChip kind="push" :value="page.deleted.push_state" />
														<div class="ts-meta ts-mono">{{ page.deleted.reference }}</div>
													</template>
													<span v-else-if="page.exists === false && page.previously_deleted" class="ts-meta">Already deleted on the wiki</span>
													<span v-else-if="page.exists === false" class="ts-meta">Not on the wiki</span>
													<span v-else-if="page.exists" class="ts-meta">Up · {{ page.revisions }} edit{{ page.revisions === 1 ? '' : 's' }}</span>
													<span v-else class="ts-meta">—</span>
												</td>
												<td data-label="">
													<cdx-button
														v-if="!page.deleted"
														weight="quiet"
														size="small"
														:aria-label="`Take ${ page.title } off the file`"
														@click="removePage( page )"
													>
														<cdx-icon :icon="cdxIconClose" size="small" />
													</cdx-button>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
							</template>
						</div>
					</section>

					<section v-if="item.removals.length" class="ts-section">
						<h2 class="ts-section__title">Erasures</h2>
						<div class="ts-panel">
							<ul class="ts-accounts">
								<li v-for="r in item.removals" :key="r.id" class="ts-accounts__row">
									<span class="ts-accounts__name">
										<router-link :to="{ name: 'data-removals' }" class="ts-mono">{{ r.reference }}</router-link>
										{{ r.account }}
									</span>
									<StatusChip kind="removal" :value="r.state" />
								</li>
							</ul>
						</div>
					</section>

					<section class="ts-section">
						<ActivityFeed
							kind="investigation"
							:entries="timeline"
							:loading="timelineLoading"
							:item="item"
							@changed="onTransacted"
						/>
					</section>

					<section class="ts-section">
						<ActivityComposer
							:key="item.id"
							kind="investigation"
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
							<dt>State</dt>
							<dd>
								<StatusChip kind="investigation" :value="item.status" />
								<div v-if="!item.live" class="ts-meta">
									{{ ago( item.closed ) }}<template v-if="item.closed_by"> by {{ item.closed_by }}</template>
									· reopen to take actions
								</div>
							</dd>
							<dt>With</dt>
							<dd>{{ item.assignee ? item.assignee.username : 'Nobody' }}</dd>
							<dt>Priority</dt>
							<dd><StatusChip kind="priority" :value="item.priority" /></dd>
							<dt>Look again</dt>
							<dd>{{ item.review_at ? dateTime( item.review_at ).replace( /,? \d{1,2}:\d{2}.*$/, '' ) : 'Not set' }}</dd>
							<dt>Opened</dt>
							<dd>{{ dateTime( item.opened ) }}<template v-if="item.opened_by"> by {{ item.opened_by }}</template></dd>
						</dl>
						<p class="ts-meta ts-case-aside__foot">
							Updated {{ ago( item.updated ) }} · change these with <strong>Add action</strong>
						</p>
					</div>

					<div class="ts-panel">
						<h2 class="ts-section__title">Reports</h2>
						<ul v-if="item.cases.length" class="ts-accounts">
							<li v-for="c in item.cases" :key="c.id" class="ts-accounts__row ts-accounts__row--removable">
								<div class="ts-accounts__name">
									<router-link :to="{ name: 'case', params: { id: c.id } }" class="ts-mono">
										{{ c.reference }}
									</router-link>
									<div class="ts-meta">
										{{ c.subject }}<template v-if="c.anonymous"> · anonymous</template>
									</div>
								</div>
								<span class="ts-accounts__chips">
									<StatusChip kind="case" :value="c.status" />
									<cdx-button
										weight="quiet"
										size="small"
										class="ts-accounts__remove"
										:aria-label="`Take ${ c.reference } off the file`"
										@click="detach( c )"
									>
										<cdx-icon :icon="cdxIconClose" size="small" />
									</cdx-button>
								</span>
							</li>
						</ul>
						<p v-else class="ts-meta">Nothing attached yet.</p>

						<form class="ts-accounts__add" @submit.prevent="attach">
							<cdx-text-input
								v-model="attachRef"
								placeholder="Attach by reference"
								aria-label="Attach a report by its reference"
							/>
							<cdx-button type="submit" :disabled="!attachRef.trim() || attaching">Attach</cdx-button>
						</form>
					</div>

					<div class="ts-panel">
						<h2 class="ts-section__title">Accounts</h2>

						<ul v-if="item.subjects.length" class="ts-accounts">
							<li v-for="s in item.subjects" :key="s.id" class="ts-accounts__row ts-accounts__row--removable">
								<div class="ts-accounts__name">
									<router-link :to="{ name: 'subject', params: { id: s.id } }">
										{{ s.username }}
									</router-link>
									<div v-if="s.unresolved" class="ts-meta">Not found on the farm</div>
								</div>
								<span class="ts-accounts__chips">
									<StatusChip kind="role" :value="s.role" />
									<StatusChip kind="standing" :value="s.standing" />
									<cdx-button
										weight="quiet"
										size="small"
										class="ts-accounts__remove"
										:aria-label="`Take ${ s.username } off the file`"
										@click="removeSubject( s )"
									>
										<cdx-icon :icon="cdxIconClose" size="small" />
									</cdx-button>
								</span>
							</li>
						</ul>
						<p v-else class="ts-meta">Nobody is named yet.</p>

						<form class="ts-accounts__add ts-accounts__add--stacked" @submit.prevent="addSubject">
							<cdx-text-input
								v-model="newSubject.username"
								placeholder="Add an account"
								aria-label="Name another account"
							/>
							<cdx-select
								v-model:selected="newSubject.role"
								:menu-items="roleOptions"
								aria-label="Their role"
							/>
							<cdx-button type="submit" :disabled="!newSubject.username.trim() || addingSubject">Add</cdx-button>
						</form>
						<cdx-button weight="quiet" size="small" class="ts-accounts__paste" @click="showAddSubjects = true">
							<cdx-icon :icon="cdxIconUserAdd" size="small" />
							Paste a list
						</cdx-button>
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

			<DeletePagesDialog
				v-model:open="showDeletePages"
				:pages="pickedPages"
				:investigation="item"
				@done="onPagesDeleted"
			/>

			<AddPagesDialog
				v-model:open="showAddPages"
				:investigation-id="item.id"
				:default-wiki="defaultWiki"
				@added="onPagesAdded"
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
					<cdx-message v-if="openCases.length" type="notice" :allow-user-dismiss="false">
						This will close {{ openCases.length }} open report{{ openCases.length === 1 ? '' : 's' }}
						({{ openCases.map( ( c ) => c.reference ).join( ', ' ) }}).
					</cdx-message>

					<cdx-field>
						<template #label>What was the outcome</template>
						<cdx-select v-model:selected="conclusion.outcome" :menu-items="outcomeOptions" />
					</cdx-field>

					<cdx-field>
						<template #label>Findings</template>
						<MarkdownEditor v-model="conclusion.findings" :upload="uploadImage" :rows="4" label="Findings" />
					</cdx-field>

				</div>
			</cdx-dialog>
		</template>
	</main>
</template>

<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import {
	CdxButton, CdxCheckbox, CdxDialog, CdxField, CdxIcon, CdxMenuButton,
	CdxMessage, CdxProgressBar, CdxSelect, CdxTextInput
} from '@wikimedia/codex';
import {
	cdxIconAdd, cdxIconAlert, cdxIconBlock, cdxIconCheck, cdxIconClose, cdxIconEllipsis, cdxIconTrash,
	cdxIconUserAdd, cdxIconUserGroup
} from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import StatusChip from '../components/StatusChip.vue';
import ActivityFeed from '../components/ActivityFeed.vue';
import ActivityComposer from '../components/ActivityComposer.vue';
import IssueActionDialog from '../components/IssueActionDialog.vue';
import BulkActionDialog from '../components/BulkActionDialog.vue';
import AddSubjectsDialog from '../components/AddSubjectsDialog.vue';
import DeletePagesDialog from '../components/DeletePagesDialog.vue';
import AddPagesDialog from '../components/AddPagesDialog.vue';
import MarkdownEditor from '../components/MarkdownEditor.vue';
import RichText from '../components/RichText.vue';
import { api } from '../lib/api.js';
import { remember } from '../lib/recents.js';
import {
	ago, dateTime, OUTCOMES, SUBJECT_ROLES
} from '../lib/format.js';

const props = defineProps( { id: { type: [ String, Number ], required: true } } );
const notify = inject( 'notify' );
const route = useRoute();
let scrolled = false;

const item = ref( null );
const timeline = ref( [] );
const timelineLoading = ref( false );
const loading = ref( false );
const error = ref( null );

const uploadImage = ( file ) => api.uploadInvestigationImage( props.id, file );

const newSubject = reactive( { username: '', role: 'subject' } );
const addingSubject = ref( false );

const attachRef = ref( '' );
const attaching = ref( false );

const showIssue = ref( false );
const showBulk = ref( false );
const bulkKind = ref( 'action' );
const showAddSubjects = ref( false );
const showDeletePages = ref( false );
const showAddPages = ref( false );
const pagesPicked = ref( [] );
const refreshingPages = ref( false );
const pagesSection = ref( null );
const showConclude = ref( false );
const moreChoice = ref( null );
const conclusion = reactive( {
	outcome: null,
	findings: ''
} );

const roleOptions = SUBJECT_ROLES;
const outcomeOptions = OUTCOMES;

const team = ref( [] );

const openCases = computed( () => (
	( item.value?.cases ?? [] ).filter( ( c ) => [ 'received', 'in-review', 'investigating' ].includes( c.status ) )
) );

const moreMenu = computed( () => {
	const data = item.value;
	if ( !data ) {
		return [];
	}
	const items = [];
	items.push( { value: 'pages', label: 'Add pages…', icon: cdxIconAdd } );
	items.push( { value: 'accounts', label: 'Paste a list of accounts…', icon: cdxIconUserAdd } );
	if ( data.live ) {
		items.push( { value: 'erase', label: 'Erase accounts on this file…', icon: cdxIconTrash, action: 'destructive', disabled: !data.subjects.length } );
	}
	return items;
} );

function onMore( value ) {
	moreChoice.value = null;
	if ( value === 'erase' ) {
		openBulk( 'erasure' );
	} else if ( value === 'pages' ) {
		showAddPages.value = true;
	} else if ( value === 'accounts' ) {
		showAddSubjects.value = true;
	}
}

const actionable = computed( () => item.value?.subjects ?? [] );

const pages = computed( () => item.value?.pages ?? [] );
const livePages = computed( () => pages.value.filter( ( p ) => !p.deleted ) );
const pickedPages = computed( () => pages.value.filter( ( p ) => pagesPicked.value.includes( p.id ) ) );
const lookingUp = computed( () => pages.value.filter( ( p ) => !p.info.fetched && !p.info.error ).length );

const defaultWiki = computed( () => {
	const counts = {};
	for ( const page of pages.value ) {
		counts[ page.wiki ] = ( counts[ page.wiki ] ?? 0 ) + 1;
	}
	return Object.entries( counts ).sort( ( a, b ) => b[ 1 ] - a[ 1 ] )[ 0 ]?.[ 0 ] ?? '';
} );

function pickAllPages() {
	pagesPicked.value = pagesPicked.value.length === livePages.value.length
		? []
		: livePages.value.map( ( p ) => p.id );
}

async function onPagesDeleted() {
	pagesPicked.value = [];
	await load();
}

function onPagesAdded( response ) {
	adopt( response.data.data ?? response.data );
	loadTimeline();
}

async function refreshPages() {
	refreshingPages.value = true;
	try {
		const response = await api.refreshInvestigationPages( props.id, pagesPicked.value.length ? { page_ids: pagesPicked.value } : {} );
		adopt( response.data.data ?? response.data );
		notify(
			response.error
				? `Looked up ${ response.fetched }; ${ response.failed } could not be: ${ response.error }`
				: `Looked up ${ response.fetched } page${ response.fetched === 1 ? '' : 's' }.`,
			response.failed ? 'warning' : 'success'
		);
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		refreshingPages.value = false;
	}
}

async function removePage( page ) {
	try {
		const response = await api.removeInvestigationPage( props.id, page.id );
		adopt( response.data );
		pagesPicked.value = pagesPicked.value.filter( ( id ) => id !== page.id );
		loadTimeline();
		notify( `${ page.title } taken off the file.` );
	} catch ( e ) {
		notify( e.message, 'error' );
	}
}

let pagePoll = null;
let pagePolls = 0;

watch( lookingUp, ( count ) => {
	clearTimeout( pagePoll );
	if ( count && pagePolls < 20 ) {
		pagePoll = setTimeout( async () => {
			pagePolls++;
			try {
				adopt( ( await api.investigation( props.id ) ).data );
			} catch ( e ) {
			}
		}, 3000 );
	}
} );

onUnmounted( () => clearTimeout( pagePoll ) );

function scrollToPages() {
	if ( route.hash === '#pages' ) {
		nextTick( () => pagesSection.value?.scrollIntoView( { behavior: 'smooth', block: 'start' } ) );
	}
}

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
		if ( !scrolled ) {
			scrolled = true;
			scrollToPages();
		}
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

function onTransacted( data ) {
	adopt( data );
	loadTimeline();
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
			findings: conclusion.findings || null
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
