<template>
	<div class="ts-composer" :class="`ts-composer--${ kind === 'case' ? mode : 'file' }`">
		<div v-if="kind === 'investigation'" class="ts-composer__kinds" role="radiogroup" aria-label="What this is">
			<button
				v-for="k in NOTE_KINDS"
				:key="k.value"
				type="button"
				role="radio"
				class="ts-composer__kind"
				:aria-checked="noteKind === k.value"
				@click="noteKind = k.value"
			>
				{{ k.label }}
			</button>
		</div>

		<div v-else class="ts-composer__modes" role="radiogroup" aria-label="Who sees this">
			<button
				type="button"
				role="radio"
				class="ts-composer__mode ts-composer__mode--internal"
				:aria-checked="mode === 'internal'"
				@click="mode = 'internal'"
			>
				<cdx-icon :icon="cdxIconLock" size="small" />
				<span>
					<strong>Internal note</strong>
				</span>
			</button>
			<button
				type="button"
				role="radio"
				class="ts-composer__mode ts-composer__mode--public"
				:aria-checked="mode === 'public'"
				:disabled="!!publicBlocked"
				:title="publicBlocked || undefined"
				@click="mode = 'public'"
			>
				<cdx-icon :icon="cdxIconEye" size="small" />
				<span>
					<strong>Reply to {{ reporterName }}</strong>
				</span>
			</button>
		</div>

		<MarkdownEditor
			ref="editor"
			v-model="body"
			:rows="4"
			:upload="mode === 'internal' ? upload : null"
			:label="kind === 'investigation' ? 'Add to the file' : mode === 'internal' ? 'Internal note' : 'Reply to the reporter'"
			:placeholder="kind === 'investigation'
				? 'What you found, who you spoke to, what you decided and why.'
				: mode === 'internal'
					? 'Write a note for the team. Only staff will see it.'
					: `Write to ${ reporterName }. They will see this exactly as written.`"
			@submit="submit"
		/>

		<p v-if="mode === 'public'" class="ts-composer__warn">
			<cdx-icon :icon="cdxIconAlert" size="x-small" />
			This goes to the reporter's Special:SafetyHome and notifies them.
		</p>

		<ul v-if="actions.length" class="ts-composer__actions">
			<li v-for="( action, i ) in actions" :key="action.type" class="ts-composer__action">
				<span class="ts-composer__action-label">{{ ACTIONS[ action.type ].label }}</span>
				<cdx-text-input
					v-if="ACTIONS[ action.type ].date"
					v-model="action.value"
					input-type="date"
					class="ts-composer__action-input"
				/>
				<cdx-select
					v-else-if="ACTIONS[ action.type ].options"
					v-model:selected="action.value"
					:menu-items="ACTIONS[ action.type ].options()"
					class="ts-composer__action-input"
				/>
				<span v-else class="ts-meta ts-composer__action-input">It will be with you.</span>
				<button type="button" class="ts-composer__x" :aria-label="`Remove ${ ACTIONS[ action.type ].label }`" @click="actions.splice( i, 1 )">
					×
				</button>
			</li>
		</ul>

		<div class="ts-composer__foot">
			<cdx-menu-button
				v-model:selected="adding"
				:menu-items="addable"
				:disabled="!addable.length"
				@update:selected="addAction"
			>
				<cdx-icon :icon="cdxIconAdd" size="small" />
				Add action
			</cdx-menu-button>

			<span class="ts-composer__spacer" />
			<span class="ts-meta ts-composer__kbd">Ctrl + Enter</span>

			<cdx-button
				v-if="hasText && changes.length"
				weight="quiet"
				:disabled="busy"
				title="Apply the changes and keep what you have written for later"
				@click="submit( { changesOnly: true } )"
			>
				Apply changes only
			</cdx-button>

			<cdx-button
				:action="mode === 'public' ? 'progressive' : 'default'"
				weight="primary"
				:disabled="!ready || busy"
				@click="submit"
			>
				{{ busy ? 'Saving…' : submitLabel }}
			</cdx-button>
		</div>
	</div>
</template>

<script setup>
import { computed, inject, ref, watch } from 'vue';
import { CdxButton, CdxIcon, CdxMenuButton, CdxSelect, CdxTextInput } from '@wikimedia/codex';
import { cdxIconAdd, cdxIconAlert, cdxIconEye, cdxIconLock } from '@wikimedia/codex-icons';
import MarkdownEditor from './MarkdownEditor.vue';
import { api } from '../lib/api.js';
import { hasImages } from '../lib/inlineImages.js';
import { NOTE_KINDS, PRIORITIES } from '../lib/format.js';
import { session } from '../lib/session.js';

const props = defineProps( {
	kind: { type: String, default: 'case' },
	item: { type: Object, required: true },
	team: { type: Array, default: () => [] }
} );
const emit = defineEmits( [ 'done' ] );

const notify = inject( 'notify' );
const editor = ref( null );
const busy = ref( false );
const adding = ref( null );
const actions = ref( [] );

const draftKey = computed( () => `ts-draft-${ props.kind }-${ props.item.id }` );
const body = ref( readDraft() );
// Investigations are internal by nature; only a report has someone to reply to.
const mode = ref( 'internal' );
const noteKind = ref( 'note' );

function readDraft() {
	try {
		return localStorage.getItem( `ts-draft-${ props.kind }-${ props.item.id }` ) ?? '';
	} catch ( e ) {
		return '';
	}
}

watch( body, ( value ) => {
	try {
		if ( value ) {
			localStorage.setItem( draftKey.value, value );
		} else {
			localStorage.removeItem( draftKey.value );
		}
	} catch ( e ) {

	}
} );

