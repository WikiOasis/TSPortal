<template>
	<cdx-dialog
		:open="open"
		title="Search the portal"
		:hide-title="true"
		:use-close-button="false"
		class="ts-switcher"
		@update:open="close"
	>
		<div class="ts-switcher__frame" :class="{ 'ts-switcher__frame--split': showPreview }">
			<div class="ts-switcher__search">
				<cdx-icon :icon="cdxIconSearch" class="ts-switcher__search-icon" />

				<input
					ref="inputRef"
					v-model="q"
					class="ts-switcher__input"
					type="text"
					role="combobox"
					aria-expanded="true"
					aria-controls="ts-switcher-results"
					:aria-activedescendant="activeRow ? rowId( activeRow ) : undefined"
					:placeholder="placeholder"
					aria-label="Search the portal"
					autocomplete="off"
					spellcheck="false"
					@keydown="onKeydown"
				>

				<cdx-button
					class="ts-switcher__pane-toggle"
					weight="quiet"
					:aria-pressed="previewWanted"
					:aria-label="previewWanted ? 'Hide the preview' : 'Show a preview'"
					@click="togglePreview"
				>
					<cdx-icon :icon="cdxIconViewCompact" />
				</cdx-button>

				<cdx-button
					class="ts-switcher__close"
					weight="quiet"
					aria-label="Close the search"
					@click="close( false )"
				>
					<cdx-icon :icon="cdxIconClose" />
				</cdx-button>
			</div>

			<div class="ts-switcher__filters">
				<cdx-toggle-button
					v-model="titlesOnly"
					:quiet="true"
					class="ts-switcher__filter"
					:aria-label="titlesOnly ? 'Searching titles only' : 'Searching everything written down'"
				>
					<span class="ts-switcher__aa" aria-hidden="true">Aa</span>
					Title only
				</cdx-toggle-button>

				<cdx-menu-button
					v-model:selected="kinds"
					class="ts-switcher__filter"
					:menu-items="kindItems"
					weight="quiet"
				>
					<cdx-icon :icon="cdxIconArticle" />
					{{ kindsLabel }}
					<cdx-icon :icon="cdxIconExpand" class="ts-switcher__caret" />
				</cdx-menu-button>

				<cdx-button
					v-for="extra in activeExtras"
					:key="extra.value"
					class="ts-switcher__filter ts-switcher__filter--on"
					weight="quiet"
					@click="dropExtra( extra.value )"
				>
					<cdx-icon :icon="extra.icon" />
					{{ extra.label }}
					<cdx-icon :icon="cdxIconClose" class="ts-switcher__caret" />
				</cdx-button>

				<cdx-menu-button
					v-if="spareExtras.length"
					:selected="null"
					class="ts-switcher__filter ts-switcher__filter--add"
					:menu-items="spareExtras"
					weight="quiet"
					@update:selected="addExtra"
				>
					<cdx-icon :icon="cdxIconAdd" />
					Filter
				</cdx-menu-button>

				<cdx-button
					v-if="filtered"
					class="ts-switcher__filter"
					weight="quiet"
					@click="clearFilters"
				>
					Clear
				</cdx-button>

				<cdx-button
					class="ts-switcher__filter ts-switcher__settings-toggle"
					:class="{ 'ts-switcher__filter--on': settingsOpen }"
					weight="quiet"
					:aria-expanded="settingsOpen"
					aria-controls="ts-switcher-settings"
					aria-label="What the empty box offers"
					@click="settingsOpen = !settingsOpen"
				>
					<cdx-icon :icon="cdxIconSettings" />
				</cdx-button>
			</div>

			<div v-if="settingsOpen" id="ts-switcher-settings" class="ts-switcher__settings">
				<p class="ts-switcher__settings-note">
					What the empty box offers, and in what order. Related only turns up while you are
					looking at a report, an investigation, an account or a transparency report.
				</p>

				<ul class="ts-switcher__settings-list">
					<li
						v-for="( section, at ) in orderedSections"
						:key="section.value"
						class="ts-switcher__settings-row"
					>
						<cdx-icon :icon="section.icon" class="ts-switcher__settings-icon" />

						<span class="ts-switcher__settings-label">{{ section.label }}</span>

						<span v-if="section.value === 'related' && !seed" class="ts-meta">
							Nothing to compare here
						</span>

						<cdx-button
							weight="quiet"
							:disabled="at === 0"
							:aria-label="`Move ${ section.label } up`"
							@click="moveSection( at, -1 )"
						>
							<cdx-icon :icon="cdxIconUpTriangle" />
						</cdx-button>

						<cdx-button
							weight="quiet"
							:disabled="at === orderedSections.length - 1"
							:aria-label="`Move ${ section.label } down`"
							@click="moveSection( at, 1 )"
						>
							<cdx-icon :icon="cdxIconDownTriangle" />
						</cdx-button>
					</li>
				</ul>
			</div>

			<div class="ts-switcher__body">
				<div class="ts-switcher__list">
					<cdx-progress-bar
						v-if="loading || relatedLoading"
						class="ts-switcher__progress"
						aria-label="Searching"
					/>

					<ul
						v-if="groups.length"
						id="ts-switcher-results"
						class="ts-switcher__results"
						role="listbox"
						aria-label="Results"
					>
						<template v-for="group in groups" :key="group.key">
							<li class="ts-switcher__group" role="presentation">
								{{ group.label }}
							</li>

							<li
								v-for="row in group.rows"
								:id="rowId( row )"
								:key="rowId( row )"
								class="ts-switcher__row"
								:class="{ 'ts-switcher__row--active': isActive( row ) }"
								role="option"
								:aria-selected="isActive( row )"
								@mousemove="makeActive( row )"
								@click="go( row, false )"
							>
								<cdx-icon :icon="icon( row.kind )" class="ts-switcher__row-icon" />

								<span class="ts-switcher__row-main">
									<span class="ts-switcher__row-title">{{ row.title }}</span>

									<span class="ts-switcher__row-meta">
										<span v-if="row.reference" class="ts-mono">{{ row.reference }}</span>
										<span>{{ row.kind_label }}</span>
										<span v-if="row.assignee">{{ row.assignee }}</span>
										<span v-if="row.related_by">{{ row.related_by }}</span>
										<span v-if="row.updated">{{ ago( row.updated ) }}</span>
									</span>

									<span v-if="row.snippet" class="ts-switcher__row-snippet">
										<span
											v-for="( run, i ) in row.snippet"
											:key="i"
											:class="{ 'ts-switcher__hit': run.match }"
										>{{ run.text }}</span>
									</span>
								</span>

								<StatusChip
									v-if="row.status"
									:kind="row.status_of || 'case'"
									:value="row.status"
								/>
							</li>
						</template>
					</ul>

					<p v-else-if="loading" class="ts-empty">Looking…</p>

					<p v-else-if="error" class="ts-empty">
						The search did not answer. Try again, or use the queue's own search.
					</p>

					<p v-else-if="term" class="ts-empty">
						Nothing matches “{{ term }}”{{ titlesOnly ? ' in any title' : '' }}.
						<template v-if="titlesOnly && fullText">
							<button type="button" class="ts-linkish" @click="titlesOnly = false">
								Search everything written down
							</button>
						</template>
					</p>

					<p v-else class="ts-empty">
						Type a name, a reference, or a phrase somebody wrote.
					</p>
				</div>

				<div v-if="showPreview" class="ts-switcher__preview">
					<div v-if="preview" class="ts-switcher__card">
						<div class="ts-switcher__card-head">
							<cdx-icon :icon="icon( preview.kind )" class="ts-switcher__card-icon" />

							<div class="ts-switcher__card-title">
								<span class="ts-switcher__card-kind">
									{{ preview.kind_label }}
									<span v-if="preview.reference" class="ts-mono">{{ preview.reference }}</span>
								</span>
								<strong>{{ preview.title }}</strong>
								<span v-if="preview.subtitle" class="ts-meta">{{ preview.subtitle }}</span>
							</div>

							<cdx-button
								weight="quiet"
								aria-label="Open in a new tab"
								@click="go( activeRow, true )"
							>
								<cdx-icon :icon="cdxIconLinkExternal" />
							</cdx-button>
						</div>

						<div class="ts-switcher__chips">
							<StatusChip
								v-if="preview.status"
								:kind="preview.status_of || 'case'"
								:value="preview.status"
							/>
							<StatusChip v-if="preview.priority" kind="priority" :value="preview.priority" />
							<cdx-info-chip v-if="preview.threat_to_life" status="error">
								Threat to life
							</cdx-info-chip>
						</div>

						<dl v-if="preview.facts.length" class="ts-switcher__facts">
							<template v-for="fact in preview.facts" :key="fact.label">
								<dt>{{ fact.label }}</dt>
								<dd>{{ fact.date ? dateTime( fact.value ) : fact.value }}</dd>
							</template>
						</dl>

						<div v-if="preview.accounts.length" class="ts-switcher__accounts">
							<span
								v-for="account in preview.accounts"
								:key="account.id"
								class="ts-switcher__account"
							>
								<cdx-icon :icon="cdxIconUserAvatar" size="small" />
								{{ account.username }}
								<StatusChip v-if="account.role" kind="role" :value="account.role" />
							</span>
						</div>

						<div v-if="preview.excerpt" class="ts-switcher__excerpt">
							<span class="ts-meta">{{ preview.excerpt_label }}</span>
							<p>{{ preview.excerpt }}</p>
						</div>

						<div v-if="preview.counts.length" class="ts-switcher__counts">
							<span v-for="count in preview.counts" :key="count.label">
								<strong>{{ count.value }}</strong> {{ count.label }}
							</span>
						</div>
					</div>

					<p v-else-if="previewLoading" class="ts-empty">Fetching it…</p>

					<p v-else class="ts-empty">
						{{ activeRow ? 'Nothing to preview.' : 'Pick something to see it here.' }}
					</p>
				</div>
			</div>

			<div class="ts-switcher__foot">
				<span class="ts-switcher__hints">
					<kbd>↵</kbd> Open
					<kbd>{{ metaKey }}↵</kbd> New tab
					<kbd>↑↓</kbd> Move
					<kbd>Esc</kbd> Close
				</span>

				<span class="ts-switcher__engine">{{ engineNote }}</span>
			</div>
		</div>
	</cdx-dialog>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
	CdxButton, CdxDialog, CdxIcon, CdxInfoChip, CdxMenuButton, CdxProgressBar, CdxToggleButton
} from '@wikimedia/codex';
import {
	cdxIconAdd, cdxIconArticle, cdxIconBlock, cdxIconClose, cdxIconDownTriangle, cdxIconExpand,
	cdxIconFlag, cdxIconHistory, cdxIconLightbulb, cdxIconLinkExternal, cdxIconSearch,
	cdxIconSettings, cdxIconTrash, cdxIconUpTriangle, cdxIconUserAvatar, cdxIconUserGroup,
	cdxIconViewCompact
} from '@wikimedia/codex-icons';
import StatusChip from './StatusChip.vue';
import { api } from '../lib/api.js';
import { ago, dateTime } from '../lib/format.js';
import { recents, remember } from '../lib/recents.js';

