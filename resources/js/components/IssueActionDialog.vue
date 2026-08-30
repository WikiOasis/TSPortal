<template>
	<cdx-dialog
		:open="open"
		title="Take an action"
		:subtitle="subtitleText"
		:use-close-button="true"
		:primary-action="{
			label: 'Take this action',
			actionType: 'destructive',
			disabled: !canSubmit || saving
		}"
		:default-action="{ label: 'Cancel' }"
		@update:open="$emit( 'update:open', $event )"
		@primary="submit"
		@default="$emit( 'update:open', false )"
	>
		<div class="ts-stack">
			<cdx-field>
				<template #label>What</template>
				<cdx-select v-model:selected="form.type" :menu-items="sanctionTypes" />
			</cdx-field>

			<cdx-field v-if="needsAccount && accounts.length > 1">
				<template #label>Against which account</template>
				<cdx-select v-model:selected="form.subject_id" :menu-items="accountOptions" />
			</cdx-field>

			<cdx-message
				v-else-if="needsAccount && accounts.length === 1"
				type="notice"
				:inline="true"
				:allow-user-dismiss="false"
			>
				Against <strong>{{ accounts[ 0 ].username }}</strong>.
			</cdx-message>

			<cdx-field
				v-if="needsWikis"
				:status="form.wikis.length ? 'default' : 'warning'"
				:messages="{ warning: wikiWarning }"
			>
				<template #label>{{ isDeletion ? 'Which wiki to delete' : 'On which wikis' }}</template>
				<template #description>
					<template v-if="!wikisKnown">
						The farm has not yet sent its list of wikis yet, so there is nothing to pick
						from.
					</template>
					<template v-else-if="isDeletion">
						This deletes the wiki. Its content goes with it.
					</template>
				</template>

				<cdx-multiselect-lookup
					v-model:input-chips="wikiChips"
					v-model:selected="form.wikis"
					:menu-items="wikiOptions"
					:menu-config="{ visibleItemLimit: 8 }"
					placeholder="oasisexamplewiki"
					aria-label="Wikis"
					@input="onWikiInput"
				/>
			</cdx-field>

			<cdx-message v-if="isDeletion" type="error" :allow-user-dismiss="false">
				This will delete the wiki. It can be lifted up until the wiki
                is permanently deleted in approximately 30 days.
			</cdx-message>

			<cdx-field
				v-if="isCustom"
				:status="form.label.trim() ? 'default' : 'warning'"
				:messages="{ warning: 'Say what was done, in a few words.' }"
			>
				<template #label>What was done</template>
				<template #description>
					A few words, as they will appear wherever this action is listed.
				</template>
				<cdx-text-input v-model="form.label" placeholder="Removal of content" />
			</cdx-field>

			<cdx-field
				v-if="!investigation"
				:status="form.investigation_reference ? 'default' : 'warning'"
				:messages="{ warning: 'An action has to come out of an investigation.' }"
			>
				<template #label>Out of which investigation</template>
				<cdx-select
					v-model:selected="form.investigation_reference"
					:menu-items="fileOptions"
				/>
			</cdx-field>

			<cdx-field>
				<template #label>{{ needsAccount ? 'Reason the account is given' : 'Reason' }}</template>
				<template #description>
					<template v-if="needsAccount">
						This is shown to the user, onwiki and in the email. Write it as something a
						person reads, not as case notes.
					</template>
					<template v-else>
						Recorded against the closure and shown in the wiki's own log.
					</template>
				</template>
				<cdx-text-area v-model="form.reason" rows="3" />
			</cdx-field>

			<cdx-field>
				<template #label>Reason category</template>
				<cdx-select v-model:selected="form.reason_category" :menu-items="reasonCategories" />
			</cdx-field>

			<cdx-field>
				<template #label>Internal reason</template>
				<template #description>Never leaves the portal.</template>
				<cdx-text-area v-model="form.internal_reason" rows="2" />
			</cdx-field>

			<cdx-field>
				<template #label>Ends</template>
				<template #description>Leave empty for an action that does not expire on its own.</template>
				<cdx-text-input v-model="form.expires_at" input-type="datetime-local" />
			</cdx-field>

			<cdx-checkbox v-if="needsAccount" v-model="form.appealable">
				They may ask for this to be reconsidered
			</cdx-checkbox>

			<cdx-message
				v-if="needsAdmin && !session.can( 'admin' )"
				type="warning"
				:allow-user-dismiss="false"
			>
				{{ form.type === 'lock' ? 'Suspending an account' : 'Closing a wiki' }} needs the
				admin flag, which your account does not hold.
			</cdx-message>

			<cdx-message
				v-if="!isCustom && form.type !== 'note' && !supported.includes( wikiVerb )"
				type="notice"
				:allow-user-dismiss="false"
			>
				The wiki extension cannot carry this out yet. It will be recorded here and flagged
				as needing someone to do it by hand.
			</cdx-message>

			<cdx-message
				v-if="form.type === 'lock' && session.wiki && session.wiki.centralauth_lock === false"
				type="warning"
				:allow-user-dismiss="false"
			>
				Central locking is switched off on this portal, so this will explain itself on the
				login screen and will not stop the account signing in through the API. Somebody will
				have to lock it by hand.
			</cdx-message>
		</div>
	</cdx-dialog>
</template>

