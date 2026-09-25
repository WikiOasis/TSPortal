<template>
	<cdx-dialog
		:open="open"
		title="Add pages"
		subtitle="Paste a list, one page per line. Their editors are looked up on the wiki."
		:use-close-button="true"
		:primary-action="{
			label: saving ? 'Adding and looking up…' : addLabel,
			actionType: 'progressive',
			disabled: !pages.length || saving
		}"
		:default-action="{ label: 'Cancel' }"
		@update:open="$emit( 'update:open', $event )"
		@primary="submit"
		@default="$emit( 'update:open', false )"
	>
		<div class="ts-stack">
			<cdx-field>
				<template #label>Wiki</template>
				<template #description>
					For lines that do not say which wiki they are on. Links and
					<code>examplewiki:Page title</code> lines say it themselves.
				</template>
				<cdx-text-input v-model="wiki" placeholder="examplewiki" :disabled="saving" />
			</cdx-field>

			<cdx-field>
				<template #label>The pages</template>
				<template #description>
					Bullets and <code>[[ ]]</code> are stripped. Underscores become spaces.
				</template>
				<cdx-text-area
					v-model="text"
					rows="8"
					:disabled="saving"
					placeholder="Spam page&#10;* [[Talk:Another one]]&#10;otherwiki:Main_Page&#10;https://example.wikioasis.org/wiki/Bad_page"
				/>
			</cdx-field>

			<cdx-field>
				<template #label>Note</template>
				<template #description>Optional. Kept against each of them on this file.</template>
				<cdx-text-input v-model="note" :disabled="saving" placeholder="Spam wave, 25 September" />
			</cdx-field>

			<div v-if="pages.length" class="ts-panel">
				<p class="ts-meta">{{ pages.length }} page{{ pages.length === 1 ? '' : 's' }} read from that:</p>
				<div class="ts-inline">
					<cdx-info-chip v-for="page in shown" :key="`${ page.wiki }|${ page.title }`">
						{{ page.title }} <span class="ts-mono">({{ page.wiki }})</span>
					</cdx-info-chip>
					<span v-if="pages.length > shown.length" class="ts-meta">
						and {{ pages.length - shown.length }} more
					</span>
				</div>
			</div>

			<cdx-message
				v-else-if="text.trim() && !previewSkipped.length"
				type="warning"
				:inline="true"
				:allow-user-dismiss="false"
			>
				No pages could be read from that.
			</cdx-message>

			<cdx-message v-if="skipped.length" type="notice" :allow-user-dismiss="false">
				<p>{{ skipped.length }} {{ skipped.length === 1 ? 'was' : 'were' }} left out:</p>
				<ul>
					<li v-for="row in skipped" :key="row.page">{{ row.page }} — {{ row.why }}</li>
				</ul>
			</cdx-message>
		</div>
	</cdx-dialog>
</template>

<script setup>
import { computed, inject, ref, watch } from 'vue';
import {
	CdxDialog, CdxField, CdxInfoChip, CdxMessage, CdxTextArea, CdxTextInput
} from '@wikimedia/codex';
import { api } from '../lib/api.js';

const props = defineProps( {
	open: { type: Boolean, default: false },
	investigationId: { type: [ String, Number ], required: true },
	defaultWiki: { type: String, default: '' }
} );

const emit = defineEmits( [ 'update:open', 'added' ] );
const notify = inject( 'notify' );

const text = ref( '' );
const wiki = ref( '' );
const note = ref( '' );
const pages = ref( [] );
const previewSkipped = ref( [] );
const saved = ref( [] );
const saving = ref( false );

const shown = computed( () => pages.value.slice( 0, 30 ) );
const skipped = computed( () => [ ...previewSkipped.value, ...saved.value ] );

const addLabel = computed( () => `Add ${ pages.value.length } page${ pages.value.length === 1 ? '' : 's' }` );

watch( () => props.open, ( isOpen ) => {
	if ( isOpen ) {
		text.value = '';
		wiki.value = props.defaultWiki;
		note.value = '';
		pages.value = [];
		previewSkipped.value = [];
		saved.value = [];
	}
} );

let timer = null;
let inFlight = null;

watch( [ text, wiki ], ( [ value ] ) => {
	clearTimeout( timer );

	if ( !value.trim() ) {
		pages.value = [];
		previewSkipped.value = [];
		return;
	}

	timer = setTimeout( async () => {
		inFlight?.abort();
		inFlight = new AbortController();

		try {
			const response = await api.previewPages( value, wiki.value.trim() || null, { signal: inFlight.signal } );
			pages.value = response.data;
			previewSkipped.value = response.skipped;
		} catch ( e ) {
			if ( e.name !== 'AbortError' ) {
				pages.value = [];
			}
		}
	}, 250 );
} );

async function submit() {
	saving.value = true;
	saved.value = [];

	try {
		const response = await api.addInvestigationPages( props.investigationId, {
			text: text.value,
			wiki: wiki.value.trim() || null,
			note: note.value || null
		} );

		emit( 'added', response );

		notify(
			response.added
				? `${ response.added } page${ response.added === 1 ? '' : 's' } added to the file.`
				: 'Nothing new to add — they were all on the file already.',
			response.added ? 'success' : 'warning'
		);

		if ( !response.skipped.length ) {
			emit( 'update:open', false );
		} else {
			saved.value = response.skipped;
			text.value = '';
			pages.value = [];
		}
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		saving.value = false;
	}
}
</script>
