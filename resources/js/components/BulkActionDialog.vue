<template>
	<cdx-dialog
		:open="open"
		title="Act on several accounts"
		:subtitle="subtitleText"
		class="ts-bulk-dialog"
		:use-close-button="true"
		:primary-action="primaryAction"
		:default-action="{ label: finished ? 'Close' : 'Cancel' }"
		@update:open="$emit( 'update:open', $event )"
		@primary="onPrimary"
		@default="close"
	>
		<div class="ts-stack">
			<cdx-message v-if="!live" type="warning" :allow-user-dismiss="false">
				This file is not open, so nothing can be done under it. Reopen it first.
			</cdx-message>

			<cdx-field>
				<template #label>What to do</template>
				<cdx-select
					v-model:selected="form.kind"
					:menu-items="kindOptions"
					:disabled="running"
				/>
			</cdx-field>

			<div class="ts-bulk-table">
				<div class="ts-bulk-table__head">
					<span class="ts-bulk-table__count">
						{{ picked.length }} of {{ rows.length }} picked
					</span>
					<cdx-button
						weight="quiet"
						size="small"
						:disabled="running || !rows.length"
						@click="pickAll( !allPicked )"
					>
						{{ allPicked ? 'Pick none' : 'Pick all' }}
					</cdx-button>
				</div>

				<p v-if="!rows.length" class="ts-meta">
					No accounts are named on this file yet. Add them first, and they will appear here.
				</p>

				<table v-else>
					<caption class="ts-visually-hidden">
						The accounts on this file, and what to do to each
					</caption>
					<thead>
						<tr>
							<th scope="col" class="ts-bulk-table__pick"><span class="ts-visually-hidden">Picked</span></th>
							<th scope="col">Account</th>
							<th scope="col">On the file as</th>
							<th scope="col">Standing</th>
							<th scope="col">What happened</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="row in rows"
							:key="row.id"
							:class="{ 'ts-bulk-table__row--picked': picked.includes( row.id ) }"
						>
							<td data-label="Picked" class="ts-bulk-table__pick">
								<cdx-checkbox
									v-model="picked"
									:input-value="row.id"
									:disabled="running || !canPick( row )"
								>
									<span class="ts-visually-hidden">{{ row.username }}</span>
								</cdx-checkbox>
							</td>
							<td data-label="Account">
								<router-link
									:to="{ name: 'subject', params: { id: row.id } }"
									target="_blank"
								>
									{{ row.username }}
								</router-link>
								<div v-if="row.erased" class="ts-meta">Already erased</div>
								<div v-else-if="row.unresolved" class="ts-meta">
									No matching account on the farm
								</div>
							</td>
							<td data-label="On the file as">
								<StatusChip kind="role" :value="row.role" />
							</td>
							<td data-label="Standing">
								<StatusChip kind="standing" :value="row.standing" />
							</td>
							<td data-label="What happened">
								<template v-if="results[ row.id ]">
									<cdx-info-chip :status="results[ row.id ].ok ? ( results[ row.id ].needs_a_person ? 'warning' : 'success' ) : 'error'">
										{{ results[ row.id ].ok ? ( results[ row.id ].reference || 'Done' ) : 'Not done' }}
									</cdx-info-chip>
									<div class="ts-meta">{{ results[ row.id ].message }}</div>
								</template>
								<span v-else-if="running && picked.includes( row.id )" class="ts-meta">
									Working…
								</span>
								<span v-else class="ts-meta">—</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<template v-if="isErasure">
				<cdx-message type="error" :allow-user-dismiss="false">
					Every account picked will be renamed across the wikis it is attached to and the
					personal data held against it removed. This cannot be undone, and it is done to
					all of them at once.
				</cdx-message>

				<cdx-field>
					<template #label>Under what</template>
					<template #description>
						Recorded against each erasure, because the answer to “why was this account
						erased” has to survive the erasure.
					</template>
					<cdx-select
						v-model:selected="form.legal_basis"
						:menu-items="basisOptions"
						:disabled="running"
					/>
				</cdx-field>

				<cdx-field>
					<template #label>Why</template>
					<template #description>For the file. The same words go on every erasure.</template>
					<cdx-text-area v-model="form.reason" rows="3" :disabled="running" />
				</cdx-field>

				<cdx-checkbox v-model="form.hold" :disabled="running">
					Write them down without starting them
				</cdx-checkbox>
			</template>

			<template v-else>
				<cdx-field>
					<template #label>Which action</template>
					<cdx-select
						v-model:selected="form.type"
						:menu-items="actionTypes"
						:disabled="running"
					/>
				</cdx-field>

				<cdx-field
					v-if="isCustom"
					:status="form.label.trim() ? 'default' : 'warning'"
					:messages="{ warning: 'Say what was done, in a few words.' }"
				>
					<template #label>What was done</template>
					<cdx-text-input
						v-model="form.label"
						placeholder="Removal of content"
						:disabled="running"
					/>
				</cdx-field>

				<cdx-field
					v-if="needsWikis"
					:status="form.wikis.length ? 'default' : 'warning'"
					:messages="{ warning: 'A block has to name at least one wiki, or it does nothing.' }"
				>
					<template #label>On which wikis</template>
					<template #description>The same wikis for every account picked.</template>
					<cdx-multiselect-lookup
						v-model:input-chips="wikiChips"
						v-model:selected="form.wikis"
						:menu-items="wikiOptions"
						:menu-config="{ visibleItemLimit: 8 }"
						placeholder="oasisexamplewiki"
						aria-label="Wikis"
						:disabled="running"
						@input="onWikiInput"
					/>
				</cdx-field>

				<cdx-field>
					<template #label>Reason the accounts are given</template>
					<template #description>
						Shown to each of them, onwiki and in the email. Write it so it reads for any
						one of them.
					</template>
					<cdx-text-area v-model="form.reason" rows="3" :disabled="running" />
				</cdx-field>

				<cdx-field>
					<template #label>Reason category</template>
					<cdx-select
						v-model:selected="form.reason_category"
						:menu-items="reasonCategories"
						:disabled="running"
					/>
				</cdx-field>

				<cdx-field>
					<template #label>Internal reason</template>
					<template #description>Never leaves the portal.</template>
					<cdx-text-area v-model="form.internal_reason" rows="2" :disabled="running" />
				</cdx-field>

				<cdx-field>
					<template #label>Ends</template>
					<template #description>Leave empty for actions that do not expire on their own.</template>
					<cdx-text-input
						v-model="form.expires_at"
						input-type="datetime-local"
						:disabled="running"
					/>
				</cdx-field>

				<cdx-checkbox v-model="form.appealable" :disabled="running">
					They may ask for these to be reconsidered
				</cdx-checkbox>

				<cdx-message
					v-if="needsAdmin && !session.can( 'admin' )"
					type="warning"
					:allow-user-dismiss="false"
				>
					Suspending accounts needs the admin flag, which your account does not hold.
				</cdx-message>

				<cdx-message
					v-else-if="form.type !== 'note' && !isCustom && !supported.includes( wikiVerb )"
					type="notice"
					:allow-user-dismiss="false"
				>
					The wiki extension cannot carry this out yet. Each one is recorded here and
					flagged as needing somebody to do it by hand.
				</cdx-message>
			</template>

			<cdx-progress-bar v-if="running" aria-label="Working through the accounts" />

			<cdx-message v-if="finished" :type="summaryTone" :allow-user-dismiss="false">
				{{ summary }}
			</cdx-message>
		</div>
	</cdx-dialog>
