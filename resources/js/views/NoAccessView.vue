<template>
	<splash-layout>
		<cdx-card class="ts-card-narrow">
			<template #title>Missing access</template>
			<template #supporting-text>
				<div class="ts-stack">
					<p>
						Your account <strong>{{ session.user?.username }}</strong> signed in
						successfully. It is not marked as Trust &amp; Safety, so you cannot
                        access TSPortal.
					</p>
					<p>
						Please reach out to tech if you believe that this is in error.
					</p>

					<form method="POST" :action="logoutUrl">
						<input type="hidden" name="_token" :value="csrf">
						<cdx-button type="submit">Sign out</cdx-button>
					</form>
				</div>
			</template>
		</cdx-card>
	</splash-layout>
</template>

<script setup>
import { CdxButton, CdxCard } from '@wikimedia/codex';
import { session } from '../lib/session.js';
import SplashLayout from '../components/SplashLayout.vue';

const logoutUrl = window.TSPortal?.logoutUrl ?? '/logout';
const csrf = document.querySelector( 'meta[name="csrf-token"]' )?.content ?? '';
</script>
