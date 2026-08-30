<template>
	<section class="ts-section">
		<h2 class="ts-section__title">The appeal</h2>

		<div class="ts-panel ts-stack">
			<div v-if="action" class="ts-stack">
				<div class="ts-inline">
					<router-link :to="{ name: 'item', params: { reference: action.reference } }" class="ts-mono">
						{{ action.reference }}
					</router-link>
					<span>{{ action.label }}</span>
					<StatusChip v-if="appeal.link.confidence" kind="appeal-confidence" :value="appeal.link.confidence" />
				</div>

				<dl class="ts-dl">
					<div>
						<dt>Issued for</dt>
						<dd>{{ action.reason_category_label || 'No reason category recorded' }}</dd>
					</div>
					<div>
						<dt>Where</dt>
						<dd>{{ action.where }}</dd>
					</div>
					<div>
						<dt>Issued</dt>
						<dd>{{ date( action.issued ) }}</dd>
					</div>
					<div>
						<dt>Now</dt>
						<dd>
							{{ action.in_force ? 'In force' : 'Not in force' }}
							<template v-if="action.lifted"> · lifted {{ date( action.lifted ) }}</template>
						</dd>
					</div>
				</dl>
			</div>

			<cdx-message v-else type="warning" :allow-user-dismiss="false">
				This appeal is not attached to any action. It can be read and declined, but
				cannot be granted.
			</cdx-message>

			<div v-if="appeal.link.explanation" class="ts-meta">
				{{ appeal.link.explanation }}
				<template v-if="appeal.link.corrected">
					— {{ appeal.link.corrected.by }}, {{ ago( appeal.link.corrected.at ) }}
				</template>
			</div>

			<ul v-if="appeal.link.notes && appeal.link.notes.length" class="ts-meta">
				<li v-for="( note, i ) in appeal.link.notes" :key="i">{{ note }}</li>
			</ul>

			<cdx-message
				v-if="appeal.link.needs_checking && !decided"
				type="notice"
				:allow-user-dismiss="false"
			>
				Nobody has confirmed which action this is about. Please confirm below.
			</cdx-message>

			<cdx-field v-if="!decided && options.length">
				<template #label>Which action is this about?</template>
				<template #description>
					Their history, most recent first. “None of these” will unlink the report from an action if this is
                    not about something logged on TSPortal.
				</template>
				<cdx-select
					v-model:selected="linkDraft"
					:menu-items="linkOptions"
					:disabled="busy !== null"
					@update:selected="relink"
				/>
			</cdx-field>

			<hr>

			<template v-if="decided">
				<div class="ts-inline">
					<StatusChip kind="appeal-outcome" :value="appeal.outcome" />
					<span class="ts-meta">
						{{ appeal.decided_by || 'somebody' }} · {{ ago( appeal.decided_at ) }}
					</span>
				</div>

				<p v-if="appeal.note" class="ts-prose">{{ appeal.note }}</p>

				<cdx-message
					v-if="accepted && action && action.in_force"
					type="error"
					:allow-user-dismiss="false"
				>
					This appeal was accepted but <strong>{{ action.reference }} is still in force</strong>.
					Either the lifting failed or was never triggered. Lift it from the action page.
				</cdx-message>
			</template>

			<template v-else>
				<cdx-field>
					<template #label>Message (sent to user)</template>
					<cdx-text-area v-model="note" rows="3" :placeholder="placeholder" />
				</cdx-field>

				<cdx-checkbox v-if="action && action.in_force" v-model="lift">
					Lift {{ action.reference }} as soon as this is accepted
				</cdx-checkbox>

				<cdx-message
					v-if="action && action.in_force && !lift"
					type="warning"
					:allow-user-dismiss="false"
				>
					Accepting without lifting it leaves the record saying this person was
					vindicated while {{ action.reference }} is still in force against them.
					Only leave this unticked if it has already been lifted somewhere else.
				</cdx-message>

				<div class="ts-inline">
					<cdx-button
						action="progressive"
						weight="primary"
						:disabled="!canDecide || !action || busy !== null"
						@click="decide( 'granted' )"
					>
						{{ busy === 'granted' ? 'Accepting…' : 'Accept the appeal' }}
					</cdx-button>
					<cdx-button
						action="destructive"
						:disabled="!canDecide || busy !== null"
						@click="decide( 'declined' )"
					>
						{{ busy === 'declined' ? 'Declining…' : 'Decline' }}
					</cdx-button>
				</div>

				<p v-if="!action" class="ts-meta">
					An appeal can be declined without knowing what it is about, but it cannot be
					accepted — there would be nothing to lift, and it would be counted in the
					overall acceptance rate while appearing in no category of it. Say which action
					it is above first.
				</p>

				<details class="ts-details">
					<summary>It ended some other way</summary>
					<div class="ts-inline">
						<cdx-button
							v-for="other in otherOutcomes"
							:key="other.value"
							:disabled="!canDecide || busy !== null || ( other.value === 'partly-granted' && !action )"
							@click="decide( other.value )"
						>
							{{ busy === other.value ? 'Recording…' : other.label }}
						</cdx-button>
					</div>
					<p class="ts-meta">
						Withdrawn and “nothing to appeal” are recorded but left out of the
						acceptance rate: neither says anything about whether the original
						action was right.
					</p>
				</details>
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
import { ago, date, APPEAL_OUTCOMES } from '../lib/format.js';

