<template>
	<cdx-dialog
		:open="open"
		title="Conclude this appeal"
		:use-close-button="true"
		:primary-action="{ label: primaryLabel, actionType: outcome === 'declined' ? 'destructive' : 'progressive', disabled: !ready || busy }"
		:default-action="{ label: 'Cancel' }"
		@update:open="emit( 'update:open', $event )"
		@primary="submit"
		@default="emit( 'update:open', false )"
	>
		<div class="ts-stack">
			<p v-if="action" class="ts-conclude-appeal__against">
				Against
				<span class="ts-mono">{{ action.reference }}</span>
				— {{ action.label }}
				<StatusChip kind="force" :value="action.in_force ? 'in-force' : 'lifted'" />
			</p>
			<p v-else class="ts-meta">
				Not attached to an action, so it can be declined or closed but not granted.
			</p>

			<cdx-field :is-fieldset="true">
				<template #label>How should it end?</template>
				<div class="ts-conclude-appeal__choices">
					<cdx-radio
						v-for="choice in choices"
						:key="choice.value"
						v-model="outcome"
						name="appeal-outcome"
						:input-value="choice.value"
						:disabled="choice.disabled"
						:class="{ 'ts-conclude-appeal__choice--on': outcome === choice.value }"
						class="ts-conclude-appeal__choice"
					>
						{{ choice.label }}
						<template #description>{{ choice.description }}</template>
					</cdx-radio>
				</div>
			</cdx-field>

			<template v-if="outcome">
				<cdx-field>
					<template #label>What they will be told</template>
					<template v-if="anonymous" #description>
						Filed anonymously, so this is kept on the record but cannot reach them.
					</template>
					<cdx-text-area v-model="note" rows="4" :placeholder="placeholder" />
				</cdx-field>

				<template v-if="outcome === 'granted' && action && action.in_force">
					<cdx-checkbox v-model="lift">
						Lift {{ action.reference }} now
					</cdx-checkbox>
					<cdx-message v-if="!lift" type="warning" :allow-user-dismiss="false">
						The record will say they were vindicated while {{ action.reference }} is still in force.
						Only leave this unticked if it was already lifted somewhere else.
					</cdx-message>
				</template>

				<p v-if="outcome === 'partly-granted' && action && action.in_force" class="ts-meta">
					{{ action.reference }} stays as it is. Change or replace it from the action page afterwards.
				</p>
			</template>
		</div>
	</cdx-dialog>
</template>

<script setup>
import { computed, inject, ref, watch } from 'vue';
import {
	CdxCheckbox, CdxDialog, CdxField, CdxMessage, CdxRadio, CdxTextArea
} from '@wikimedia/codex';
import StatusChip from './StatusChip.vue';
import { api } from '../lib/api.js';

const props = defineProps( {
	open: { type: Boolean, default: false },
	caseId: { type: [ String, Number ], required: true },
	appeal: { type: Object, required: true },
	anonymous: { type: Boolean, default: false }
} );

const emit = defineEmits( [ 'update:open', 'concluded' ] );
const notify = inject( 'notify' );

const action = computed( () => props.appeal.action ?? null );

const outcome = ref( null );
const note = ref( '' );
const lift = ref( true );
const busy = ref( false );

watch( () => props.open, ( value ) => {
	if ( value ) {
		lift.value = true;
	}
} );

const choices = computed( () => [
	{
		value: 'granted',
		label: 'Grant it',
		description: action.value?.in_force
			? `The action was wrong. ${ action.value.reference } is lifted.`
			: 'The action was wrong.',
		disabled: !action.value
	},
	{
		value: 'partly-granted',
		label: 'Grant part of it',
		description: 'Some of it was wrong, some of it stands.',
		disabled: !action.value
	},
	{
		value: 'declined',
		label: 'Decline it',
		description: 'The action was right and stays in place.'
	},
	{
		value: 'withdrawn',
		label: 'They withdrew it',
		description: 'Recorded, but not counted for or against the action.'
	},
	{
		value: 'invalid',
		label: 'Nothing to appeal',
		description: 'Not an appeal of anything real. Not counted either way.'
	}
] );

const PLACEHOLDERS = {
	granted: 'We have looked at this again and agree with you. The action has been lifted.',
	'partly-granted': 'We have looked at this again. Part of the action has been changed…',
	declined: 'We have looked at this again, and the action stands because…',
	withdrawn: 'Thanks for letting us know. We have closed your appeal.',
	invalid: 'We could not find an action on your account to appeal…'
};

const placeholder = computed( () => PLACEHOLDERS[ outcome.value ] ?? '' );

const ready = computed( () => !!outcome.value && note.value.trim().length > 0 );

const primaryLabel = computed( () => {
	if ( busy.value ) {
		return 'Recording…';
	}
	switch ( outcome.value ) {
		case 'granted':
			return lift.value && action.value?.in_force ? `Grant and lift ${ action.value.reference }` : 'Grant the appeal';
		case 'partly-granted':
			return 'Grant part of it';
		case 'declined':
			return 'Decline the appeal';
		case 'withdrawn':
			return 'Close as withdrawn';
		case 'invalid':
			return 'Close as nothing to appeal';
		default:
			return 'Conclude';
	}
} );

async function submit() {
	if ( !ready.value ) {
		return;
	}

	busy.value = true;

	try {
		const response = await api.decideAppeal( props.caseId, {
			outcome: outcome.value,
			note: note.value,
			lift: outcome.value === 'granted' && lift.value
		} );

		outcome.value = null;
		note.value = '';
		emit( 'update:open', false );
		emit( 'concluded' );

		notify(
			response.lifted
				? `Concluded, and ${ response.lifted.reference } is being lifted.`
				: 'Concluded, and they have been told.'
		);
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		busy.value = false;
	}
}
</script>
