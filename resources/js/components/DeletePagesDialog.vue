<template>
	<cdx-dialog
		:open="open"
		:title="picked.length === 1 ? 'Delete a page' : 'Delete pages'"
		:subtitle="subtitleText"
		class="ts-bulk-dialog ts-delete-pages"
		:use-close-button="true"
		:primary-action="primaryAction"
		:default-action="{ label: finished ? 'Close' : 'Cancel' }"
		@update:open="$emit( 'update:open', $event )"
		@primary="onPrimary"
		@default="close"
	>
		<div class="ts-stack">
			<cdx-message v-if="finished" :type="outcome.failed || outcome.needs_manual_action ? 'warning' : 'success'" :allow-user-dismiss="false">
				<p>
					<span class="ts-mono">{{ outcome.reference }}</span>
					<template v-if="outcome.needs_manual_action">
						is recorded, but the wiki has not deleted anything yet: {{ outcome.push_error }}
					</template>
					<template v-else>
						is deleting {{ outcome.pages }} page{{ outcome.pages === 1 ? '' : 's' }} on
						{{ ( outcome.wikis ?? [] ).join( ', ' ) }}.
					</template>
					<template v-if="outcome.notices.length">
						{{ outcome.done }} editor{{ outcome.done === 1 ? '' : 's' }} told<template v-if="outcome.failed">,
							{{ outcome.failed }} could not be</template>.
					</template>
				</p>
				<p v-for="s in outcome.skipped" :key="s.page" class="ts-meta">{{ s.page }} — {{ s.why }}</p>
			</cdx-message>

			<div class="ts-bulk-table">
				<div class="ts-bulk-table__head">
					<span class="ts-bulk-table__count">
						{{ picked.length }} page{{ picked.length === 1 ? '' : 's' }} on {{ wikiCount }} wiki{{ wikiCount === 1 ? '' : 's' }}
					</span>
				</div>
				<table>
					<caption class="ts-visually-hidden">The pages to delete</caption>
					<thead>
						<tr>
							<th scope="col">Page</th>
							<th scope="col">Wiki</th>
							<th scope="col">Created by</th>
							<th scope="col">Edits</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="page in picked" :key="page.id">
							<td data-label="Page">
								<strong>{{ page.title }}</strong>
								<div v-if="page.exists === false" class="ts-meta">The wiki says this page does not exist.</div>
							</td>
							<td data-label="Wiki" class="ts-mono">{{ page.wiki }}</td>
							<td data-label="Created by">{{ page.creator ?? '—' }}</td>
							<td data-label="Edits">{{ page.revisions ?? '—' }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<cdx-field :status="form.reason.trim() || !touched ? 'default' : 'warning'" :messages="{ warning: 'Say why the pages are being deleted.' }">
				<template #label>Reason for deleting</template>
				<template #description>Shown in each wiki's deletion log.</template>
				<cdx-text-area v-model="form.reason" rows="2" :disabled="running || finished" @blur="touched = true" />
			</cdx-field>

			<cdx-field>
				<template #label>Reason category</template>
				<cdx-select v-model:selected="form.reason_category" :menu-items="reasonCategories" :disabled="running || finished" />
			</cdx-field>

			<cdx-field>
				<template #label>Internal reason</template>
				<template #description>Never leaves the portal.</template>
				<cdx-text-area v-model="form.internal_reason" rows="2" :disabled="running || finished" />
			</cdx-field>

			<h3 class="ts-section__title">Tell the editors</h3>

			<cdx-field>
				<template #label>Put on their record</template>
				<template #description>
					Each editor ticked gets this as a formal record of their own, naming only the
					pages they edited, and is notified on the wiki. Whoever created each page and
					anyone reported for it are ticked to start with.
				</template>
				<cdx-select v-model:selected="form.notice_type" :menu-items="noticeOptions" :disabled="running || finished" />
			</cdx-field>

			<template v-if="form.notice_type !== 'none'">
				<div class="ts-bulk-table">
					<div class="ts-bulk-table__head">
						<span class="ts-bulk-table__count">
							{{ told.length }} of {{ editors.length }} editor{{ editors.length === 1 ? '' : 's' }} to tell
						</span>
						<span class="ts-inline">
							<cdx-button weight="quiet" size="small" :disabled="running || finished" @click="tellSuggested">
								Creators and reported
							</cdx-button>
							<cdx-button weight="quiet" size="small" :disabled="running || finished" @click="told = editors.map( ( e ) => e.key )">
								Everyone
							</cdx-button>
							<cdx-button weight="quiet" size="small" :disabled="running || finished" @click="told = []">
								Nobody
							</cdx-button>
						</span>
					</div>

					<p v-if="!editors.length" class="ts-meta">
						Nobody is known to have edited these pages. Look them up again, or add editors below.
					</p>

					<table v-else>
						<caption class="ts-visually-hidden">Editors to tell</caption>
						<thead>
							<tr>
								<th scope="col" class="ts-bulk-table__pick"><span class="ts-visually-hidden">Tell</span></th>
								<th scope="col">Editor</th>
								<th scope="col">Why</th>
								<th scope="col">Pages</th>
								<th scope="col">What happened</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="editor in editors" :key="editor.key">
								<td data-label="Tell" class="ts-bulk-table__pick">
									<cdx-checkbox
										v-model="told"
										:input-value="editor.key"
										:disabled="running || finished || !editor.registered"
									>
										<span class="ts-visually-hidden">{{ editor.username }}</span>
									</cdx-checkbox>
								</td>
								<td data-label="Editor">
									{{ editor.username }}
									<div v-if="!editor.registered" class="ts-meta">Not an account, so cannot be told</div>
								</td>
								<td data-label="Why">
									<span class="ts-inline">
										<cdx-info-chip v-if="editor.created" status="warning">Created {{ editor.created }}</cdx-info-chip>
										<cdx-info-chip v-if="editor.reported" status="error">Reported</cdx-info-chip>
										<span v-if="editor.edits" class="ts-meta">{{ editor.edits }} edit{{ editor.edits === 1 ? '' : 's' }}</span>
									</span>
								</td>
								<td data-label="Pages">
									{{ editor.pageIds === null ? 'Every page picked' : editor.pageIds.length }}
								</td>
								<td data-label="What happened">
									<template v-if="noticeFor( editor )">
										<cdx-info-chip :status="noticeFor( editor ).ok ? 'success' : 'error'">
											{{ noticeFor( editor ).ok ? noticeFor( editor ).reference : 'Not done' }}
										</cdx-info-chip>
										<div v-if="!noticeFor( editor ).ok" class="ts-meta">{{ noticeFor( editor ).message }}</div>
									</template>
									<span v-else class="ts-meta">—</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div v-if="!finished" class="ts-inline">
					<cdx-text-input
						v-model="addingEditor"
						placeholder="Another editor's username"
						:disabled="running"
						@keydown.enter.prevent="addEditor"
					/>
					<cdx-button :disabled="running || !addingEditor.trim()" @click="addEditor">Add</cdx-button>
				</div>

				<cdx-field>
					<template #label>What they are told</template>
					<template #description>
						Shown to each editor onwiki and in the email, followed by the pages of theirs
						that were deleted. Leave empty to give them the reason for deleting.
					</template>
					<cdx-text-area
						v-model="form.notice_reason"
						rows="3"
						:placeholder="form.reason || 'A page you created or edited broke the Terms of Use and was deleted.'"
						:disabled="running || finished"
					/>
				</cdx-field>
			</template>
		</div>
	</cdx-dialog>
</template>

<script setup>
import { computed, inject, reactive, ref, watch } from 'vue';
import {
	CdxButton, CdxCheckbox, CdxDialog, CdxField, CdxInfoChip, CdxMessage,
	CdxSelect, CdxTextArea, CdxTextInput
} from '@wikimedia/codex';
import { api } from '../lib/api.js';
import { ACTION_REASONS } from '../lib/format.js';

const props = defineProps( {
	open: { type: Boolean, default: false },
	pages: { type: Array, default: () => [] },
	investigation: { type: Object, required: true }
} );

const emit = defineEmits( [ 'update:open', 'done' ] );
const notify = inject( 'notify' );

const reasonCategories = [ { value: null, label: 'Not recorded' }, ...ACTION_REASONS ];

const noticeOptions = [
	{ value: 'warning', label: 'Formal warning' },
	{ value: 'note', label: 'Note on file' },
	{ value: 'none', label: 'Nothing — do not tell them' }
];

const form = reactive( {
	reason: '',
	reason_category: null,
	internal_reason: '',
	notice_type: 'warning',
	notice_reason: ''
} );

const picked = ref( [] );
const addedEditors = ref( [] );
const told = ref( [] );
const addingEditor = ref( '' );
const running = ref( false );
const touched = ref( false );
const outcome = ref( null );

const finished = computed( () => outcome.value !== null );
const wikiCount = computed( () => new Set( picked.value.map( ( p ) => p.wiki ) ).size );

const editors = computed( () => {
	const byName = new Map();

	for ( const page of picked.value ) {
		for ( const editor of page.editors ?? [] ) {
			if ( !editor.username ) {
				continue;
			}

			if ( !byName.has( editor.username ) ) {
				byName.set( editor.username, {
					key: editor.username,
					username: editor.username,
					subjectId: editor.subject_id ?? null,
					registered: editor.registered !== false,
					reported: false,
					created: 0,
					edits: 0,
					pageIds: []
				} );
			}

			const entry = byName.get( editor.username );
			entry.subjectId ??= editor.subject_id ?? null;
			entry.reported ||= !!editor.reported;
			entry.created += editor.creator ? 1 : 0;
			entry.edits += editor.edits ?? 0;
			if ( !entry.pageIds.includes( page.id ) ) {
				entry.pageIds.push( page.id );
			}
		}
	}

	for ( const username of addedEditors.value ) {
		if ( !byName.has( username ) ) {
			byName.set( username, {
				key: username,
				username,
				subjectId: null,
				registered: true,
				reported: false,
				created: 0,
				edits: 0,
				pageIds: null
			} );
		}
	}

	return [ ...byName.values() ].sort( ( a, b ) => (
		( b.reported - a.reported ) || ( b.created - a.created ) || ( b.edits - a.edits )
	) );
} );

const subtitleText = computed( () => `Under ${ props.investigation.reference }. The wiki deletes them and reports back.` );

const canSubmit = computed( () => (
	picked.value.length > 0 && form.reason.trim().length > 0 && props.investigation.live !== false
) );

const toNotify = computed( () => (
	form.notice_type === 'none'
		? []
		: editors.value.filter( ( e ) => told.value.includes( e.key ) && e.registered )
) );

const primaryAction = computed( () => {
	if ( finished.value ) {
		return undefined;
	}

	const count = picked.value.length;
	let label = `Delete ${ count } page${ count === 1 ? '' : 's' }`;
	if ( toNotify.value.length ) {
		label += ` and tell ${ toNotify.value.length } editor${ toNotify.value.length === 1 ? '' : 's' }`;
	}

	return {
		label: running.value ? 'Deleting…' : label,
		actionType: 'destructive',
		disabled: !canSubmit.value || running.value
	};
} );

function tellSuggested() {
	told.value = editors.value.filter( ( e ) => e.registered && ( e.created || e.reported ) ).map( ( e ) => e.key );
}

watch( () => props.open, ( isOpen ) => {
	if ( !isOpen ) {
		return;
	}

	picked.value = props.pages.filter( ( p ) => !p.deleted );
	form.reason = '';
	form.reason_category = null;
	form.internal_reason = '';
	form.notice_type = 'warning';
	form.notice_reason = '';
	addedEditors.value = [];
	addingEditor.value = '';
	outcome.value = null;
	touched.value = false;
	tellSuggested();
} );

function addEditor() {
	const username = addingEditor.value.trim().replace( /^\s*(User|User talk)\s*:\s*/i, '' ).replace( /_/g, ' ' );
	if ( !username ) {
		return;
	}

	if ( !editors.value.some( ( e ) => e.username === username ) ) {
		addedEditors.value.push( username );
	}
	if ( !told.value.includes( username ) ) {
		told.value.push( username );
	}

	addingEditor.value = '';
}

function noticeFor( editor ) {
	return ( outcome.value?.notices ?? [] ).find( ( n ) => (
		( editor.subjectId && n.subject_id === editor.subjectId ) || n.username === editor.username
	) ) ?? null;
}

function close() {
	emit( 'update:open', false );
}

async function onPrimary() {
	if ( finished.value ) {
		close();
		return;
	}

	touched.value = true;
	if ( !canSubmit.value ) {
		return;
	}

	running.value = true;

	try {
		const response = await api.deletePages( props.investigation.id, {
			page_ids: picked.value.map( ( p ) => p.id ),
			reason: form.reason,
			reason_category: form.reason_category,
			internal_reason: form.internal_reason || null,
			notice_type: form.notice_type === 'none' ? null : form.notice_type,
			notice_reason: form.notice_reason || null,
			notify: toNotify.value.map( ( e ) => ( {
				subject_id: e.subjectId,
				username: e.subjectId ? null : e.username,
				page_ids: e.pageIds
			} ) )
		} );

		outcome.value = response;
		emit( 'done', response );

		notify(
			response.needs_manual_action
				? `Recorded as ${ response.reference }. The wiki has not deleted anything — somebody has to.`
				: `${ response.reference }: deleting ${ response.pages } page${ response.pages === 1 ? '' : 's' }${ response.done ? `, ${ response.done } editor${ response.done === 1 ? '' : 's' } told` : '' }.`,
			response.needs_manual_action || response.failed ? 'warning' : 'success'
		);
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		running.value = false;
	}
}
</script>
