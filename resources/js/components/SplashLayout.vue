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
					src="https://cdn.wikioasis.org/static/tsportal_login_0_1_0.jpeg"
					alt="Battersea power station at night, from the rooftop of a neighbouring building."
				>
			</div>
			<p class="ts-splash__credit">
				<a href="https://cdn.wikioasis.org/static/tsportal_login_0_1_0.jpeg" target="_blank" rel="noopener">Zippybonzo</a>,
				<a href="https://en.wikipedia.org/wiki/Public_domain" target="_blank" rel="noopener">Public domain</a>
			</p>
		</aside>
	</main>
</template>

<script setup>
const appName = window.TSPortal?.appName ?? 'TSPortal';
const version = window.TSPortal?.version ?? 'beta';
</script>