const upload = ( file ) => ( props.kind === 'case'
	? api.uploadCaseImage( props.item.id, file )
	: api.uploadInvestigationImage( props.item.id, file ) );

const reporterName = computed( () => props.item.reporter?.username ?? 'the reporter' );

const publicBlocked = computed( () => {
	if ( props.item.anonymous ) {
		return 'Filed anonymously';
	}
	if ( !props.item.reporter ) {
		return 'Nobody to reply to';
	}
	if ( hasImages( body.value ) ) {
		return 'Pictures are internal only';
	}
	return null;
} );

watch( publicBlocked, ( blocked ) => {
	if ( blocked && mode.value === 'public' ) {
		mode.value = 'internal';
	}
} );

const STATUSES = {
	case: [
		{ value: 'received', label: 'Received' },
		{ value: 'in-review', label: 'Being read' },
		{ value: 'investigating', label: 'Under investigation' },
		{ value: 'action-taken', label: 'Action taken' },
		{ value: 'closed', label: 'Closed' },
		{ value: 'rejected', label: 'Closed, no action' }
	],
	investigation: [
		{ value: 'open', label: 'Open' },
		{ value: 'monitoring', label: 'Monitoring' }
	]
};

const reviewDate = () => ( props.item.review_at ? props.item.review_at.slice( 0, 10 ) : '' );

const ACTIONS = {
	status: {
		label: props.kind === 'case' ? 'Change status' : 'Change state',
		options: () => STATUSES[ props.kind ],
		initial: () => ( props.item.status === 'duplicate' ? null : props.item.status ),
		hidden: () => props.kind === 'investigation' && !props.item.live
	},
	claim: {
		label: 'Take this',
		options: null,
		initial: () => null,
		hidden: () => props.item.assignee?.id === session.user?.id
	},
	assign: {
		label: 'Assign to',
		options: () => [
			{ value: null, label: 'Nobody' },
			...props.team.map( ( u ) => ( { value: u.id, label: u.username } ) )
		],
		initial: () => props.item.assignee?.id ?? null
	},
	priority: {
		label: 'Change priority',
		options: () => PRIORITIES,
		initial: () => props.item.priority
	},
	review: {
		label: 'Look again on',
		date: true,
		initial: reviewDate,
		hidden: () => props.kind !== 'investigation'
	}
};

const addable = computed( () => Object.entries( ACTIONS )
	.filter( ( [ type, def ] ) => !actions.value.some( ( a ) => a.type === type ) && !def.hidden?.() )
	.filter( ( [ type ] ) => !( type === 'claim' && actions.value.some( ( a ) => a.type === 'assign' ) ) )
	.filter( ( [ type ] ) => !( type === 'assign' && actions.value.some( ( a ) => a.type === 'claim' ) ) )
	.map( ( [ type, def ] ) => ( { value: type, label: def.label } ) ) );

function addAction( type ) {
	adding.value = null;
	if ( type && ACTIONS[ type ] ) {
		actions.value.push( { type, value: ACTIONS[ type ].initial() } );
	}
}

const changes = computed( () => actions.value.filter( ( a ) => {
	switch ( a.type ) {
		case 'claim':
			return true;
		case 'status':
			return a.value && a.value !== props.item.status;
		case 'priority':
			return a.value && a.value !== props.item.priority;
		case 'assign':
			return a.value !== ( props.item.assignee?.id ?? null );
		case 'review':
			return ( a.value || '' ) !== reviewDate();
	}
	return false;
} ) );

const hasText = computed( () => body.value.trim() !== '' );
const ready = computed( () => hasText.value || changes.value.length > 0 );

const submitLabel = computed( () => {
	const n = changes.value.length;
	if ( !hasText.value && n ) {
		return n === 1 ? 'Apply change' : `Apply ${ n } changes`;
	}
	const base = props.kind === 'investigation'
		? `Save ${ NOTE_KINDS.find( ( k ) => k.value === noteKind.value )?.label.toLowerCase() ?? 'note' }`
		: mode.value === 'public' ? 'Send to reporter' : 'Save note';
	return n ? `${ base } + ${ n } change${ n === 1 ? '' : 's' }` : base;
} );

async function submit( { changesOnly = false } = {} ) {
	if ( !ready.value || busy.value ) {
		return;
	}
	const withText = hasText.value && !changesOnly;
	busy.value = true;
	try {
		const sent = changes.value.map( ( a ) => ( {
			type: a.type,
			value: a.type === 'review' ? ( a.value || null ) : a.value
		} ) );
		const response = props.kind === 'case'
			? await api.caseTransaction( props.item.id, {
				comment: withText ? body.value : null,
				visibility: withText ? mode.value : null,
				actions: sent
			} )
			: await api.investigationTransaction( props.item.id, {
				note: withText ? body.value : null,
				kind: noteKind.value,
				actions: sent
			} );

		const said = withText
			? ( mode.value === 'public' ? 'Sent to the reporter.' : props.kind === 'case' ? 'Note saved.' : 'Saved to the file.' )
			: 'Changes applied.';

		if ( withText ) {
			body.value = '';
		}
		actions.value = [];
		if ( withText ) {
			mode.value = 'internal';
			noteKind.value = 'note';
		}
		emit( 'done', response.data );
		notify( said );
	} catch ( e ) {
		notify( e.errors ? Object.values( e.errors ).flat()[ 0 ] : e.message, 'error' );
	} finally {
		busy.value = false;
	}
}
</script>