const props = defineProps( {
	open: { type: Boolean, default: false }
} );

const emit = defineEmits( [ 'update:open' ] );
const router = useRouter();
const route = useRoute();

const PREVIEW_KEY = 'tsportal:switcher-preview';
const ORDER_KEY = 'tsportal:switcher-order';
const DETAIL_ROUTES = [ 'case', 'investigation', 'subject', 'transparency-report' ];

const SEED_KINDS = {
	case: 'case',
	investigation: 'investigation',
	subject: 'subject',
	'transparency-report': 'transparency'
};

const ICONS = {
	case: cdxIconFlag,
	investigation: cdxIconUserGroup,
	subject: cdxIconUserAvatar,
	sanction: cdxIconBlock,
	removal: cdxIconTrash
};

const EXTRAS = [
	{ value: 'mine', label: 'With me', icon: cdxIconUserAvatar },
	{ value: 'open', label: 'Only what is open', icon: cdxIconFlag }
];

const SECTIONS = [
	{ value: 'related', label: 'Related', icon: cdxIconLightbulb },
	{ value: 'recent', label: 'Recent', icon: cdxIconHistory },
	{ value: 'mine', label: 'Open, and yours', icon: cdxIconUserAvatar }
];

const appName = window.TSPortal?.appName ?? 'TSPortal';

