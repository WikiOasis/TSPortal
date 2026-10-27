<template>
	<!-- eslint-disable-next-line vue/no-v-html -- render() escapes everything it is given -->
	<component
		:is="tag"
		class="ts-md"
		@click="reveal"
		@keydown.enter="reveal"
		v-html="html"
	/>
</template>

<script setup>
import { computed } from 'vue';
import { render } from '../lib/markdown.js';

const props = defineProps( {
	text: { type: String, default: '' },
	tag: { type: String, default: 'div' }
} );

const html = computed( () => render( props.text ) );

function reveal( event ) {
	const spoiler = event.target.closest?.( '.ts-md-spoiler' );
	if ( spoiler ) {
		spoiler.classList.add( 'ts-md-spoiler--shown' );
	}
}
</script>
