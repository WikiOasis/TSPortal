<template>
	<cdx-dialog
		:open="open"
		class="ts-ofile"
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
		<div class="ts-ofile__grid">
			<section class="ts-ofile__side" aria-label="Details">
				<cdx-message v-if="fromCase" type="notice" :allow-user-dismiss="false" :inline="true">
					<span class="ts-mono">{{ fromCase.reference }}</span> will be attached, and the person who
					filed it told it is under investigation.
				</cdx-message>

				<cdx-field>
					<template #label>Linked accounts</template>
					<template #description>Accounts involved, and how each one is linked.</template>

					<div class="ts-ofile__accounts">
						<div v-for="( row, i ) in accounts" :key="i" class="ts-ofile__account">
							<cdx-text-input
								v-model="row.username"
								placeholder="Account name"
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
								aria-label="Remove this account"
								@click="removeRow( i )"
							>
								<cdx-icon :icon="cdxIconClose" />
							</cdx-button>
						</div>

						<div>
							<cdx-button @click="addRow">
								<cdx-icon :icon="cdxIconAdd" />
								Add another account
							</cdx-button>
						</div>
					</div>
				</cdx-field>

				<cdx-field :is-fieldset="true">
					<template #label>Priority</template>
					<cdx-toggle-button-group v-model="form.priority" :buttons="priorityOptions" />
				</cdx-field>

				<cdx-field>
					<template #label>Look again on</template>
					<template #description>Optional. Brings a quiet file back.</template>
					<DatePicker v-model="form.review_at" aria-label="Look again on" :min="today" />
				</cdx-field>

				<cdx-checkbox v-model="form.assign_to_me">Assign it to me</cdx-checkbox>
			</section>

			<section class="ts-ofile__main" aria-label="What this is about">
				<cdx-field :status="touched && !canSubmit ? 'error' : 'default'" :messages="{ error: 'Give the file a name somebody can recognise later.' }">
					<template #label>What is this about</template>
					<cdx-text-input
						v-model="form.title"
						class="ts-ofile__title"
						placeholder="Name this investigation"
						@keydown.enter.prevent="focusEditor"
					/>
				</cdx-field>

				<MarkdownEditor
					ref="editor"
					v-model="form.premise"
					class="ts-ofile__editor"
					:rows="18"
					:upload="upload"
					label="Why was it opened"
					placeholder="Why this was opened: what you saw, who raised it, what you want to find out. Drop in pictures as you go."
				/>
			</section>
		</div>
	</cdx-dialog>
</template>

<script setup>
import { computed, inject, nextTick, reactive, ref, watch } from 'vue';
import {
	CdxButton, CdxCheckbox, CdxDialog, CdxField, CdxIcon, CdxMessage, CdxSelect,
	CdxTextInput, CdxToggleButtonGroup
} from '@wikimedia/codex';
import { cdxIconAdd, cdxIconClose } from '@wikimedia/codex-icons';
import DatePicker from './DatePicker.vue';
import MarkdownEditor from './MarkdownEditor.vue';
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
const editor = ref( null );

const accounts = ref( [ blankRow() ] );

function blankRow() {
	return { username: '', role: 'subject' };
}

const form = reactive( {
	title: '',
	premise: '',
	priority: 'normal',
	review_at: '',
	assign_to_me: true
} );

const roleOptions = SUBJECT_ROLES;
const priorityOptions = PRIORITIES.slice().reverse();

const pad = ( n ) => String( n ).padStart( 2, '0' );
const now = new Date();
const today = `${ now.getFullYear() }-${ pad( now.getMonth() + 1 ) }-${ pad( now.getDate() ) }`;

const canSubmit = computed( () => form.title.trim().length > 0 );

const upload = ( file ) => api.uploadStagedImage( file );

watch( () => props.open, ( isOpen ) => {
	if ( !isOpen ) {
		return;
	}

	touched.value = false;
	form.title = props.fromCase ? `Opened from ${ props.fromCase.reference }` : '';
	form.premise = '';
	form.priority = props.fromCase?.priority ?? 'normal';
	form.review_at = '';
	form.assign_to_me = true;

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
	} else {
		accounts.value[ 0 ] = blankRow();
	}
}

function focusEditor() {
	nextTick( () => document.querySelector( '.ts-ofile .ts-mde__field' )?.focus() );
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
			assign_to_me: form.assign_to_me,
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