const onMac = /Mac|iPhone|iPad/.test( navigator.platform ?? navigator.userAgent );
const metaKey = onMac ? '⌘' : 'Ctrl ';

const q = ref( '' );
const titlesOnly = ref( false );
const kinds = ref( [] );
const extras = ref( [] );

const rows = ref( [] );
const meta = ref( { engine: null, total: 0, degraded: false, full_text: false } );
const kindList = ref( [] );
const serverRecents = ref( [] );
const localRecents = ref( [] );
const related = ref( [] );
const relatedSeed = ref( null );

const order = ref( readOrder() );
const settingsOpen = ref( false );

const loading = ref( false );
const relatedLoading = ref( false );
const error = ref( false );

const activeKey = ref( null );
const previewWanted = ref( readPreference() );
const previews = new Map();
const preview = ref( null );
const previewLoading = ref( false );

const inputRef = ref( null );

const term = computed( () => q.value.trim() );
const fullText = computed( () => meta.value.full_text === true );
const placeholder = computed( () => `Search ${ appName } — a name, a reference, a phrase somebody wrote…` );

const filtered = computed(
	() => titlesOnly.value || kinds.value.length > 0 || extras.value.length > 0
);

const kindItems = computed( () => kindList.value.map( ( kind ) => ( {
	value: kind.kind,
	label: kind.plural
} ) ) );

