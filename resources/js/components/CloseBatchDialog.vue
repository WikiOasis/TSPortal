<template>
	<cdx-dialog
		:open="open"
		class="ts-bulk-dialog ts-batch-dialog"
		title="Close the batch"
		:subtitle="`${ picked.length } of ${ closeBatch.items.length } ticked. Untick anything you want to keep open.`"
		:use-close-button="true"
		:primary-action="{
			label: saving ? `Closing ${ progress } of ${ picked.length }…` : `Close ${ picked.length } with no action`,
			actionType: 'destructive',
			disabled: !picked.length || saving
		}"
		:default-action="{ label: 'Keep reviewing' }"
		@update:open="$emit( 'update:open', $event )"
		@primary="submit"
		@default="$emit( 'update:open', false )"
		@keydown="onKeydown"
	>
		<div class="ts-stack">
			<div class="ts-bulk-table__head">
				<span class="ts-meta">
					Keyboard: J and K to move, X to tick or untick, {{ modKey }}+Enter to close them.
				</span>
				<span class="ts-inline">
					<cdx-button weight="quiet" size="small" @click="setAll( true )">Tick all</cdx-button>
					<cdx-button weight="quiet" size="small" @click="setAll( false )">Untick all</cdx-button>
					<cdx-button weight="quiet" size="small" action="destructive" :disabled="saving" @click="emptyBatch">
						Empty the batch
					</cdx-button>
				</span>
			</div>

			<ol ref="listRef" class="ts-batch" aria-label="Reports in the batch">
				<li
					v-for="( item, index ) in closeBatch.items"
					:key="item.id"
					class="ts-batch__item"
					:class="{
						'ts-batch__item--off': !ticked[ item.id ],
						'ts-batch__item--focus': index === focus
					}"
					@click="focus = index"
				>
					<cdx-checkbox
						:model-value="!!ticked[ item.id ]"
						:disabled="saving"
						@update:model-value="( v ) => ( ticked[ item.id ] = v )"
					>
						<span class="ts-batch__title">{{ item.page ?? item.reference }}</span>
						<span class="ts-meta"> · {{ item.wiki }}<template v-if="item.author"> · {{ item.author }}</template></span>
						<template #description>
							<span class="ts-batch__line">
								<cdx-info-chip v-if="bucket( item.bucket )" :status="bucket( item.bucket ).chip">
									{{ bucket( item.bucket ).label }}
								</cdx-info-chip>
								<span v-if="item.page_summary">{{ item.page_summary }}</span>
							</span>
							<span v-if="item.change_summary || item.reason" class="ts-batch__line ts-batch__reason">
								{{ item.change_summary }} {{ item.reason }}
							</span>
							<span class="ts-batch__line">
								<router-link :to="{ name: 'case', params: { id: item.id } }" class="ts-mono" target="_blank">{{ item.reference }}</router-link>
								<a v-if="item.revision" :href="item.revision" target="_blank" rel="noopener noreferrer" class="ts-meta"> · on the wiki ↗</a>
							</span>
						</template>
					</cdx-checkbox>
				</li>
			</ol>

			<p v-if="!closeBatch.items.length" class="ts-empty">The batch is empty.</p>

			<cdx-field>
				<template #label>Resolution</template>
				<template #description>Recorded on every report closed. Leave empty for the standard wording.</template>
				<cdx-text-area
					v-model="note"
					rows="2"
					placeholder="Checked with automated triage; no action needed."
					:disabled="saving"
				/>
			</cdx-field>
		</div>
	</cdx-dialog>
</template>

<script setup>
import { computed, inject, nextTick, reactive, ref, watch } from 'vue';
import {
	CdxButton, CdxCheckbox, CdxDialog, CdxField, CdxInfoChip, CdxTextArea
} from '@wikimedia/codex';
import { api } from '../lib/api.js';
import { bucket, closeBatch } from '../lib/autoreview.js';

const props = defineProps( {
	open: { type: Boolean, default: false }
} );