</template>

<script setup>
import { computed, inject, reactive, ref, watch } from 'vue';
import {
	CdxButton, CdxCheckbox, CdxDialog, CdxField, CdxInfoChip, CdxMessage,
	CdxMultiselectLookup, CdxProgressBar, CdxSelect, CdxTextArea, CdxTextInput
} from '@wikimedia/codex';
import StatusChip from './StatusChip.vue';
import { api } from '../lib/api.js';
import {
	ACTION_REASONS, BULK_KINDS, LEGAL_BASES, SANCTION_TYPES, sanctionType
} from '../lib/format.js';
import { session } from '../lib/session.js';

const props = defineProps( {
	open: { type: Boolean, default: false },
	investigation: { type: Object, default: null },
	kind: { type: String, default: 'action' },
	preselect: { type: Array, default: () => [] }
} );

const emit = defineEmits( [ 'update:open', 'done' ] );
const notify = inject( 'notify' );

const picked = ref( [] );
const results = ref( {} );
const running = ref( false );
const finished = ref( false );
const outcome = ref( null );

const knownWikis = ref( [] );
const wikiChips = ref( [] );

const form = reactive( {
	kind: 'action',
	type: 'warning',
	label: '',
	wikis: [],
	reason: '',
	reason_category: null,
	internal_reason: '',
	expires_at: '',
	appealable: true,
	legal_basis: 'gdpr-17',
	hold: false
} );

