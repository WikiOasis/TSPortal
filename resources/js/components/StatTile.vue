<template>
	<div class="ts-panel ts-stat ts-stat--figure" :class="`ts-stat--${ tone }`">
		<div class="ts-stat__value">
			{{ shown }}<em v-if="unit && figure.value !== null"> {{ unit }}</em>
		</div>

		<div class="ts-stat__label">{{ label }}</div>

		<div v-if="change" class="ts-stat__detail" :class="`ts-stat__detail--${ change.tone }`">
			{{ change.text }}
		</div>
		<div v-else-if="note" class="ts-stat__detail">{{ note }}</div>
	</div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps( {
	label: { type: String, required: true },
	figure: { type: Object, required: true },
	unit: { type: String, default: '' },
	note: { type: String, default: '' },
	tone: { type: String, default: 'plain' },
	lowerIsBetter: { type: Boolean, default: false }
} );

const shown = computed( () => {
	const value = props.figure?.value;

	if ( value === null || value === undefined ) {
		return '—';
	}

	return typeof value === 'number' ? Math.round( value ).toLocaleString() : value;
} );

const change = computed( () => {
	const now = props.figure?.value;
	const before = props.figure?.previous;

	if ( now === null || now === undefined || before === null || before === undefined ) {
		return null;
	}

	if ( before === 0 ) {
		return now === 0
			? { text: 'unchanged', tone: 'flat' }
			: { text: 'none in the previous period', tone: 'flat' };
	}

	const delta = Math.round( ( ( now - before ) / before ) * 100 );

	if ( delta === 0 ) {
		return { text: 'unchanged', tone: 'flat' };
	}

	const up = delta > 0;
	const better = props.lowerIsBetter ? !up : up;

	return {
		text: `${ up ? 'up' : 'down' } ${ Math.abs( delta ) }% on the previous period`,
		tone: better ? 'better' : 'worse'
	};
} );
</script>
