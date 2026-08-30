<template>
	<main class="ts-page">
		<h1 class="ts-page__title">There is nothing at this address</h1>
		<p class="ts-page__subtitle">
			The link may be out of date, or the case may have been merged into another.
		</p>
		<p><router-link :to="{ name: 'dashboard' }">Back to the dashboard</router-link></p>
	</main>
</template>
