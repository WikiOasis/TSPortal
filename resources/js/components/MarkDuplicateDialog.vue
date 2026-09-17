<template>
	<cdx-dialog
		:open="open"
		title="Merge as a duplicate"
		:subtitle="`${ report ? report.reference : 'This report' } will be closed and answered on the one it duplicates.`"
		:use-close-button="true"
		:primary-action="{
			label: saving ? 'Merging…' : 'Merge and close it',
			actionType: 'destructive',
			disabled: !chosen || saving
		}"
		:default-action="{ label: 'Cancel' }"
		@update:open="$emit( 'update:open', $event )"
		@primary="submit"
		@default="$emit( 'update:open', false )"
	>
		<div class="ts-stack">
			<cdx-field>
				<template #label>Which report does it duplicate</template>
				<template #description>By reference, or pick one below.</template>
				<div class="ts-inline">
					<cdx-text-input
						v-model="reference"
						placeholder="TS-2026-0481"
						:disabled="saving"
						@update:model-value="onTyped"
					/>
					<cdx-button :disabled="!reference.trim() || looking" @click="lookUp">
						Find it
					</cdx-button>
				</div>
			</cdx-field>

			<cdx-message
				v-if="chosen"
				type="success"
				:inline="true"
				:allow-user-dismiss="false"
			>
				Merging into <span class="ts-mono">{{ chosen.reference }}</span> — {{ chosen.subject }}
			</cdx-message>

			<div v-if="!chosen">
				<h3 class="ts-section__title">Reports that look related</h3>

				<cdx-progress-bar v-if="loading" aria-label="Looking for related reports" />

				<p v-else-if="!candidates.length" class="ts-meta">
					Nothing else on file looks related. Give a reference above.
				</p>

				<div v-else class="ts-panel ts-stack">
					<button
						v-for="row in candidates"
						:key="row.id"
						type="button"
						class="ts-pick-row"
						@click="pick( row )"
					>
						<span>
							<span class="ts-mono">{{ row.reference }}</span>
							<span class="ts-row__title">{{ row.subject }}</span>
							<span class="ts-meta ts-pick-row__why">
								{{ row.because }}<template v-if="row.accounts.length"> · {{ row.accounts.join( ', ' ) }}</template>
							</span>
						</span>
						<StatusChip kind="case" :value="row.status" />
					</button>
				</div>
			</div>

			<cdx-field>
				<template #label>Why it is a duplicate</template>
				<template #description>
					Internal. Goes on both reports, and never to whoever filed either of them.
				</template>
				<cdx-text-area
					v-model="note"
					rows="2"
					:disabled="saving"
					placeholder="Same incident, reported twice within an hour."
				/>
			</cdx-field>
		</div>
	</cdx-dialog>
</template>

<script setup>
import { inject, ref, watch } from 'vue';
import {
	CdxButton, CdxDialog, CdxField, CdxMessage, CdxProgressBar, CdxTextArea, CdxTextInput
} from '@wikimedia/codex';
import StatusChip from './StatusChip.vue';
import { api } from '../lib/api.js';

const props = defineProps( {
	open: { type: Boolean, default: false },
	report: { type: Object, default: null }
} );

const emit = defineEmits( [ 'update:open', 'merged' ] );
const notify = inject( 'notify' );

const reference = ref( '' );
const note = ref( '' );
const chosen = ref( null );
const candidates = ref( [] );
const loading = ref( false );
const looking = ref( false );
const saving = ref( false );

watch( () => props.open, ( isOpen ) => {
	if ( !isOpen ) {
		return;
	}

	reference.value = '';
	note.value = '';
	chosen.value = null;
	candidates.value = [];

	load();
} );

async function load() {
	loading.value = true;
	try {
		const response = await api.duplicateCandidates( props.report.id );
		candidates.value = response.data;
	} catch ( e ) {
		candidates.value = [];
	} finally {
		loading.value = false;
	}
}

function onTyped() {
	chosen.value = null;
}

function pick( row ) {
	chosen.value = row;
	reference.value = row.reference;
}

async function lookUp() {
	looking.value = true;
	try {
		const found = await api.object( reference.value.trim().toUpperCase() );

		if ( found.type !== 'SafetyCase' ) {
			notify( `${ found.reference } is a ${ found.type_label.toLowerCase() }, not a report.`, 'error' );
			return;
		}

		if ( found.id === props.report.id ) {
			notify( 'A report cannot be a duplicate of itself.', 'error' );
			return;
		}

		chosen.value = {
			id: found.id,
			reference: found.reference,
			subject: found.label ?? 'that report'
		};
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		looking.value = false;
	}
}

async function submit() {
	saving.value = true;
	try {
		const response = await api.markDuplicate( props.report.id, {
			of_id: chosen.value.id,
			note: note.value || null
		} );

		emit( 'update:open', false );
		emit( 'merged', response );

		notify( `Merged into ${ response.of }.` );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		saving.value = false;
	}
}
</script>
