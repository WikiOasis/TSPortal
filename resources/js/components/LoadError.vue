<template>
	<cdx-message v-if="error" type="error" :allow-user-dismiss="false">
		<p>{{ error.message }}</p>
		<p v-if="error.status === 403" class="ts-meta">
			If you think you should have access, ask someone on the team who holds the
			<code>user-manager</code> flag.
		</p>
		<template v-if="retry">
			<cdx-button action="progressive" @click="retry">Try again</cdx-button>
		</template>
	</cdx-message>
</template>

<script setup>
import { CdxButton, CdxMessage } from '@wikimedia/codex';

defineProps( {
	error: { type: Object, default: null },
	retry: { type: Function, default: null }
} );
</script>
