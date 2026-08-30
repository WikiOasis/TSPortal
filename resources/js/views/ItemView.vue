<template>
	<main class="ts-main">
		<div v-if="error" class="ts-panel ts-stack">
			<h1 class="ts-section__title">{{ reference }}</h1>
			<cdx-message type="error" :allow-user-dismiss="false">{{ error }}</cdx-message>
			<router-link :to="{ name: 'queue' }">Back to the queue</router-link>
		</div>

		<div v-else class="ts-panel ts-inline">
			<cdx-progress-bar :inline="true" />
			<span class="ts-meta">Looking up {{ reference }}…</span>
		</div>
	</main>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { CdxMessage, CdxProgressBar } from '@wikimedia/codex';
import { api } from '../lib/api.js';

const props = defineProps( { reference: { type: String, required: true } } );

const router = useRouter();
const error = ref( null );

onMounted( async () => {
	try {
		const found = await api.object( props.reference );

		if ( !found.route || found.id === null || found.id === undefined ) {
			error.value = `${ found.reference } is a ${ ( found.type_label || 'record' ).toLowerCase() }, and there is no page for it.`;

			return;
		}

		await router.replace( { name: found.route, params: { id: found.id } } );
	} catch ( e ) {
		error.value = e.message;
	}
} );
</script>