const props = defineProps( {
	caseId: { type: [ String, Number ], required: true },
	appeal: { type: Object, required: true }
} );

const emit = defineEmits( [ 'changed' ] );
const notify = inject( 'notify' );

const otherOutcomes = APPEAL_OUTCOMES.filter(
	( o ) => ![ 'granted', 'declined' ].includes( o.value )
);

const action = computed( () => props.appeal.action ?? null );
const options = computed( () => props.appeal.options ?? [] );
const decided = computed( () => !!props.appeal.outcome );
const accepted = computed( () => [ 'granted', 'partly-granted' ].includes( props.appeal.outcome ) );

const note = ref( '' );
const lift = ref( true );
const busy = ref( null );
const linkDraft = ref( action.value ? action.value.reference : '' );

watch( action, ( value ) => {
	linkDraft.value = value ? value.reference : '';
} );

const linkOptions = computed( () => [
	{ value: '', label: 'None of these' },
	...options.value.map( ( o ) => ( {
		value: o.reference,
		label: `${ o.reference } — ${ o.label }${ o.in_force ? '' : ' (not in force)' }`
	} ) )
] );

const canDecide = computed( () => note.value.trim().length > 0 );

const placeholder = computed( () => ( action.value && !action.value.in_force ?
	'We have looked at this again and the action has been lifted. Your account works from now.' :
	'We have looked at this again, and…'
) );

async function relink( reference ) {
	const current = action.value ? action.value.reference : '';
	if ( reference === current ) {
		return;
	}

	busy.value = 'link';

	try {
		await api.linkAppealAction( props.caseId, reference || null );
		emit( 'changed' );
		notify( reference ? `This appeal is now against ${ reference }.` : 'This appeal is no longer attached to an action.' );
	} catch ( e ) {
		linkDraft.value = current;
		notify( e.message, 'error' );
	} finally {
		busy.value = null;
	}
}

async function decide( outcome ) {
	busy.value = outcome;

	try {
		const response = await api.decideAppeal( props.caseId, {
			outcome: outcome,
			note: note.value,
			lift: lift.value
		} );

		note.value = '';
		emit( 'changed' );

		notify(
			response.lifted
				? `Recorded, and ${ response.lifted.reference } is being lifted.`
				: 'Recorded, and they have been told.'
		);
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		busy.value = null;
	}
}
</script>
