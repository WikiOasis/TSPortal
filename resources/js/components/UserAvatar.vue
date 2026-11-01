<template>
	<span class="ts-avatar-img" :style="{ '--ts-avatar-hue': hue, '--ts-avatar-size': size }">
		<img v-if="url && !broken" :src="url" alt="" @error="broken = true">
		<span v-else aria-hidden="true">{{ initial }}</span>
	</span>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps( {
	name: { type: String, default: '?' },
	url: { type: String, default: null },
	size: { type: String, default: '2rem' }
} );

const broken = ref( false );

watch( () => props.url, () => {
	broken.value = false;
} );

const initial = computed( () => ( props.name || '?' ).slice( 0, 1 ).toUpperCase() );

const hue = computed( () => {
	let hash = 0;
	for ( const ch of props.name ?? '' ) {
		hash = ( hash * 31 + ch.charCodeAt( 0 ) ) % 360;
	}
	return hash;
} );
</script>