const kindsLabel = computed( () => {
	if ( !kinds.value.length ) {
		return 'Everywhere';
	}

	const first = kindList.value.find( ( k ) => k.kind === kinds.value[ 0 ] );
	const label = first?.plural ?? kinds.value[ 0 ];

	return kinds.value.length === 1 ? label : `${ label } +${ kinds.value.length - 1 }`;
} );

const activeExtras = computed(
	() => EXTRAS.filter( ( extra ) => extras.value.includes( extra.value ) )
);

const spareExtras = computed( () => EXTRAS
	.filter( ( extra ) => !extras.value.includes( extra.value ) )
	.map( ( extra ) => ( { value: extra.value, label: extra.label, icon: extra.icon } ) ) );

const seed = computed( () => {
	const kind = SEED_KINDS[ route.name ];
	const id = Number( route.params.id );

	return kind && Number.isInteger( id ) && id > 0 ? { kind, id } : null;
} );

const orderedSections = computed( () => order.value
	.map( ( value ) => SECTIONS.find( ( section ) => section.value === value ) )
	.filter( Boolean ) );

const relatedLabel = computed( () => {
	const about = relatedSeed.value;

	return about ? `Related to ${ about.reference || about.title }` : 'Related';
} );

const groups = computed( () => ( term.value ? foundGroups() : idleGroups() ) );

const flat = computed( () => groups.value.flatMap( ( group ) => group.rows ) );

const activeRow = computed(
	() => flat.value.find( ( row ) => rowId( row ) === activeKey.value ) ?? flat.value[ 0 ] ?? null
);

const showPreview = computed( () => previewWanted.value );

const engineNote = computed( () => {
	if ( meta.value.degraded ) {
		return 'Full-text search is unavailable.';
	}

	if ( !term.value ) {
		return fullText.value ? 'Full-text search is available.' : '';
	}

	if ( titlesOnly.value ) {
		return 'Titles, references and account names.';
	}

	return fullText.value && meta.value.engine === 'opensearch'
		? `Full-text search · ${ meta.value.total } found`
		: 'Titles, summaries and comments.';
} );

function rowId( row ) {
	return `ts-switcher-${ row.kind }-${ row.id }`;
}

function isActive( row ) {
	return activeRow.value !== null && rowId( activeRow.value ) === rowId( row );
}

function makeActive( row ) {
	activeKey.value = rowId( row );
}

function icon( kind ) {
	return ICONS[ kind ] ?? cdxIconArticle;
}

function readPreference() {
	try {
		return window.localStorage.getItem( PREVIEW_KEY ) !== '0';
	} catch ( e ) {
		return true;
	}
}

function togglePreview() {
	previewWanted.value = !previewWanted.value;

	try {
		window.localStorage.setItem( PREVIEW_KEY, previewWanted.value ? '1' : '0' );
	} catch ( e ) { }
}

function addExtra( value ) {
	if ( value && !extras.value.includes( value ) ) {
		extras.value = [ ...extras.value, value ];
	}
}

function dropExtra( value ) {
	extras.value = extras.value.filter( ( extra ) => extra !== value );
}

function clearFilters() {
	titlesOnly.value = false;
	kinds.value = [];
	extras.value = [];
}

