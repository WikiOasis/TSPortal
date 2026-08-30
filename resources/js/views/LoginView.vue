<template>
	<splash-layout>
		<cdx-card class="ts-card-narrow">
			<template #title>Sign in</template>
			<template #supporting-text>
				<div class="ts-stack">

					<cdx-message v-if="error" type="error" :allow-user-dismiss="false">
						{{ error }}
					</cdx-message>

					<cdx-message v-if="!oauthConfigured" type="warning" :allow-user-dismiss="false">
						No OAuth client is currently configured. Please contact an administrator for help.
					</cdx-message>

					<cdx-button
						action="progressive"
						weight="primary"
						:disabled="!oauthConfigured"
						@click="signIn"
					>
						Continue with WikiOasis
					</cdx-button>
				</div>
			</template>
		</cdx-card>
	</splash-layout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { CdxButton, CdxCard, CdxMessage } from '@wikimedia/codex';
import { session } from '../lib/session.js';
import SplashLayout from '../components/SplashLayout.vue';

const route = useRoute();

const oauthConfigured = computed( () => session.wiki?.oauth_configured !== false );

const error = ref( route.query.error ? String( route.query.error ) : '' );

function signIn() {
	const base = window.TSPortal?.loginUrl ?? '/auth/mediawiki/redirect';
	const next = route.query.next;

	const safe = typeof next === 'string' && next.startsWith( '/' ) && !next.startsWith( '//' )
		? `?next=${ encodeURIComponent( next ) }`
		: '';

	window.location.href = base + safe;
}
</script>
