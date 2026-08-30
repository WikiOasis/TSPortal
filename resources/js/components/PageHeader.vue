<template>
	<div class="ts-page__head">
		<div>
			<h1 class="ts-page__title">{{ title }}</h1>
			<p v-if="subtitle" class="ts-page__subtitle">{{ subtitle }}</p>
		</div>
		<div class="ts-inline">
			<slot name="actions" />
		</div>
	</div>
</template>

<script setup>
defineProps( {
	title: { type: String, required: true },
	subtitle: { type: String, default: '' }
} );
</script>