function foundGroups() {
	const order = kindList.value.length
		? kindList.value.map( ( kind ) => kind.kind )
		: [ ...new Set( rows.value.map( ( row ) => row.kind ) ) ];

	return order
		.map( ( kind ) => ( {
			key: kind,
			label: kindList.value.find( ( k ) => k.kind === kind )?.plural ?? kind,
			rows: rows.value.filter( ( row ) => row.kind === kind )
		} ) )
		.filter( ( group ) => group.rows.length );
}

function idleGroups() {
	const seen = new Set();

	const fresh = ( list ) => list.filter( ( row ) => {
		const key = `${ row.kind }:${ row.id }`;

		if ( seen.has( key ) ) {
			return false;
		}

		seen.add( key );

		return true;
	} );

	return order.value
		.flatMap( ( section ) => {
			if ( section === 'related' ) {
				return [ { key: 'related', label: relatedLabel.value, rows: fresh( related.value ) } ];
			}

			if ( section === 'mine' ) {
				return [ { key: 'mine', label: 'Open, and yours', rows: fresh( serverRecents.value ) } ];
			}

			return recentBuckets( fresh( localRecents.value ) );
		} )
		.filter( ( group ) => group.rows.length );
}

function recentBuckets( list ) {
	const buckets = [
		{ key: 'today', label: 'Today', rows: [] },
		{ key: 'week', label: 'Past week', rows: [] },
		{ key: 'earlier', label: 'Earlier', rows: [] }
	];

	for ( const row of list ) {
		const days = ( Date.now() - new Date( row.visited ).getTime() ) / 86400000;
		const bucket = days < 1 ? buckets[ 0 ] : ( days < 7 ? buckets[ 1 ] : buckets[ 2 ] );

		bucket.rows.push( { ...row, updated: row.updated ?? row.visited } );
	}

	return buckets;
}

function readOrder() {
	const known = SECTIONS.map( ( section ) => section.value );

	let saved = [];

	try {
		saved = ( window.localStorage.getItem( ORDER_KEY ) ?? '' ).split( ',' );
	} catch ( e ) {
		saved = [];
	}

	const kept = saved.filter(
		( value, at ) => known.includes( value ) && saved.indexOf( value ) === at
	);

	return [ ...kept, ...known.filter( ( value ) => !kept.includes( value ) ) ];
}

function moveSection( at, by ) {
	const to = at + by;

	if ( to < 0 || to >= order.value.length ) {
		return;
	}

	const next = [ ...order.value ];
	[ next[ at ], next[ to ] ] = [ next[ to ], next[ at ] ];

	order.value = next;

	try {
		window.localStorage.setItem( ORDER_KEY, next.join( ',' ) );
	} catch ( e ) { }
}

let timer = null;
let inFlight = null;
let requestId = 0;

function schedule() {
	clearTimeout( timer );

	if ( !term.value ) {
		inFlight?.abort();
		rows.value = [];
		loading.value = false;
		error.value = false;

		return;
	}

	loading.value = true;
	timer = setTimeout( run, 180 );
}

async function run() {
	const id = ++requestId;

	inFlight?.abort();
	inFlight = new AbortController();

	try {
		const response = await api.search(
			term.value,
			{
				kinds: kinds.value.join( ',' ) || null,
				titles_only: titlesOnly.value || null,
				mine: extras.value.includes( 'mine' ) || null,
				open_only: extras.value.includes( 'open' ) || null,
				limit: 25
			},
			{ signal: inFlight.signal }
		);

		if ( id !== requestId ) {
			return;
		}

		rows.value = response.data;
		meta.value = { ...meta.value, ...response.meta };
		error.value = false;
		activeKey.value = null;
	} catch ( e ) {
		if ( e.name === 'AbortError' || id !== requestId ) {
			return;
		}

		rows.value = [];
		error.value = true;
	} finally {
		if ( id === requestId ) {
			loading.value = false;
		}
	}
}

watch( q, schedule );
watch( titlesOnly, schedule );
watch( kinds, schedule, { deep: true } );
watch( extras, schedule, { deep: true } );

let previewTimer = null;
let previewRequest = 0;

watch( [ activeRow, showPreview ], () => {
	clearTimeout( previewTimer );

	const row = activeRow.value;

	if ( !showPreview.value || !row ) {
		preview.value = null;

		return;
	}

	const key = `${ row.kind }:${ row.id }`;

	if ( previews.has( key ) ) {
		preview.value = previews.get( key );
		previewLoading.value = false;

		return;
	}

	previewTimer = setTimeout( () => loadPreview( row, key ), 120 );
} );

