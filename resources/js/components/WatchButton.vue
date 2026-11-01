<template>
	<cdx-button :disabled="busy" :action="watching ? 'progressive' : 'default'" :weight="watching ? 'primary' : 'normal'" @click="toggle">
		<cdx-icon :icon="watching ? cdxIconBell : cdxIconBellOutline" size="small" />
		{{ watching ? 'Watching' : 'Watch' }}
	</cdx-button>
</template>

<script setup>
import { inject, ref, watch } from 'vue';
import { CdxButton, CdxIcon } from '@wikimedia/codex';
import { cdxIconBell, cdxIconBellOutline } from '@wikimedia/codex-icons';
import { api } from '../lib/api.js';

const props = defineProps( {
	type: { type: String, required: true },
	id: { type: [ Number, String ], required: true }
} );

const notify = inject( 'notify' );

const watching = ref( false );
const busy = ref( false );

async function load() {
	try {
		watching.value = ( await api.subscription( props.type, props.id ) ).subscribed;
	} catch ( e ) {
		watching.value = false;
	}
}

async function toggle() {
	busy.value = true;

	try {
		if ( watching.value ) {
			await api.unsubscribe( props.type, props.id );
			watching.value = false;
			notify( 'You will no longer hear about changes to this.' );
		} else {
			await api.subscribe( props.type, props.id );
			watching.value = true;
			notify( 'You will be told when this changes.' );
		}
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		busy.value = false;
	}
}

watch( () => [ props.type, props.id ], load, { immediate: true } );
</script>
