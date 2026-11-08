<template>
	<div class="ts-grad">
		<div class="ts-grad__bar" :style="{ background: css }" role="img" :aria-label="`Gradient of ${ stops.length } colours`" />

		<div class="ts-grad__stops">
			<div v-for="( stop, i ) in stops" :key="i" class="ts-grad__stop">
				<ColourPicker :model-value="stop" :label="`Colour ${ i + 1 }`" @update:model-value="( v ) => setStop( i, v )" />
				<cdx-button
					weight="quiet"
					:disabled="stops.length <= MIN_STOPS"
					:aria-label="`Remove colour ${ i + 1 }`"
					:title="stops.length <= MIN_STOPS ? 'A gradient needs at least 2 colours' : 'Remove this colour'"
					@click="removeStop( i )"
				>
					<cdx-icon :icon="cdxIconTrash" />
				</cdx-button>
			</div>
		</div>

		<div class="ts-inline">
			<cdx-button :disabled="stops.length >= MAX_STOPS" @click="addStop">
				<cdx-icon :icon="cdxIconAdd" size="small" />
				Add a colour
			</cdx-button>
			<cdx-button weight="quiet" @click="reverse">Reverse</cdx-button>
			<span class="ts-meta">{{ stops.length }} of {{ MAX_STOPS }} colours</span>
		</div>

		<label class="ts-grad__angle">
			<span>Angle · {{ angle }}°</span>
			<input
				type="range"
				class="ts-settings__range"
				min="0"
				max="360"
				step="5"
				:value="angle"
				aria-label="Gradient angle"
				@input="update( stops, Number( $event.target.value ) )"
			>
		</label>
	</div>
</template>

<script setup>
import { computed } from 'vue';
import { CdxButton, CdxIcon } from '@wikimedia/codex';
import { cdxIconAdd, cdxIconTrash } from '@wikimedia/codex-icons';
import ColourPicker from './ColourPicker.vue';
import { gradientCss, MAX_STOPS, MIN_STOPS } from '../lib/gradient.js';

const props = defineProps( { modelValue: { type: Object, required: true } } );
const emit = defineEmits( [ 'update:modelValue' ] );

const stops = computed( () => props.modelValue.stops );
const angle = computed( () => Number( props.modelValue.angle ?? 135 ) );
const css = computed( () => gradientCss( props.modelValue ) );

function update( next, deg = angle.value ) {
	emit( 'update:modelValue', { stops: next, angle: deg } );
}

const setStop = ( i, value ) => update( stops.value.map( ( s, at ) => ( at === i ? value : s ) ) );

function removeStop( i ) {
	if ( stops.value.length > MIN_STOPS ) {
		update( stops.value.filter( ( _, at ) => at !== i ) );
	}
}

function addStop() {
	if ( stops.value.length < MAX_STOPS ) {
		update( [ ...stops.value, stops.value[ stops.value.length - 1 ] ] );
	}
}

const reverse = () => update( [ ...stops.value ].reverse() );
</script>
