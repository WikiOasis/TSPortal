<template>
	<div class="ts-app">
		<div v-if="!session.loaded" class="ts-centre">
			<cdx-progress-bar aria-label="Loading the portal" />
		</div>

		<AppSidebar v-else-if="session.isStaff">
			<router-view />
		</AppSidebar>

		<router-view v-else />

		<cdx-toast-container />
	</div>
</template>

<script setup>
import { provide } from 'vue';
import { CdxProgressBar, CdxToastContainer, useToast } from '@wikimedia/codex';
import AppSidebar from './components/AppSidebar.vue';
import { session } from './lib/session.js';

const toast = useToast();

provide( 'notify', ( message, type = 'success' ) => {
	toast.show( { message, type, autoDismiss: type === 'error' ? false : 4000 } );
} );
</script>