const kindOptions = BULK_KINDS;
const basisOptions = LEGAL_BASES;
const reasonCategories = [ { value: null, label: 'Not recorded' }, ...ACTION_REASONS ];

const actionTypes = SANCTION_TYPES.filter( ( t ) => t.account !== false );

const supported = computed( () => session.wiki?.supported_actions ?? [] );

const live = computed( () => props.investigation?.live !== false );

const isErasure = computed( () => form.kind === 'erasure' );
const meta = computed( () => sanctionType( form.type ) );
const needsWikis = computed( () => !isErasure.value && meta.value?.wikis === true );
const isCustom = computed( () => !isErasure.value && meta.value?.custom === true );
const needsAdmin = computed( () => !isErasure.value && form.type === 'lock' );

const wikiVerb = computed( () => ( {
	lock: 'lock',
	warning: 'warn',
	note: 'note',
	block: 'block'
}[ form.type ] ?? form.type ) );

const rows = computed( () => ( props.investigation?.subjects ?? [] ).map( ( s ) => ( {
	id: s.id,
	username: s.username,
	role: s.role ?? 'subject',
	standing: s.standing ?? 'good',
	unresolved: !!s.unresolved,
	erased: !!s.erased
} ) ) );

const allPicked = computed( () => (
	rows.value.length > 0 && picked.value.length === rows.value.filter( canPick ).length
) );

const subtitleText = computed( () => (
	props.investigation
		? `Out of ${ props.investigation.reference } — ${ props.investigation.title }`
		: 'Every account picked is dealt with in one go, under one investigation.'
) );

const canSubmit = computed( () => {
	if ( !live.value || picked.value.length === 0 || form.reason.trim() === '' ) {
		return false;
	}
	if ( isErasure.value ) {
		return true;
	}
	if ( needsAdmin.value && !session.can( 'admin' ) ) {
		return false;
	}

	return ( !needsWikis.value || form.wikis.length > 0 )
		&& ( !isCustom.value || form.label.trim().length > 0 );
} );

const unfinished = computed( () => (
	Object.values( results.value ).filter( ( r ) => !r.ok ).map( ( r ) => r.subject_id )
) );

const primaryAction = computed( () => {
	if ( finished.value ) {
		return {
			label: unfinished.value.length
				? `Try the ${ unfinished.value.length } that did not go through`
				: 'Start again',
			actionType: 'progressive'
		};
	}

	return {
		label: running.value
			? 'Working…'
			: `${ isErasure.value ? 'Erase' : 'Act on' } ${ picked.value.length || 'no' } account${ picked.value.length === 1 ? '' : 's' }`,
		actionType: 'destructive',
		disabled: !canSubmit.value || running.value
	};
} );

const summaryTone = computed( () => {
	if ( !outcome.value ) {
		return 'notice';
	}

	return outcome.value.failed === 0
		? 'success'
		: ( outcome.value.done === 0 ? 'error' : 'warning' );
} );