const emit = defineEmits( [ 'update:open', 'closed' ] );
const notify = inject( 'notify' );

const ticked = reactive( {} );
const note = ref( '' );
const saving = ref( false );
const progress = ref( 0 );
const focus = ref( 0 );
const listRef = ref( null );

const modKey = /Mac|iPhone|iPad/.test( navigator.platform ?? navigator.userAgent ) ? '⌘' : 'Ctrl';

const picked = computed( () => closeBatch.items.filter( ( item ) => ticked[ item.id ] ) );

watch( () => props.open, ( open ) => {
	if ( open ) {
		for ( const item of closeBatch.items ) {
			ticked[ item.id ] = true;
		}
		focus.value = 0;
		progress.value = 0;
	}
} );

function setAll( value ) {
	for ( const item of closeBatch.items ) {
		ticked[ item.id ] = value;
	}
}

function emptyBatch() {
	closeBatch.clear();
	emit( 'update:open', false );
}

function scrollToFocus() {
	nextTick( () => {
		const list = listRef.value;
		const row = list?.children[ focus.value ];
		if ( !list || !row ) {
			return;
		}
		const top = row.getBoundingClientRect().top - list.getBoundingClientRect().top + list.scrollTop;
		const bottom = top + row.offsetHeight;
		if ( top < list.scrollTop ) {
			list.scrollTop = top;
		} else if ( bottom > list.scrollTop + list.clientHeight ) {
			list.scrollTop = bottom - list.clientHeight;
		}
	} );
}

function onKeydown( event ) {
	if ( saving.value ) {
		return;
	}

	if ( event.key === 'Enter' && ( event.metaKey || event.ctrlKey ) ) {
		event.preventDefault();
		submit();
		return;
	}

	if ( event.target instanceof Element && [ 'TEXTAREA', 'INPUT' ].includes( event.target.tagName ) && event.target.type !== 'checkbox' ) {
		return;
	}

	const count = closeBatch.items.length;
	if ( !count ) {
		return;
	}

	if ( event.key === 'j' || event.key === 'ArrowDown' ) {
		event.preventDefault();
		focus.value = Math.min( count - 1, focus.value + 1 );
		scrollToFocus();
	} else if ( event.key === 'k' || event.key === 'ArrowUp' ) {
		event.preventDefault();
		focus.value = Math.max( 0, focus.value - 1 );
		scrollToFocus();
	} else if ( event.key === 'x' ) {
		event.preventDefault();
		const item = closeBatch.items[ focus.value ];
		if ( item ) {
			ticked[ item.id ] = !ticked[ item.id ];
			focus.value = Math.min( count - 1, focus.value + 1 );
			scrollToFocus();
		}
	}
}

async function submit() {
	if ( !picked.value.length || saving.value ) {
		return;
	}

	saving.value = true;
	progress.value = 0;

	const ids = picked.value.map( ( item ) => item.id );
	const closed = [];
	let skipped = 0;
	let counts = null;

	try {
		for ( let i = 0; i < ids.length; i += 100 ) {
			const chunk = ids.slice( i, i + 100 );
			const response = await api.autoReviewClose( { case_ids: chunk, note: note.value.trim() || null } );
			closed.push( ...response.closed );
			skipped += response.skipped.length;
			counts = response.counts;
			progress.value = Math.min( ids.length, i + chunk.length );
		}

		closeBatch.remove( ids );
		note.value = '';

		notify( skipped
			? `Closed ${ closed.length }. ${ skipped } were already closed or not automated, and have been dropped from the batch.`
			: `Closed ${ closed.length } with no action.` );

		emit( 'closed', { ids: closed, counts } );
		emit( 'update:open', false );
	} catch ( e ) {
		closeBatch.remove( closed );
		notify( `Stopped after ${ closed.length }: ${ e.message }`, 'error' );
		emit( 'closed', { ids: closed, counts } );
	} finally {
		saving.value = false;
	}
}
</script>