async function loadPreview( row, key ) {
	const id = ++previewRequest;

	previewLoading.value = true;

	try {
		const response = await api.searchPreview( row.kind, row.id );

		if ( id !== previewRequest ) {
			return;
		}

		previews.set( key, response.data );
		preview.value = response.data;
	} catch ( e ) {
		if ( id === previewRequest ) {
			previews.set( key, null );
			preview.value = null;
		}
	} finally {
		if ( id === previewRequest ) {
			previewLoading.value = false;
		}
	}
}

let relatedRequest = 0;
let relatedInFlight = null;

async function loadRelated() {
	const id = ++relatedRequest;

	relatedInFlight?.abort();

	related.value = [];
	relatedSeed.value = null;

	const item = seed.value;

	if ( !item ) {
		relatedLoading.value = false;

		return;
	}

	relatedInFlight = new AbortController();
	relatedLoading.value = true;

	try {
		const response = await api.searchRelated(
			item.kind,
			item.id,
			{ limit: 5 },
			{ signal: relatedInFlight.signal }
		);

		if ( id !== relatedRequest ) {
			return;
		}

		related.value = response.data;
		relatedSeed.value = response.meta.seed;
	} catch ( e ) {
		if ( id === relatedRequest ) {
			related.value = [];
			relatedSeed.value = null;
		}
	} finally {
		if ( id === relatedRequest ) {
			relatedLoading.value = false;
		}
	}
}

async function loadRecents() {
	localRecents.value = recents();

	try {
		const response = await api.searchRecents();

		serverRecents.value = response.data;
		kindList.value = response.meta.kinds;
		meta.value = { ...meta.value, full_text: response.meta.full_text };
	} catch ( e ) {
		serverRecents.value = [];
	}
}

watch( () => props.open, ( isOpen ) => {
	if ( !isOpen ) {
		clearTimeout( timer );
		inFlight?.abort();
		relatedInFlight?.abort();

		return;
	}

	q.value = '';
	rows.value = [];
	error.value = false;
	activeKey.value = null;
	settingsOpen.value = false;
	previews.clear();
	preview.value = null;

	loadRecents();
	loadRelated();

	nextTick( () => inputRef.value?.focus() );
} );

function onGlobalKeydown( e ) {
	if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 'k' ) {
		e.preventDefault();
		emit( 'update:open', true );
	}
}

onMounted( () => window.addEventListener( 'keydown', onGlobalKeydown ) );

onUnmounted( () => {
	window.removeEventListener( 'keydown', onGlobalKeydown );
	clearTimeout( timer );
	clearTimeout( previewTimer );
	inFlight?.abort();
	relatedInFlight?.abort();
} );

function close( value ) {
	emit( 'update:open', value );
}

function move( by ) {
	const list = flat.value;

	if ( !list.length ) {
		return;
	}

	const at = list.findIndex( ( row ) => rowId( row ) === activeKey.value );
	const next = ( ( at === -1 ? 0 : at ) + by + list.length ) % list.length;

	activeKey.value = rowId( list[ next ] );

	nextTick( () => {
		document.getElementById( activeKey.value )?.scrollIntoView( { block: 'nearest' } );
	} );
}

function target( row ) {
	if ( !row?.route ) {
		return null;
	}

	return DETAIL_ROUTES.includes( row.route )
		? { name: row.route, params: { id: String( row.id ) } }
		: { name: row.route };
}

function go( row, newTab ) {
	const to = target( row );

	if ( !to ) {
		return;
	}

	remember( row );

	if ( newTab ) {
		window.open( router.resolve( to ).href, '_blank', 'noopener' );

		return;
	}

	router.push( to );
	close( false );
}

function onKeydown( e ) {
	if ( e.key === 'ArrowDown' ) {
		e.preventDefault();
		move( 1 );
	} else if ( e.key === 'ArrowUp' ) {
		e.preventDefault();
		move( -1 );
	} else if ( e.key === 'Enter' ) {
		e.preventDefault();
		if ( activeRow.value ) {
			go( activeRow.value, e.metaKey || e.ctrlKey );
		}
	} else if ( e.key === 'Escape' ) {
		close( false );
	}
}
</script>
