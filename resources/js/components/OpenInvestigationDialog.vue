<template>
	<cdx-dialog
		:open="open"
		title="Open an investigation"
		subtitle="Internal. Nothing on an investigation is ever shown outside of here."
		:use-close-button="true"
		:primary-action="{
			label: 'Open the file',
			actionType: 'progressive',
			disabled: !canSubmit || saving
		}"
		:default-action="{ label: 'Cancel' }"
		@update:open="$emit( 'update:open', $event )"
		@primary="submit"
		@default="$emit( 'update:open', false )"
	>
		<div class="ts-stack">
			<cdx-field :status="titleStatus" :messages="{ error: 'Give the file a name somebody can recognise later.' }">
				<template #label>What is this about</template>
				<cdx-text-input
					v-model="form.title"
					placeholder="Copyright violations on ..."
					@keydown.enter.prevent
				/>
			</cdx-field>

			<cdx-field>
				<template #label>Why was it opened</template>
				<cdx-text-area v-model="form.premise" rows="3" />
			</cdx-field>

			<cdx-field>
				<template #label>Accounts linked</template>

				<div class="ts-stack">
					<div v-for="( row, i ) in accounts" :key="i" class="ts-account-row">
						<cdx-text-input
							v-model="row.username"
							placeholder="Example user"
							aria-label="Account name"
							@keydown.enter.prevent="addRow"
						/>
						<cdx-select
							v-model:selected="row.role"
							:menu-items="roleOptions"
							aria-label="How they are linked"
						/>
						<cdx-button
							weight="quiet"
							:disabled="accounts.length === 1"
							aria-label="Remove this account"
							@click="removeRow( i )"
						>
							<cdx-icon :icon="cdxIconClose" size="small" />
						</cdx-button>
					</div>

					<cdx-button @click="addRow">
						<cdx-icon :icon="cdxIconAdd" size="small" />
						Another account
					</cdx-button>
				</div>
			</cdx-field>

			<div class="ts-stack">
				<cdx-field>
					<template #label>Priority</template>
					<cdx-select v-model:selected="form.priority" :menu-items="priorityOptions" />
				</cdx-field>

				<cdx-field>
					<template #label>Look again on</template>
					<template #description>Optional. Brings a quiet case back.</template>
					<cdx-text-input v-model="form.review_at" input-type="date" />
				</cdx-field>
			</div>

			<cdx-message v-if="fromCase" type="notice" :allow-user-dismiss="false">
				<span class="ts-mono">{{ fromCase.reference }}</span> will be attached, and the opener
                will be notified it is under investigation.
			</cdx-message>
		</div>
	</cdx-dialog>
</template>

<script setup>
import { computed, inject, reactive, ref, watch } from 'vue';
import {
	CdxButton, CdxDialog, CdxField, CdxIcon, CdxMessage, CdxSelect,
	CdxTextArea, CdxTextInput
} from '@wikimedia/codex';
import { cdxIconAdd, cdxIconClose } from '@wikimedia/codex-icons';
import { api } from '../lib/api.js';
import { PRIORITIES, SUBJECT_ROLES } from '../lib/format.js';

const props = defineProps( {
	open: { type: Boolean, default: false },
	fromCase: { type: Object, default: null },
	subjects: { type: Array, default: () => [] }
} );

const emit = defineEmits( [ 'update:open', 'opened' ] );
const notify = inject( 'notify' );

const saving = ref( false );
const touched = ref( false );

const accounts = ref( [ blankRow() ] );

function blankRow() {
	return { username: '', role: 'subject' };
}

const form = reactive( {
	title: '',
	premise: '',
	priority: 'normal',
	review_at: ''
} );

const roleOptions = SUBJECT_ROLES;
const priorityOptions = PRIORITIES;

const canSubmit = computed( () => form.title.trim().length > 0 );
const titleStatus = computed( () => ( touched.value && !canSubmit.value ? 'error' : 'default' ) );

watch( () => props.open, ( isOpen ) => {
	if ( !isOpen ) {
		return;
	}

	touched.value = false;
	form.title = props.fromCase ? `Opened from ${ props.fromCase.reference }` : '';
	form.premise = '';
	form.priority = props.fromCase?.priority ?? 'normal';
	form.review_at = '';

	accounts.value = props.subjects.length
		? props.subjects.map( ( s ) => (
			typeof s === 'string'
				? { username: s, role: 'subject' }
				: { username: s.username ?? '', role: s.role ?? 'subject' }
		) ).concat( blankRow() )
		: [ blankRow() ];
} );

function addRow() {
	accounts.value.push( blankRow() );
}

function removeRow( i ) {
	if ( accounts.value.length > 1 ) {
		accounts.value.splice( i, 1 );
	}
}

async function submit() {
	touched.value = true;
	if ( !canSubmit.value ) {
		return;
	}

	saving.value = true;

	try {
		const investigation = await api.openInvestigation( {
			title: form.title,
			premise: form.premise || null,
			priority: form.priority,
			case_id: props.fromCase?.id ?? null,
			review_at: form.review_at || null,
			subjects: accounts.value
				.map( ( row ) => ( { username: row.username.trim(), role: row.role } ) )
				.filter( ( row ) => row.username !== '' )
		} );

		emit( 'update:open', false );
		emit( 'opened', investigation );
		notify( `Opened ${ investigation.reference }.` );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		saving.value = false;
	}
}
</script>
