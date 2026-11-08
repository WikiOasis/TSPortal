<template>
	<main class="ts-page ts-catchup">
		<PageHeader
			title="Catch up"
			:subtitle="deck.length ? `${ deck.length } remaining` : 'You are all caught up'"
		>
			<template #actions>
				<cdx-button :disabled="!deck.length || busyAll" @click="readAll">
					<cdx-icon :icon="cdxIconCheckAll" size="small" />
					Mark all read
				</cdx-button>
			</template>
		</PageHeader>

		<LoadError :error="error" :retry="reload" />
		<cdx-progress-bar v-if="loading && !deck.length" aria-label="Loading catch up" />

		<div v-if="deck.length" class="ts-deck" :style="{ '--ts-deck-x': `${ dx }px` }">
			<div class="ts-deck__stage">
				<div
					v-for="( row, i ) in visible"
					:key="row.id"
					class="ts-deck__slot"
					:class="{
						'ts-deck__slot--top': i === 0,
						'ts-deck__slot--dragging': dragging && i === 0,
						'ts-deck__slot--gone': i === 0 && leaving
					}"
					:style="slotStyle( i )"
					@pointerdown="i === 0 && down( $event )"
					@pointermove="i === 0 && move( $event )"
					@pointerup="i === 0 && up( $event )"
					@pointercancel="i === 0 && cancel()"
				>
					<CatchUpCard :row="row" :peek="i !== 0" :team="team" @engaged="onEngaged( row )" />

					<div
						v-if="i === 0 && progress > 0"
						class="ts-deck__cue"
						:class="`ts-deck__cue--${ side }`"
						:style="{ '--p': progress }"
						aria-hidden="true"
					>
						<svg class="ts-deck__badge" viewBox="0 0 80 80">
							<circle class="ts-deck__ring" cx="40" cy="40" r="36" />
							<circle class="ts-deck__fill" cx="40" cy="40" :r="36 * progress" />
							<g class="ts-deck__glyph" :class="{ 'ts-deck__glyph--on': progress > 0.55 }">
								<path v-if="side === 'read'" d="M23 41 L35 53 L58 27" pathLength="1" :style="{ strokeDashoffset: 1 - Math.min( 1, progress * 1.4 ) }" />
								<template v-else>
									<path d="M11 40 Q40 14 69 40 Q40 66 11 40 Z" pathLength="1" :style="{ strokeDashoffset: 1 - Math.min( 1, progress * 1.4 ) }" />
									<circle cx="40" cy="40" r="8" :style="{ opacity: progress }" />
								</template>
							</g>
						</svg>
						<span class="ts-deck__cue-label">{{ side === 'read' ? 'Mark read' : 'Leave for later' }}</span>
					</div>
				</div>
			</div>

			<div class="ts-deck__controls">
				<cdx-button :disabled="leaving || deck.length < 2" aria-label="Leave in catch up" @click="commit( 'left' )">
					<cdx-icon :icon="cdxIconPrevious" size="small" />
					Leave
				</cdx-button>
				<cdx-button weight="quiet" :disabled="!undoable || leaving" @click="undo">
					<cdx-icon :icon="cdxIconUndo" size="small" />
					Undo
				</cdx-button>
				<cdx-button action="progressive" weight="primary" :disabled="leaving" aria-label="Mark read" @click="commit( 'right' )">
					Mark read
					<cdx-icon :icon="cdxIconNext" size="small" />
				</cdx-button>
			</div>
		</div>

		<div v-else-if="!loading && !error" class="ts-panel ts-catchup__empty">
			<cdx-icon :icon="cdxIconCheckAll" size="large" />
			<h2>All caught up</h2>
			<cdx-button v-if="undoable" @click="undo">
				<cdx-icon :icon="cdxIconUndo" size="small" />
				Undo the last one
			</cdx-button>
		</div>
	</main>
</template>

<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref } from 'vue';
import { CdxButton, CdxIcon, CdxProgressBar } from '@wikimedia/codex';
import { cdxIconCheckAll, cdxIconNext, cdxIconPrevious, cdxIconUndo } from '@wikimedia/codex-icons';
import CatchUpCard from '../components/CatchUpCard.vue';
import LoadError from '../components/LoadError.vue';
import PageHeader from '../components/PageHeader.vue';
import { api } from '../lib/api.js';

const notify = inject( 'notify' );

const THRESHOLD = 110;
const touch = window.matchMedia?.( '( pointer: coarse )' ).matches ?? false;

const deck = ref( [] );
const page = ref( { current: 0, last: 1 } );
const loading = ref( false );
const error = ref( null );
const busyAll = ref( false );
const cleared = ref( 0 );
const undoable = ref( null );
const team = ref( [] );

const dx = ref( 0 );
const dragging = ref( false );
const leaving = ref( false );
let start = null;
let locked = null;

const visible = computed( () => deck.value.slice( 0, 3 ) );

function slotStyle( i ) {
	if ( i === 0 ) {
		const out = leaving.value ? ( dx.value >= 0 ? 1 : -1 ) * 1.4 * window.innerWidth : dx.value;
		return { transform: `translateX( ${ out }px ) rotate( ${ out / 40 }deg )`, zIndex: 3 };
	}

	const lift = Math.min( 1, Math.abs( dx.value ) / THRESHOLD );
	const depth = i - lift;

	return {
		transform: `translateY( ${ depth * 14 }px ) scale( ${ 1 - depth * 0.045 } )`,
		opacity: 1 - depth * 0.18,
		zIndex: 3 - i
	};
}

