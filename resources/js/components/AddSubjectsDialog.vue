<template>
	<cdx-dialog
		:open="open"
		title="Name several accounts"
		subtitle="Paste a list. One per line, or separated by commas."
		:use-close-button="true"
		:primary-action="{
			label: saving ? 'Adding…' : addLabel,
			actionType: 'progressive',
			disabled: !names.length || saving
		}"
		:default-action="{ label: 'Cancel' }"
		@update:open="$emit( 'update:open', $event )"
		@primary="submit"
		@default="$emit( 'update:open', false )"
	>
		<div class="ts-stack">
			<cdx-field>
				<template #label>The accounts</template>
				<template #description>
					<code>User:</code> prefixes, wiki links and bullets are stripped. Underscores
					become spaces, as they do on the farm.
				</template>
				<cdx-text-area
					v-model="text"
					rows="7"
					:disabled="saving"
					placeholder="Quiet Marlin&#10;* [[User:Halcyon Reed]]&#10;Sandpiper_99"
				/>
			</cdx-field>

			<cdx-field>
				<template #label>How are they linked?</template>
				<template #description>The same for all of them. Change any of them afterwards.</template>
				<cdx-select v-model:selected="role" :menu-items="roleOptions" :disabled="saving" />
			</cdx-field>

			<cdx-field>
				<template #label>Note</template>
				<template #description>Optional. Kept against each of them on this file.</template>
				<cdx-text-input
					v-model="note"
					:disabled="saving"
					placeholder="From the sockpuppet report"
				/>
			</cdx-field>

			<div v-if="names.length" class="ts-panel">
				<p class="ts-meta">{{ names.length }} name{{ names.length === 1 ? '' : 's' }} read from that:</p>
				<div class="ts-inline">
					<cdx-info-chip v-for="( name, i ) in shown" :key="`${ name }-${ i }`">
						{{ name }}
					</cdx-info-chip>
					<span v-if="names.length > shown.length" class="ts-meta">
						and {{ names.length - shown.length }} more
					</span>
				</div>
			</div>

			<cdx-message
				v-else-if="text.trim()"
				type="warning"
				:inline="true"
				:allow-user-dismiss="false"
			>
				No account names could be read from that.
			</cdx-message>

			<cdx-message v-if="skipped.length" type="notice" :allow-user-dismiss="false">
				<p>{{ skipped.length }} were left out:</p>
				<ul>
					<li v-for="row in skipped" :key="row.name">{{ row.name }} — {{ row.why }}</li>
				</ul>
			</cdx-message>
		</div>
	</cdx-dialog>
</template>

<script setup>
import { computed, inject, ref, watch } from 'vue';
import {
	CdxDialog, CdxField, CdxInfoChip, CdxMessage, CdxSelect, CdxTextArea, CdxTextInput
} from '@wikimedia/codex';
import { api } from '../lib/api.js';
import { SUBJECT_ROLES } from '../lib/format.js';

const props = defineProps( {
	open: { type: Boolean, default: false },
	investigationId: { type: [ String, Number ], required: true }
} );

const emit = defineEmits( [ 'update:open', 'added' ] );
const notify = inject( 'notify' );

const text = ref( '' );
const role = ref( 'subject' );
const note = ref( '' );
const names = ref( [] );
const skipped = ref( [] );
const saving = ref( false );

const roleOptions = SUBJECT_ROLES;

const shown = computed( () => names.value.slice( 0, 30 ) );

const addLabel = computed( () => (
	`Name ${ names.value.length } account${ names.value.length === 1 ? '' : 's' }`
) );

watch( () => props.open, ( isOpen ) => {
	if ( isOpen ) {
		text.value = '';
		role.value = 'subject';
		note.value = '';
		names.value = [];
		skipped.value = [];
	}
} );

let timer = null;
let inFlight = null;

watch( text, ( value ) => {
	clearTimeout( timer );

	if ( !value.trim() ) {
		names.value = [];
		return;
	}

	timer = setTimeout( async () => {
		inFlight?.abort();
		inFlight = new AbortController();

		try {
			const response = await api.previewSubjectNames( value, { signal: inFlight.signal } );
			names.value = response.data;
		} catch ( e ) {
			if ( e.name !== 'AbortError' ) {
				names.value = [];
			}
		}
	}, 250 );
} );

async function submit() {
	saving.value = true;
	skipped.value = [];

	try {
		const response = await api.addInvestigationSubjects( props.investigationId, {
			text: text.value,
			role: role.value,
			note: note.value || null
		} );

		skipped.value = response.skipped;

		emit( 'added', response );

		notify(
			response.added.length
				? `${ response.added.length } named on the file.`
				: 'Nothing new to add — they were all on the file already.',
			response.added.length ? 'success' : 'warning'
		);

		if ( !response.skipped.length ) {
			emit( 'update:open', false );
		} else {
			text.value = '';
			names.value = [];
		}
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		saving.value = false;
	}
}
</script>
