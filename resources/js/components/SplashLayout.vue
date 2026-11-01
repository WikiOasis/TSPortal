<template>
	<main class="ts-splash">
		<div class="ts-splash__panel">
			<h1 class="ts-splash__title">{{ appName }}</h1>
			<p class="ts-splash__tagline">Trust &amp; Safety only</p>

			<p class="ts-splash__lede">
				Welcome to TSPortal, is internal tracking tool for reports, investigations
				and taking action on accounts on WikiOasis.
			</p>

			<slot></slot>

			<p class="ts-splash__version">{{ appName }} {{ version }}</p>
		</div>

		<aside class="ts-splash__media">
			<div class="ts-splash__image-placeholder">
				<img
					:src="imageUrl"
					alt="A tram on a cobbled street in Prague, seen from the roof of the Rudolfinum."
				>
			</div>
			<p class="ts-splash__credit">
				<a :href="imageUrl" target="_blank" rel="noopener">Zippybonzo</a>,
				<a href="https://en.wikipedia.org/wiki/Public_domain" target="_blank" rel="noopener">Public domain</a>
			</p>
		</aside>
	</main>
</template>

<script setup>
const imageUrl = '/images/tsportal_login_1_5_1.jpg';
const appName = window.TSPortal?.appName ?? 'TSPortal';
const version = window.TSPortal?.version ?? '1.5.1';
</script>