<script setup>
import { computed, inject, reactive, ref, watch } from 'vue';
import {
	CdxCheckbox, CdxDialog, CdxField, CdxMessage, CdxMultiselectLookup,
	CdxSelect, CdxTextArea, CdxTextInput
} from '@wikimedia/codex';
import { api } from '../lib/api.js';
import { ACTION_REASONS, SANCTION_TYPES, sanctionType } from '../lib/format.js';
import { session } from '../lib/session.js';

const props = defineProps( {
	open: { type: Boolean, default: false },
	accounts: { type: Array, default: () => [] },
	investigation: { type: Object, default: null },
	files: { type: Array, default: () => [] },
	caseReference: { type: String, default: null }
} );

const emit = defineEmits( [ 'update:open', 'issued' ] );
const notify = inject( 'notify' );

const saving = ref( false );

const form = reactive( {
	subject_id: null,
	investigation_reference: null,
	type: 'warning',
	reason: '',
	reason_category: null,
	internal_reason: '',
	expires_at: '',
	appealable: true,
	wikis: [],
	label: ''
} );

const wikiChips = ref( [] );
const knownWikis = ref( [] );
const wikisKnown = ref( true );

const sanctionTypes = SANCTION_TYPES;

const reasonCategories = [ { value: null, label: 'Not recorded' }, ...ACTION_REASONS ];
const supported = computed( () => session.wiki?.supported_actions ?? [] );

const meta = computed( () => sanctionType( form.type ) );
const needsWikis = computed( () => meta.value?.wikis === true );
const needsAccount = computed( () => meta.value?.account !== false );
const isDeletion = computed( () => form.type === 'wiki-deletion' );
const isCustom = computed( () => meta.value?.custom === true );
const needsAdmin = computed( () => form.type === 'lock' || isDeletion.value );

const wikiVerb = computed( () => ( {
	lock: 'lock',
	warning: 'warn',
	note: 'note',
	block: 'block',
	'wiki-deletion': 'delete-wiki'
}[ form.type ] ?? form.type ) );

const wikiWarning = computed( () => (
	isDeletion.value
		? 'Name the wiki to delete.'
		: 'A block has to name at least one wiki, or it does nothing.'
) );

const wikiOptions = computed( () => knownWikis.value.map( ( w ) => ( {
	value: w.dbname,
	label: w.dbname,
	description: w.sitename
		? `${ w.sitename }${ w.closed ? ' — closed' : '' }`
		: ( w.closed ? 'closed' : undefined )
} ) ) );

const accountOptions = computed( () => props.accounts.map( ( a ) => ( {
	value: a.id,
	label: a.role && a.role !== 'subject' ? `${ a.username } (${ a.role })` : a.username
} ) ) );

const fileOptions = computed( () => props.files.map( ( f ) => ( {
	value: f.reference,
	label: `${ f.reference } — ${ f.title }`
} ) ) );

const subtitleText = computed( () => (
	props.investigation
		? `Out of ${ props.investigation.reference } — ${ props.investigation.title }`
		: 'Recorded against an investigation, so that the decision has a document behind it.'
) );

const canSubmit = computed( () => (
	form.reason.trim().length > 0
	&& ( !needsAccount.value || form.subject_id !== null )
	&& ( !needsWikis.value || form.wikis.length > 0 )
	&& ( !isCustom.value || form.label.trim().length > 0 )
	&& ( props.investigation?.reference ?? form.investigation_reference ) !== null
) );

watch( () => props.open, ( isOpen ) => {
	if ( !isOpen ) {
		return;
	}

	form.subject_id = props.accounts.length === 1 ? props.accounts[ 0 ].id : null;
	form.investigation_reference = props.investigation?.reference
		?? ( props.files.length === 1 ? props.files[ 0 ].reference : null );
	form.type = 'warning';
	form.reason = '';
	form.internal_reason = '';
	form.expires_at = '';
	form.appealable = true;
	form.wikis = [];
	form.label = '';
	wikiChips.value = [];

	loadWikis();
} );

watch( () => form.type, () => {
	form.wikis = [];
	wikiChips.value = [];
	if ( needsWikis.value ) {
		loadWikis();
	}
} );

async function loadWikis() {
	if ( !needsWikis.value ) {
		return;
	}

	try {
		const response = await api.wikis( { for: isDeletion.value ? 'closeable' : 'actionable' } );
		knownWikis.value = response.data;
		wikisKnown.value = response.synced;
	} catch ( e ) {
		knownWikis.value = [];
		wikisKnown.value = false;
	}
}

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

async function submit() {
	saving.value = true;

	const body = {
		type: form.type,
		label: isCustom.value ? form.label : null,
		reason: form.reason,
		internal_reason: form.internal_reason,
		expires_at: form.expires_at || null,
		appealable: form.appealable,
		wikis: needsWikis.value ? form.wikis : null,
		investigation_reference: props.investigation?.reference ?? form.investigation_reference,
		case_reference: props.caseReference
	};

	try {
		const response = needsAccount.value
			? await api.issueSanction( form.subject_id, body )
			: await api.issueAction( body );

		emit( 'update:open', false );
		emit( 'issued', response );

		notify(
			response.needs_manual_action
				? `Recorded as ${ response.reference }. The wiki has not carried it out — somebody has to.`
				: `Done. ${ response.reference } is on the wiki.`,
			response.needs_manual_action ? 'warning' : 'success'
		);
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		saving.value = false;
	}
}
</script>