const summary = computed( () => {
	if ( !outcome.value ) {
		return '';
	}

	const { done, failed } = outcome.value;
	const noun = isErasure.value ? 'erasure' : 'action';

	if ( failed === 0 ) {
		return `${ done } ${ noun }${ done === 1 ? '' : 's' } recorded. The file has the lot.`;
	}
	if ( done === 0 ) {
		return 'None of them went through. Nothing has been recorded. See each row for why.';
	}

	return `${ done } went through, ${ failed } did not. See each row for why — `
		+ 'trying again picks only the ones that did not.';
} );

function canPick( row ) {
	return !row.erased;
}

function pickAll( on ) {
	picked.value = on ? rows.value.filter( canPick ).map( ( r ) => r.id ) : [];
}

function reset( only = null ) {
	form.kind = props.kind === 'erasure' ? 'erasure' : 'action';
	form.type = 'warning';
	form.label = '';
	form.wikis = [];
	form.reason = '';
	form.reason_category = null;
	form.internal_reason = '';
	form.expires_at = '';
	form.appealable = true;
	form.legal_basis = 'gdpr-17';
	form.hold = false;

	wikiChips.value = [];
	results.value = {};
	outcome.value = null;
	finished.value = false;

	const pickable = rows.value.filter( canPick );
	const wanted = only ?? ( props.preselect.length ? props.preselect : null );

	picked.value = wanted === null
		? pickable.map( ( r ) => r.id )
		: pickable.filter( ( r ) => wanted.includes( r.id ) ).map( ( r ) => r.id );
}

watch( () => props.open, ( isOpen ) => {
	if ( isOpen ) {
		reset();
	}
} );

watch( () => form.type, () => {
	form.wikis = [];
	wikiChips.value = [];
	if ( needsWikis.value ) {
		loadWikis();
	}
} );

async function loadWikis() {
	try {
		const response = await api.wikis( { for: 'actionable' } );
		knownWikis.value = response.data;
	} catch ( e ) {
		knownWikis.value = [];
	}
}

const wikiOptions = computed( () => knownWikis.value.map( ( w ) => ( {
	value: w.dbname,
	label: w.dbname,
	description: w.sitename || undefined
} ) ) );

let wikiTimer = null;
function onWikiInput( value ) {
	clearTimeout( wikiTimer );
	wikiTimer = setTimeout( async () => {
		try {
			const response = await api.wikis( { q: value, for: 'actionable' } );
			knownWikis.value = response.data;
		} catch ( e ) {
		}
	}, 200 );
}

function onPrimary() {
	if ( finished.value ) {
		reset( unfinished.value.length ? unfinished.value : [] );
		return;
	}

	return run();
}

async function run() {
	if ( !canSubmit.value || running.value ) {
		return;
	}

	running.value = true;

	const body = {
		kind: form.kind,
		subject_ids: picked.value,
		reason: form.reason
	};

	if ( isErasure.value ) {
		body.legal_basis = form.legal_basis;
		body.hold = form.hold;
	} else {
		body.type = form.type;
		body.label = isCustom.value ? form.label : null;
		body.wikis = needsWikis.value ? form.wikis : null;
		body.internal_reason = form.internal_reason || null;
		body.reason_category = form.reason_category;
		body.expires_at = form.expires_at || null;
		body.appealable = form.appealable;
	}

	try {
		const response = await api.bulkInvestigationAction( props.investigation.id, body );

		results.value = Object.fromEntries(
			response.results.map( ( r ) => [ r.subject_id, r ] )
		);
		outcome.value = { done: response.done, failed: response.failed };
		finished.value = true;

		emit( 'done', response );

		notify(
			response.failed === 0
				? `${ response.done } done.`
				: `${ response.done } done, ${ response.failed } not.`,
			response.failed === 0 ? 'success' : 'warning'
		);
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		running.value = false;
	}
}

function close() {
	emit( 'update:open', false );
}
</script>
