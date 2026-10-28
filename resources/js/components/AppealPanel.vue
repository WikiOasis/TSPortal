<template>
	<section class="ts-section">
		<h2 class="ts-section__title">The appeal</h2>

		<div class="ts-panel ts-stack">
			<div v-if="action" class="ts-appeal__action">
				<div class="ts-appeal__action-main">
					<div class="ts-inline">
						<router-link :to="{ name: 'item', params: { reference: action.reference } }" class="ts-mono">
							{{ action.reference }}
						</router-link>
						<strong>{{ action.label }}</strong>
					</div>
					<div class="ts-meta">
						{{ action.reason_category_label || 'No reason recorded' }}
						· {{ action.where }}
						· issued {{ date( action.issued ) }}
						<template v-if="action.lifted"> · lifted {{ date( action.lifted ) }}</template>
					</div>
				</div>
				<StatusChip kind="force" :value="action.in_force ? 'in-force' : 'lifted'" />
			</div>

			<p v-else class="ts-meta">
				Not attached to an action yet.
			</p>

			<cdx-message
				v-if="appeal.link.needs_checking && !decided"
				type="notice"
				:allow-user-dismiss="false"
			>
				Nobody has confirmed which action this is about.
			</cdx-message>

			<details
				v-if="!decided && options.length"
				class="ts-details"
				:open="appeal.link.needs_checking || !action"
			>
				<summary>
					{{ action ? 'Wrong action?' : 'Which action is this about?' }}
					<StatusChip v-if="appeal.link.confidence" kind="appeal-confidence" :value="appeal.link.confidence" />
				</summary>
				<div class="ts-stack ts-appeal__link">
					<p v-if="appeal.link.explanation" class="ts-meta">
						{{ appeal.link.explanation }}
						<template v-if="appeal.link.corrected">
							— {{ appeal.link.corrected.by }}, {{ ago( appeal.link.corrected.at ) }}
						</template>
					</p>
					<ul v-if="appeal.link.notes && appeal.link.notes.length" class="ts-meta">
						<li v-for="( n, i ) in appeal.link.notes" :key="i">{{ n }}</li>
					</ul>
					<cdx-select
						v-model:selected="linkDraft"
						:menu-items="linkOptions"
						:disabled="busy"
						@update:selected="relink"
					/>
				</div>
			</details>

			<div v-if="decided" class="ts-appeal__decision">
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
					Granted, but <strong>{{ action.reference }} is still in force</strong>.
					Lift it from the action page.
				</cdx-message>
			</div>
		</div>
	</section>
</template>

<script setup>
import { computed, inject, ref, watch } from 'vue';
import { CdxMessage, CdxSelect } from '@wikimedia/codex';
import StatusChip from './StatusChip.vue';
import { api } from '../lib/api.js';
import { ago, date } from '../lib/format.js';

const props = defineProps( {
	caseId: { type: [ String, Number ], required: true },
	appeal: { type: Object, required: true }
} );

const emit = defineEmits( [ 'changed' ] );
const notify = inject( 'notify' );

const action = computed( () => props.appeal.action ?? null );
const options = computed( () => props.appeal.options ?? [] );
const decided = computed( () => !!props.appeal.outcome );
const accepted = computed( () => [ 'granted', 'partly-granted' ].includes( props.appeal.outcome ) );

const busy = ref( false );
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

async function relink( reference ) {
	const current = action.value ? action.value.reference : '';
	if ( reference === current ) {
		return;
	}

	busy.value = true;

	try {
		await api.linkAppealAction( props.caseId, reference || null );
		emit( 'changed' );
		notify( reference ? `This appeal is now against ${ reference }.` : 'This appeal is no longer attached to an action.' );
	} catch ( e ) {
		linkDraft.value = current;
		notify( e.message, 'error' );
	} finally {
		busy.value = false;
	}
}
</script>