const progress = computed( () => ( leaving.value ? 1 : Math.min( 1, Math.abs( dx.value ) / THRESHOLD ) ) );
const side = computed( () => ( dx.value >= 0 ? 'read' : 'leave' ) );

async function fetchPage( n ) {
	loading.value = true;
	error.value = null;

	try {
		const response = await api.catchUp( { show: 'unread', per_page: 30, page: n } );
		const known = new Set( deck.value.map( ( r ) => r.id ) );
		deck.value = [ ...deck.value, ...response.data.filter( ( r ) => !known.has( r.id ) ) ];
		page.value = { current: response.meta.current_page, last: response.meta.last_page };
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

function reload() {
	deck.value = [];
	return fetchPage( 1 );
}

function topUp() {
	if ( deck.value.length < 5 && page.value.current < page.value.last && !loading.value ) {
		fetchPage( page.value.current + 1 );
	}
}

function interactive( target ) {
	return !!target.closest?.( 'a, button, input, textarea, select, [contenteditable="true"], .cdx-menu, .cdx-select-vue' );
}

function down( event ) {
	if ( leaving.value || interactive( event.target ) ) {
		return;
	}
	if ( event.pointerType === 'mouse' && event.button !== 0 ) {
		return;
	}

	start = { x: event.clientX, y: event.clientY };
	locked = null;
}

function move( event ) {
	if ( !start ) {
		return;
	}

	const mx = event.clientX - start.x;
	const my = event.clientY - start.y;

	if ( locked === null && Math.max( Math.abs( mx ), Math.abs( my ) ) > 10 ) {
		locked = Math.abs( mx ) > Math.abs( my ) * 1.2 ? 'x' : 'y';

		if ( locked === 'x' ) {
			event.currentTarget.setPointerCapture( event.pointerId );
			window.getSelection?.()?.removeAllRanges();
			dragging.value = true;
		}
	}

	if ( locked === 'x' ) {
		dx.value = mx;
	}
}

function up() {
	if ( !start ) {
		return;
	}

	const moved = dx.value;
	start = null;
	dragging.value = false;

	if ( moved >= THRESHOLD ) {
		commit( 'right' );
	} else if ( moved <= -THRESHOLD ) {
		commit( 'left' );
	} else {
		dx.value = 0;
	}
}

function cancel() {
	start = null;
	dragging.value = false;
	dx.value = 0;
}

async function commit( direction ) {
	const row = deck.value[ 0 ];

	if ( !row || leaving.value ) {
		return;
	}

	if ( direction === 'left' && deck.value.length < 2 ) {
		dx.value = 0;
		notify( 'That is the only one left.' );
		return;
	}

	dx.value = direction === 'right' ? 1 : -1;
	leaving.value = true;
	await new Promise( ( resolve ) => setTimeout( resolve, 260 ) );

	deck.value = deck.value.slice( 1 );
	leaving.value = false;
	dx.value = 0;

	if ( direction === 'right' ) {
		cleared.value++;
		undoable.value = { row, direction };

		try {
			await api.markRead( row.id );
		} catch ( e ) {
			deck.value = [ row, ...deck.value ];
			cleared.value--;
			undoable.value = null;
			notify( e.message, 'error' );
		}
	} else {
		deck.value = [ ...deck.value, row ];
		undoable.value = { row, direction };
	}

	topUp();
}

async function undo() {
	const last = undoable.value;
	undoable.value = null;

	if ( !last ) {
		return;
	}

	if ( last.direction === 'left' ) {
		deck.value = [ last.row, ...deck.value.filter( ( r ) => r.id !== last.row.id ) ];
		return;
	}

	try {
		await api.markUnread( last.row.id );
		deck.value = [ last.row, ...deck.value ];
		cleared.value = Math.max( 0, cleared.value - 1 );
	} catch ( e ) {
		notify( e.message, 'error' );
	}
}

/** Replying or changing something counts as having seen it, and the change must not bring it straight back. */
function onEngaged( row ) {
	api.markRead( row.id ).catch( () => {} );
}

async function readAll() {
	busyAll.value = true;

	try {
		const count = deck.value.length;
		await api.markAllRead();
		cleared.value += count;
		deck.value = [];
		undoable.value = null;
		notify( 'All caught up.' );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		busyAll.value = false;
	}
}

function onKey( event ) {
	if ( event.metaKey || event.ctrlKey || event.altKey || interactive( event.target ) || event.target.isContentEditable ) {
		return;
	}
	if ( event.key === 'ArrowRight' ) {
		commit( 'right' );
	} else if ( event.key === 'ArrowLeft' ) {
		commit( 'left' );
	}
}

onMounted( async () => {
	window.addEventListener( 'keydown', onKey );
	reload();

	try {
		team.value = ( await api.staff() ).data.filter( ( u ) => u.active && u.flags.includes( 'ts' ) );
	} catch ( e ) {
		team.value = [];
	}
} );

onBeforeUnmount( () => window.removeEventListener( 'keydown', onKey ) );
</script>
