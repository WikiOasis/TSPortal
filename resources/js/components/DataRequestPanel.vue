<template>
	<section class="ts-section">
		<h2 class="ts-section__title">The request</h2>

		<div class="ts-panel ts-stack">
			<cdx-field
				:status="request.kind ? 'default' : 'warning'"
				:messages="{ warning: 'What data is being requested?' }"
			>
				<template #label>What is being asked for</template>
				<cdx-select
					v-model:selected="kindDraft"
					:menu-items="dataKinds"
					:disabled="decided"
					@update:selected="changeKind"
				/>
			</cdx-field>

			<template v-if="decided">
				<div class="ts-inline">
					<StatusChip kind="data-decision" :value="request.decision" />
					<span class="ts-meta">
						{{ request.decided_by || 'somebody' }} · {{ ago( request.decided_at ) }}
					</span>
				</div>

				<p v-if="request.note" class="ts-prose">{{ request.note }}</p>

				<cdx-message
					v-if="request.still_owed && request.kind !== 'erasure'"
					type="warning"
					:allow-user-dismiss="false"
				>
					Approved, still open. This is marked done when what was agreed to has
					actually been done, not when it was agreed to.
				</cdx-message>
			</template>

			<template v-else>
				<cdx-field>
					<template #label>Message (shown to user)</template>
					<cdx-text-area
						v-model="note"
						rows="3"
						:placeholder="notePlaceholder"
					/>
				</cdx-field>

				<cdx-checkbox
					v-if="request.kind === 'erasure'"
					v-model="startErasure"
				>
					Start the erasure as soon as this is approved
				</cdx-checkbox>

				<cdx-message
					v-if="request.kind === 'erasure' && startErasure"
					type="error"
					:allow-user-dismiss="false"
				>
					Approving will rename <strong>{{ account }}</strong> across every wiki it is
					attached to and remove the personal data linked to it. This cannot be undone.
				</cdx-message>

				<div class="ts-inline">
					<cdx-button
						action="progressive"
						weight="primary"
						:disabled="!canDecide || busy"
						@click="approve"
					>
						{{ busy === 'approve' ? 'Approving…' : 'Approve' }}
					</cdx-button>
					<cdx-button
						action="destructive"
						:disabled="!canDecide || busy"
						@click="decline"
					>
						{{ busy === 'decline' ? 'Declining…' : 'Decline' }}
					</cdx-button>
				</div>
			</template>
		</div>
	</section>
</template>

<script setup>
import { computed, inject, ref, watch } from 'vue';
import {
	CdxButton, CdxCheckbox, CdxField, CdxMessage, CdxSelect, CdxTextArea
} from '@wikimedia/codex';
import StatusChip from './StatusChip.vue';
import { api } from '../lib/api.js';
import { ago, DATA_KINDS } from '../lib/format.js';

const props = defineProps( {
	caseId: { type: [ String, Number ], required: true },
	request: { type: Object, required: true },
	account: { type: String, default: null }
} );

const emit = defineEmits( [ 'changed' ] );
const notify = inject( 'notify' );

const dataKinds = DATA_KINDS;

const kindDraft = ref( props.request.kind );
const note = ref( '' );
const startErasure = ref( true );
const busy = ref( null );

const decided = computed( () => props.request.decision !== null && props.request.decision !== undefined );
const canDecide = computed( () => !!kindDraft.value && note.value.trim().length > 0 );

const notePlaceholder = computed( () => (
	kindDraft.value === 'erasure'
		? 'We will remove what we hold about your account and write to you when it is done.'
		: 'We are looking at this and will write to you when it is done.'
) );

watch( () => props.request.kind, ( kind ) => {
	kindDraft.value = kind;
} );

async function changeKind( value ) {
	if ( !value || value === props.request.kind ) {
		return;
	}

	try {
		await api.setDataKind( props.caseId, { kind: value } );
		emit( 'changed' );
	} catch ( e ) {
		kindDraft.value = props.request.kind;
		notify( e.message, 'error' );
	}
}

async function approve() {
	busy.value = 'approve';

	try {
		const response = await api.approveDataRequest( props.caseId, {
			note: note.value,
			start: startErasure.value
		} );

		note.value = '';
		emit( 'changed' );

		notify(
			response.erasure
				? `Approved. ${ response.erasure.reference } — the rename is being sent.`
				: 'Approved, and they have been notified. Nothing has been executed yet.'
		);
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		busy.value = null;
	}
}

async function decline() {
	busy.value = 'decline';

	try {
		await api.declineDataRequest( props.caseId, { reason: note.value } );
		note.value = '';
		emit( 'changed' );
		notify( 'Declined, and the reason has been sent to them.' );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		busy.value = null;
	}
}
</script>
