<template>
	<div class="ts-reply" :class="`ts-reply--${ visibility }`">
		<div class="ts-reply__head">
			<cdx-icon :icon="cdxIconSpeechBubble" size="x-small" />
			<span class="ts-reply__to">
				Replying to <strong>{{ quote.author }}</strong>
			</span>
			<span class="ts-reply__spacer" />
			<span class="ts-reply__who">
				<cdx-icon :icon="visibility === 'public' ? cdxIconEye : cdxIconLock" size="x-small" />
				{{ visibility === 'public' ? `${ reporterName } will see this` : kind === 'case' ? 'Internal — staff only' : 'Added to the file' }}
			</span>
		</div>

		<MarkdownEditor
			ref="editor"
			v-model="body"
			:rows="2"
			:upload="visibility === 'internal' ? upload : null"
			:label="`Reply to ${ quote.author }`"
			:placeholder="visibility === 'public' ? `Reply to ${ reporterName }…` : 'Reply to the team…'"
			@submit="submit"
		/>

		<div class="ts-reply__foot">
			<cdx-checkbox
				v-if="canGoPublic"
				:model-value="visibility === 'internal'"
				:disabled="hasImages( body )"
				@update:model-value="( v ) => ( privately = v )"
			>
				Keep this internal
			</cdx-checkbox>
			<span class="ts-reply__spacer" />
			<cdx-button weight="quiet" @click="emit( 'cancel' )">Cancel</cdx-button>
			<cdx-button
				:action="visibility === 'public' ? 'progressive' : 'default'"
				weight="primary"
				:disabled="!body.trim() || busy"
				@click="submit"
			>
				{{ busy ? 'Sending…' : visibility === 'public' ? 'Send reply' : 'Save reply' }}
			</cdx-button>
		</div>
	</div>
</template>

<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import { CdxButton, CdxCheckbox, CdxIcon } from '@wikimedia/codex';
import { cdxIconEye, cdxIconLock, cdxIconSpeechBubble } from '@wikimedia/codex-icons';
import MarkdownEditor from './MarkdownEditor.vue';
import { api } from '../lib/api.js';
import { hasImages } from '../lib/inlineImages.js';

const props = defineProps( {
	kind: { type: String, default: 'case' },
	item: { type: Object, required: true },
	quote: { type: Object, required: true }
} );
const emit = defineEmits( [ 'cancel', 'done' ] );

const notify = inject( 'notify' );
const editor = ref( null );
const body = ref( '' );
const busy = ref( false );
const privately = ref( false );

const reporterName = computed( () => props.item.reporter?.username ?? 'the reporter' );

const canGoPublic = computed( () => props.kind === 'case' &&
	props.quote.visibility === 'public' &&
	!props.item.anonymous &&
	!!props.item.reporter );

const visibility = computed( () => ( canGoPublic.value && !privately.value && !hasImages( body.value )
	? 'public'
	: 'internal' ) );

const upload = ( file ) => ( props.kind === 'case'
	? api.uploadCaseImage( props.item.id, file )
	: api.uploadInvestigationImage( props.item.id, file ) );

onMounted( () => editor.value?.focus() );

async function submit() {
	if ( !body.value.trim() || busy.value ) {
		return;
	}
	busy.value = true;
	try {
		const response = props.kind === 'case'
			? await api.caseTransaction( props.item.id, {
				comment: body.value,
				visibility: visibility.value,
				reply_to: props.quote.id
			} )
			: await api.investigationTransaction( props.item.id, {
				note: body.value,
				kind: 'note',
				reply_to: props.quote.id
			} );
		notify( visibility.value === 'public' ? 'Reply sent to the reporter.' : 'Reply saved.' );
		emit( 'done', response.data );
	} catch ( e ) {
		notify( e.errors ? Object.values( e.errors ).flat()[ 0 ] : e.message, 'error' );
	} finally {
		busy.value = false;
	}
}
</script>
