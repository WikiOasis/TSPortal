<template>
	<main class="ts-page ts-ar">
		<PageHeader
			title="Automation"
			:subtitle="subtitle"
		>
			<template #actions>
				<span v-if="tallyTotal" class="ts-meta" aria-live="polite">
					This session: {{ tally.closed }} closed · {{ tally.taken }} taken · {{ tally.moved }} moved
				</span>
				<cdx-menu-button
					v-model:selected="menuChoice"
					:menu-items="menuItems"
					:disabled="working"
					aria-label="More automation actions"
					@update:selected="onMenu"
				>
					<cdx-icon :icon="cdxIconEllipsis" />
				</cdx-menu-button>
				<cdx-button v-tooltip="'Keyboard shortcuts (?)'" aria-label="Keyboard shortcuts" @click="showKeys = true">
					<cdx-icon :icon="cdxIconKeyboard" />
				</cdx-button>
			</template>
		</PageHeader>

		<cdx-message v-if="loaded && !summary.enabled" type="warning" :allow-user-dismiss="false">
			The AI is switched off because <code>OPENROUTER_API_KEY</code> is not set, so new flags aren't
			being sorted. Anything already sorted can still be reviewed here.
		</cdx-message>

		<cdx-tabs v-model:active="view" class="ts-ar-tabs" @update:active="reload">
			<cdx-tab
				v-for="tab in tabs"
				:key="tab.value"
				:name="tab.value"
				:label="`${ tab.label } (${ ( tab.count ?? 0 ).toLocaleString() })`"
			>
				<template v-if="tab.value === view">
					<div class="ts-toolbar ts-ar-toolbar">
						<cdx-field v-if="mode === 'focus'" class="ts-toolbar__search">
							<template #label>Find</template>
							<cdx-search-input
								v-model="q"
								placeholder="Page, editor, reference or reason"
								@update:model-value="debouncedReload"
							/>
						</cdx-field>

						<cdx-field v-if="mode === 'focus'">
							<template #label>Order</template>
							<cdx-select v-model:selected="sort" :menu-items="sortOptions" @update:selected="reload" />
						</cdx-field>

						<cdx-field>
							<template #label>Show</template>
							<cdx-toggle-button-group
								v-model="mode"
								:buttons="modeButtons"
								@update:model-value="reload"
							/>
						</cdx-field>
					</div>

					<cdx-checkbox v-if="mode === 'focus'" v-model="showTaken" class="ts-ar-taken" @update:model-value="reload">
						Include flags another person has taken
					</cdx-checkbox>

					<div v-if="groupFilter" class="ts-inline ts-ar-filter">
						<span class="ts-meta">Only</span>
						<cdx-info-chip>{{ groupFilter }}</cdx-info-chip>
						<cdx-button weight="quiet" size="small" @click="clearGroupFilter">Show everything</cdx-button>
					</div>

					<div v-if="closeBatch.items.length" class="ts-panel ts-bulkbar">
						<span class="ts-bulkbar__count">
							{{ closeBatch.items.length.toLocaleString() }} in the close batch
						</span>
						<cdx-button v-tooltip="'Shortcut: B'" action="progressive" weight="primary" @click="showBatch = true">
							Check and close
						</cdx-button>
						<cdx-button weight="quiet" @click="closeBatch.clear()">Empty the batch</cdx-button>
					</div>

					<LoadError :error="error" :retry="reload" />

					<template v-if="mode === 'focus'">
						<cdx-progress-bar v-if="loading && !items.length" aria-label="Loading the flags" />

						<div v-else-if="!items.length && loaded" class="ts-panel ts-empty ts-stack">
							<template v-if="view === 'waiting' && counts.waiting && summary.enabled">
								<p>{{ counts.queued ? `The AI is sorting ${ counts.queued.toLocaleString() } now.` : 'These are waiting for the AI.' }}</p>
								<cdx-button v-if="!counts.queued" action="progressive" @click="classifyWaiting( false )">Sort them now</cdx-button>
							</template>
							<template v-else>
								<p>{{ view === 'all' ? 'No open flags.' : 'Nothing left in this bucket.' }}</p>
								<cdx-button v-if="nextTab" @click="setView( nextTab.value )">
									Go to {{ nextTab.label.toLowerCase() }}
								</cdx-button>
							</template>
						</div>

						<div v-else class="ts-ar-layout">
							<ol :ref="setListRef" class="ts-ar-list ts-panel" aria-label="Flags">
								<li v-for="( row, i ) in items" :key="row.id">
									<button
										type="button"
										class="ts-ar-row"
										:class="{
											'ts-ar-row--current': i === index,
											'ts-ar-row--batched': closeBatch.has( row.id )
										}"
										:aria-current="i === index ? 'true' : undefined"
										@click="select( i )"
									>
										<span class="ts-ar-row__main">
											<span class="ts-ar-row__title">{{ row.review?.page_title ?? row.subject }}</span>
											<span class="ts-meta">
												{{ row.review?.author ?? 'unknown editor' }} · {{ ago( row.filed ) }}
												<template v-if="row.review?.confidence !== null && row.review?.confidence !== undefined">
													· {{ percent( row.review.confidence ) }} sure
												</template>
											</span>
											<span v-if="row.review?.reason" class="ts-ar-row__reason">{{ row.review.reason }}</span>
											<span
												v-if="closeBatch.has( row.id ) || row.assignee || row.review?.facts?.reverted || ( view === 'all' && bucket( row.review?.bucket ) )"
												class="ts-inline ts-ar-row__chips"
											>
												<cdx-info-chip v-if="closeBatch.has( row.id )" status="success">In the batch</cdx-info-chip>
												<cdx-info-chip v-if="view === 'all' && bucket( row.review?.bucket )" :status="bucket( row.review.bucket ).chip">
													{{ bucket( row.review.bucket ).short }}
												</cdx-info-chip>
												<cdx-info-chip v-if="row.review?.facts?.reverted">Reverted</cdx-info-chip>
												<cdx-info-chip v-if="row.assignee" status="warning">With {{ row.assignee.username }}</cdx-info-chip>
											</span>
										</span>
									</button>
								</li>
								<li v-if="next !== null" class="ts-ar-list__more">
									<cdx-button weight="quiet" :disabled="loading" @click="loadMore">
										{{ loading ? 'Loading…' : `Load ${ Math.min( 50, total - items.length ).toLocaleString() } more` }}
									</cdx-button>
								</li>
							</ol>

							<section v-if="currentRow" class="ts-ar-pane ts-panel" aria-label="The flag being reviewed">
								<div class="ts-ar-pane__nav">
									<span class="ts-meta">
										{{ index + 1 }} of {{ total.toLocaleString() }}
										<template v-if="hiddenTaken"> · {{ hiddenTaken }} hidden because another person took {{ hiddenTaken === 1 ? 'it' : 'them' }}</template>
									</span>
									<span class="ts-inline">
										<cdx-button v-tooltip="'Previous (K)'" weight="quiet" :disabled="index === 0" aria-label="Previous flag" @click="move( -1 )">
											<cdx-icon :icon="cdxIconPrevious" />
										</cdx-button>
										<cdx-button v-tooltip="'Next (J)'" weight="quiet" :disabled="index >= items.length - 1" aria-label="Next flag" @click="move( 1 )">
											<cdx-icon :icon="cdxIconNext" />
										</cdx-button>
									</span>
								</div>

								<AutoReviewDetail :row="currentRow" :loading="detailLoading" />

								<div class="ts-ar-actions">
									<div class="ts-inline">
										<cdx-button
											v-if="agree"
											v-tooltip="'Shortcut: Y'"
											action="progressive"
											weight="primary"
											:disabled="busy || ( agree.kind === 'take' && takenByOther )"
											@click="doAgree"
										>
											{{ agree.label }}
										</cdx-button>
										<cdx-button
											v-else-if="summary.enabled && currentRow.review?.state !== 'queued'"
											action="progressive"
											:disabled="busy"
											@click="sortThis"
										>
											Ask the AI now
										</cdx-button>

										<cdx-button v-if="agree?.kind !== 'batch'" v-tooltip="'Shortcut: X'" :disabled="busy" @click="toggleBatch">
											{{ closeBatch.has( currentRow.id ) ? 'Take out of the batch' : 'Add to close batch' }}
										</cdx-button>
										<cdx-button v-if="agree?.kind !== 'take'" v-tooltip="'Shortcut: T'" :disabled="busy || takenByOther" @click="takeIt">
											Take it
										</cdx-button>
										<cdx-button v-tooltip="'Shortcut: C'" action="destructive" :disabled="busy" @click="closeNow">
											Close now
										</cdx-button>
									</div>
									<div class="ts-inline">
										<span class="ts-meta">Not right? Move it to</span>
										<cdx-button
											v-for="b in BUCKETS"
											:key="b.value"
											v-tooltip="`Shortcut: ${ b.key }`"
											size="small"
											weight="quiet"
											:disabled="busy || currentRow.review?.bucket === b.value"
											@click="moveTo( b.value )"
										>
											{{ b.label }}
										</cdx-button>
									</div>
								</div>
							</section>
						</div>
					</template>

					<template v-else>
						<cdx-progress-bar v-if="groupsLoading" aria-label="Grouping the flags" />

						<div v-else-if="!groups.length" class="ts-panel ts-empty">
							No {{ mode === 'page' ? 'page' : 'editor' }} has more than one open flag here.
						</div>

						<div v-else class="ts-panel ts-scroll ts-bulk-table">
							<table>
								<thead>
									<tr>
										<th>{{ mode === 'page' ? 'Page' : 'Editor' }}</th>
										<th>Flags</th>
										<th>The AI says</th>
										<th>{{ mode === 'page' ? 'Editors' : 'Pages' }}</th>
										<th>Latest</th>
										<th><span class="ts-visually-hidden">Actions</span></th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="group in groups" :key="`${ group.wiki }|${ group.name }`">
										<td :data-label="mode === 'page' ? 'Page' : 'Editor'">
											<strong>{{ group.name }}</strong>
											<div class="ts-meta ts-mono">{{ group.wiki }}</div>
										</td>
										<td data-label="Flags">{{ group.total }}</td>
										<td data-label="The AI says">
											<span class="ts-inline">
												<cdx-info-chip v-if="group.urgent" status="error">{{ group.urgent }} quickly</cdx-info-chip>
												<cdx-info-chip v-if="group.review" status="warning">{{ group.review }} review</cdx-info-chip>
												<cdx-info-chip v-if="group.unlikely" status="success">{{ group.unlikely }} unlikely</cdx-info-chip>
												<cdx-info-chip v-if="group.waiting">{{ group.waiting }} waiting</cdx-info-chip>
											</span>
										</td>
										<td :data-label="mode === 'page' ? 'Editors' : 'Pages'">{{ group.spread }}</td>
										<td data-label="Latest">{{ ago( group.newest ) }}</td>
										<td data-label="">
											<span class="ts-inline">
												<cdx-button size="small" action="progressive" @click="reviewGroup( group )">Review</cdx-button>
												<cdx-button size="small" :disabled="working" @click="batchGroup( group )">Add all to batch</cdx-button>
												<cdx-button size="small" weight="quiet" :disabled="working" @click="mergeTarget = group">Merge</cdx-button>
											</span>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</template>
				</template>
			</cdx-tab>
		</cdx-tabs>

		<section class="ts-section">
			<details class="ts-panel ts-details" @toggle="onStatsToggle">
				<summary>How well is the AI sorting?</summary>
				<cdx-progress-bar v-if="statsLoading" :inline="true" aria-label="Loading" />
				<div v-else-if="stats" class="ts-stack">
					<p class="ts-meta">
						{{ stats.classified.toLocaleString() }} sorted
						<template v-if="stats.cost"> · about ${{ stats.cost.toFixed( 2 ) }} so far</template>
						<template v-if="stats.last_classified"> · last {{ ago( stats.last_classified ) }}</template>.
						“Agreed” means a person closed an “unlikely” flag, or took or acted on one the AI said needed review.
						Keep an eye on “unlikely” flags that were acted on: those are the ones the AI missed.
					</p>
					<cdx-table
						caption="How staff treated each bucket"
						:hide-caption="true"
						:columns="statsColumns"
						:data="stats.buckets"
					>
						<template #item-label="{ item }">{{ item }}</template>
						<template #item-agreed="{ row }">
							{{ row.agreed }}
							<span v-if="decided( row )" class="ts-meta">({{ Math.round( row.agreed / decided( row ) * 100 ) }}%)</span>
						</template>
						<template #item-moved="{ row }">
							{{ row.moved }}
							<span v-if="row.moved" class="ts-meta">({{ movedSummary( row ) }})</span>
						</template>
						<template #item-acted="{ row }">
							<cdx-info-chip v-if="row.bucket === 'unlikely' && row.acted" status="error">{{ row.acted }}</cdx-info-chip>
							<template v-else>{{ row.acted }}</template>
						</template>
					</cdx-table>
				</div>
			</details>
		</section>

		<div class="ts-visually-hidden" role="status" aria-live="polite">{{ spoken }}</div>

		<CloseBatchDialog v-model:open="showBatch" @closed="onBatchClosed" />

		<cdx-dialog v-model:open="showKeys" title="Keyboard shortcuts" :use-close-button="true">
			<cdx-table
				caption="Keyboard shortcuts"
				:hide-caption="true"
				:columns="[ { id: 'keys', label: 'Key', width: '7rem' }, { id: 'what', label: 'Does' } ]"
				:data="KEYS"
			>
				<template #item-keys="{ item }"><span class="ts-mono">{{ item }}</span></template>
			</cdx-table>
		</cdx-dialog>

		<cdx-dialog
			:open="!!mergeTarget"
			title="Merge into one report"
			:subtitle="mergeTarget ? `${ mergeTarget.total } open flags for ${ mergeTarget.name } become duplicates of the oldest.` : ''"
			:use-close-button="true"
			:primary-action="{ label: working ? 'Merging…' : 'Merge them', actionType: 'destructive', disabled: working }"
			:default-action="{ label: 'Cancel' }"
			@update:open="( v ) => { if ( !v ) mergeTarget = null; }"
			@primary="confirmMerge"
			@default="mergeTarget = null"
		>
			<p>
				They'll be answered together on one report. Use this when the flags are really one problem,
				such as the same vandal hitting a page again and again.
			</p>
		</cdx-dialog>
	</main>
</template>

<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
	CdxButton, CdxCheckbox, CdxDialog, CdxField, CdxIcon, CdxInfoChip, CdxMenuButton, CdxMessage,
	CdxProgressBar, CdxSearchInput, CdxSelect, CdxTab, CdxTable, CdxTabs, CdxToggleButtonGroup, CdxTooltip
} from '@wikimedia/codex';
import { cdxIconEllipsis, cdxIconKeyboard, cdxIconNext, cdxIconPrevious } from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import AutoReviewDetail from '../components/AutoReviewDetail.vue';
import CloseBatchDialog from '../components/CloseBatchDialog.vue';
import { api } from '../lib/api.js';
import { BUCKETS, VIEWS, agreement, bucket, closeBatch, isTyping, percent, tally } from '../lib/autoreview.js';
import { ago } from '../lib/format.js';
import { session } from '../lib/session.js';

const vTooltip = CdxTooltip;

const route = useRoute();
const router = useRouter();
const notify = inject( 'notify' );

const KEYS = [
	{ keys: 'Y', what: 'Agree with the AI: add an “unlikely” flag to the close batch, or take one that needs a person' },
	{ keys: 'X', what: 'Add to the close batch (or take out), then go to the next' },
	{ keys: 'T', what: 'Take it: assign it to you and open the case' },
	{ keys: 'C', what: 'Close this one now with no action' },
	{ keys: '1 / 2 / 3', what: 'Disagree: move to needs review quickly / needs review / unlikely' },
	{ keys: 'J / ↓', what: 'Next flag' },
	{ keys: 'K / ↑', what: 'Previous flag' },
	{ keys: 'Shift A', what: 'Add every flag loaded in the list to the close batch' },
	{ keys: 'B', what: 'Check the close batch and close it' },
	{ keys: 'Enter', what: 'Open the case without taking it' },
	{ keys: 'O', what: 'Open the edit on the wiki in a new tab' },
	{ keys: '?', what: 'Show these shortcuts' }
];

const PER_PAGE = 50;

const summary = reactive( { enabled: true, model: '', counts: {} } );
const counts = computed( () => summary.counts ?? {} );
const loaded = ref( false );
const error = ref( null );
const loading = ref( false );
const working = ref( false );
const busy = ref( false );

const view = ref( [ ...VIEWS.map( ( v ) => v.value ), 'all' ].includes( route.query.view ) ? route.query.view : null );
const mode = ref( [ 'focus', 'page', 'editor' ].includes( route.query.mode ) ? route.query.mode : 'focus' );
const sort = ref( [ 'confidence', 'oldest', 'newest', 'page', 'editor' ].includes( route.query.sort ) ? route.query.sort : 'confidence' );
const q = ref( typeof route.query.q === 'string' ? route.query.q : '' );
const showTaken = ref( route.query.taken === 'show' );
const filter = reactive( {
	wiki: typeof route.query.wiki === 'string' ? route.query.wiki : null,
	page: typeof route.query.page === 'string' ? route.query.page : null,
	author: typeof route.query.author === 'string' ? route.query.author : null
} );
const focusCase = ref( route.query.case ? Number( route.query.case ) : null );

const items = ref( [] );
const total = ref( 0 );
const next = ref( null );
const index = ref( 0 );
const listRef = ref( null );

function setListRef( el ) {
	listRef.value = el;
}

const statsColumns = [
	{ id: 'label', label: 'The AI said' },
	{ id: 'total', label: 'Sorted', textAlign: 'number' },
	{ id: 'agreed', label: 'A person agreed' },
	{ id: 'moved', label: 'A person moved it' },
	{ id: 'acted', label: 'Acted on' },
	{ id: 'open', label: 'Still open', textAlign: 'number' }
];

const subtitle = computed( () => {
	if ( !loaded.value ) {
		return 'Work through Jev\'s flags with the AI\'s read on each one.';
	}
	const open = ( counts.value.open ?? 0 ).toLocaleString();
	return summary.enabled
		? `Work through Jev's flags with the AI's read on each one. ${ open } open, sorted by ${ summary.model }.`
		: `Work through Jev's flags with the AI's read on each one. ${ open } open.`;
} );
const details = reactive( {} );
const detailLoading = ref( false );

const groups = ref( [] );
const groupsLoading = ref( false );
const mergeTarget = ref( null );

const stats = ref( null );
const statsLoading = ref( false );

const showBatch = ref( false );
const showKeys = ref( false );
const menuChoice = ref( null );
const spoken = ref( '' );

const modeButtons = [
	{ value: 'focus', label: 'One at a time' },
	{ value: 'page', label: 'By page' },
	{ value: 'editor', label: 'By editor' }
];

const sortOptions = [
	{ value: 'confidence', label: 'Surest first' },
	{ value: 'oldest', label: 'Oldest first' },
	{ value: 'newest', label: 'Newest first' },
	{ value: 'page', label: 'Page by page' },
	{ value: 'editor', label: 'Editor by editor' }
];

const tabs = computed( () => [
	...VIEWS.filter( ( v ) => v.value !== 'failed' || counts.value.failed ).map( ( v ) => ( { ...v, count: counts.value[ v.value ] } ) ),
	{ value: 'all', label: 'All', short: 'All', count: counts.value.open }
] );

const currentTab = computed( () => tabs.value.find( ( t ) => t.value === view.value ) ?? null );

const nextTab = computed( () => BUCKETS.find( ( b ) => b.value !== view.value && counts.value[ b.value ] ) ?? null );

const menuItems = computed( () => [
	...( summary.enabled && counts.value.waiting
		? [ { value: 'sort', label: `Ask the AI to sort ${ counts.value.waiting.toLocaleString() } waiting` } ]
		: [] ),
	...( summary.enabled && counts.value.failed
		? [ { value: 'retry', label: `Retry ${ counts.value.failed.toLocaleString() } that failed` } ]
		: [] ),
	{ value: 'fold', label: 'Merge repeat flags on the same edit' },
	{ value: 'queue', label: 'See these in the queue' }
] );

const tallyTotal = computed( () => tally.closed + tally.taken + tally.moved );

const groupFilter = computed( () => {
	if ( filter.page ) {
		return `${ filter.page } on ${ filter.wiki }`;
	}
	if ( filter.author ) {
		return `edits by ${ filter.author } on ${ filter.wiki }`;
	}
	return null;
} );

const currentRow = computed( () => {
	const row = items.value[ index.value ];
	return row ? details[ row.id ] ?? row : null;
} );

const agree = computed( () => agreement( currentRow.value ) );

const hiddenTaken = computed( () => {
	if ( showTaken.value || q.value || filter.page || filter.author || !currentTab.value ) {
		return 0;
	}
	return Math.max( 0, ( currentTab.value.count ?? 0 ) - total.value );
} );

const takenByOther = computed( () => {
	const assignee = currentRow.value?.assignee;
	return !!assignee && assignee.id !== session.user?.id;
} );

function announce( text ) {
	spoken.value = '';
	nextTick( () => {
		spoken.value = text;
	} );
}

function syncUrl() {
	router.replace( {
		query: {
			view: view.value,
			mode: mode.value === 'focus' ? undefined : mode.value,
			sort: sort.value === 'confidence' ? undefined : sort.value,
			q: q.value || undefined,
			taken: showTaken.value ? 'show' : undefined,
			wiki: filter.wiki || undefined,
			page: filter.page || undefined,
			author: filter.author || undefined,
			case: items.value[ index.value ]?.id ?? undefined
		}
	} ).catch( () => {} );
}

async function loadSummary() {
	try {
		Object.assign( summary, await api.autoReview() );
		loaded.value = true;
	} catch ( e ) {
		error.value = e;
	}
}

function params( cursor ) {
	return {
		view: view.value,
		sort: sort.value,
		q: q.value,
		wiki: filter.wiki,
		page: filter.page,
		author: filter.author,
		taken: showTaken.value ? 'show' : null,
		per_page: PER_PAGE,
		cursor: cursor || null
	};
}

let loadToken = 0;

async function reload() {
	if ( mode.value !== 'focus' ) {
		syncUrl();
		return loadGroups();
	}

	const token = ++loadToken;
	loading.value = true;
	error.value = null;

	try {
		const response = await api.autoReviewItems( params( null ) );
		if ( token !== loadToken ) {
			return;
		}
		items.value = response.data;
		total.value = response.meta.total;
		next.value = response.meta.next;
		index.value = 0;

		if ( focusCase.value ) {
			const at = items.value.findIndex( ( r ) => r.id === focusCase.value );
			if ( at !== -1 ) {
				index.value = at;
			} else if ( details[ focusCase.value ] ) {
				items.value.unshift( details[ focusCase.value ] );
				total.value++;
			}
			focusCase.value = null;
		}

		listRef.value?.scrollTo?.( { top: 0 } );
		scrollToCurrent();
		fetchDetail();
		syncUrl();
	} catch ( e ) {
		error.value = e;
	} finally {
		if ( token === loadToken ) {
			loading.value = false;
		}
	}
}

async function loadMore() {
	if ( next.value === null || loading.value ) {
		return;
	}
	loading.value = true;
	try {
		const response = await api.autoReviewItems( params( next.value ) );
		const known = new Set( items.value.map( ( r ) => r.id ) );
		items.value.push( ...response.data.filter( ( r ) => !known.has( r.id ) ) );
		total.value = response.meta.total;
		next.value = response.meta.next;
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		loading.value = false;
	}
}

const inflight = new Map();

function prefetch( row ) {
	if ( !row || details[ row.id ] ) {
		return Promise.resolve();
	}
	if ( inflight.has( row.id ) ) {
		return inflight.get( row.id );
	}
	const promise = api.autoReviewItem( row.id )
		.then( ( response ) => {
			details[ row.id ] = response.data;
		} )
		.catch( () => {} )
		.finally( () => inflight.delete( row.id ) );
	inflight.set( row.id, promise );
	return promise;
}

async function fetchDetail() {
	const row = items.value[ index.value ];
	if ( !row ) {
		return;
	}
	if ( !details[ row.id ] ) {
		detailLoading.value = true;
		await prefetch( row );
		if ( items.value[ index.value ]?.id === row.id ) {
			detailLoading.value = false;
		}
	} else {
		detailLoading.value = false;
	}
	prefetch( items.value[ index.value + 1 ] );
	prefetch( items.value[ index.value + 2 ] );
}

function scrollToCurrent() {
	nextTick( () => {
		const list = listRef.value;
		const row = list?.querySelector( '.ts-ar-row--current' );
		if ( !list || !row ) {
			return;
		}
		const top = row.getBoundingClientRect().top - list.getBoundingClientRect().top + list.scrollTop;
		const bottom = top + row.offsetHeight;
		if ( top < list.scrollTop ) {
			list.scrollTop = top - 4;
		} else if ( bottom > list.scrollTop + list.clientHeight ) {
			list.scrollTop = bottom - list.clientHeight + 4;
		}
	} );
}

function select( i ) {
	index.value = Math.max( 0, Math.min( items.value.length - 1, i ) );
	scrollToCurrent();
	fetchDetail();
	syncUrl();
	if ( items.value.length - index.value < 10 ) {
		loadMore();
	}
}

function move( step ) {
	select( index.value + step );
}

function dropCurrent() {
	const row = items.value[ index.value ];
	if ( !row ) {
		return;
	}
	items.value.splice( index.value, 1 );
	total.value = Math.max( 0, total.value - 1 );
	delete details[ row.id ];
	if ( index.value >= items.value.length ) {
		index.value = Math.max( 0, items.value.length - 1 );
	}
	select( index.value );
}

function toggleBatch() {
	const row = currentRow.value;
	if ( !row ) {
		return;
	}
	const added = closeBatch.toggle( row );
	announce( added ? `${ row.reference } added to the close batch.` : `${ row.reference } taken out of the close batch.` );
	if ( added ) {
		move( 1 );
	}
}

function batchAllLoaded() {
	closeBatch.add( items.value );
	notify( `${ items.value.length } added to the close batch.` );
}

async function closeNow() {
	const row = currentRow.value;
	if ( !row || busy.value ) {
		return;
	}
	busy.value = true;
	try {
		const response = await api.autoReviewClose( { case_ids: [ row.id ] } );
		summary.counts = response.counts;
		closeBatch.remove( [ row.id ] );
		if ( response.closed.length ) {
			tally.closed++;
			announce( `${ row.reference } closed with no action.` );
		} else {
			notify( `${ row.reference } was already closed by someone else.` );
		}
		dropCurrent();
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		busy.value = false;
	}
}

async function takeIt() {
	const row = currentRow.value;
	if ( !row || busy.value ) {
		return;
	}
	busy.value = true;
	try {
		const response = await api.autoReviewTake( row.id );
		summary.counts = response.counts;
		tally.taken++;
		closeBatch.remove( [ row.id ] );
		router.push( { name: 'case', params: { id: row.id } } );
	} catch ( e ) {
		notify( e.message, 'error' );
		if ( e.code === 'cannot-take' ) {
			delete details[ row.id ];
			fetchDetail();
		}
	} finally {
		busy.value = false;
	}
}

function doAgree() {
	if ( !agree.value ) {
		return;
	}
	if ( agree.value.kind === 'batch' ) {
		if ( !closeBatch.has( currentRow.value.id ) ) {
			toggleBatch();
		} else {
			move( 1 );
		}
		return;
	}
	takeIt();
}

async function moveTo( bucketValue ) {
	const row = currentRow.value;
	if ( !row || busy.value || row.review?.bucket === bucketValue ) {
		return;
	}
	busy.value = true;
	try {
		const response = await api.autoReviewBucket( row.id, bucketValue );
		summary.counts = response.counts;
		tally.moved++;
		const label = BUCKETS.find( ( b ) => b.value === bucketValue )?.label.toLowerCase();
		announce( `${ row.reference } moved to ${ label }.` );
		if ( view.value === 'all' ) {
			details[ row.id ] = { ...( details[ row.id ] ?? row ), review: { ...response.data.review, evidence: details[ row.id ]?.review?.evidence } };
			items.value[ index.value ] = response.data;
			move( 1 );
		} else {
			notify( `${ row.reference } moved to ${ label }.` );
			dropCurrent();
		}
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		busy.value = false;
	}
}

async function sortThis() {
	const row = currentRow.value;
	if ( !row ) {
		return;
	}
	busy.value = true;
	try {
		const response = await api.autoReviewClassify( { case_ids: [ row.id ] } );
		summary.counts = response.counts;
		notify( 'Sent to the AI. It will move to its bucket when the answer comes back.' );
		startPolling();
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		busy.value = false;
	}
}

function openCase() {
	const row = currentRow.value;
	if ( row ) {
		router.push( { name: 'case', params: { id: row.id } } );
	}
}

function openOnWiki() {
	const link = currentRow.value?.review?.links?.revision;
	if ( link ) {
		window.open( link, '_blank', 'noopener' );
	}
}

function setView( value ) {
	view.value = value;
	reload();
}

function clearGroupFilter() {
	filter.wiki = null;
	filter.page = null;
	filter.author = null;
	reload();
}

let debounce = null;
function debouncedReload() {
	clearTimeout( debounce );
	debounce = setTimeout( reload, 300 );
}

async function loadGroups() {
	groupsLoading.value = true;
	error.value = null;
	try {
		groups.value = ( await api.autoReviewGroups( { by: mode.value, view: view.value } ) ).data;
	} catch ( e ) {
		error.value = e;
	} finally {
		groupsLoading.value = false;
	}
}

function splitParts( group ) {
	return [ 'urgent', 'review', 'unlikely', 'waiting' ]
		.filter( ( key ) => group[ key ] )
		.map( ( key ) => ( { key, value: group[ key ] } ) );
}

function splitTitle( group ) {
	const words = { urgent: 'quickly', review: 'review', unlikely: 'unlikely', waiting: 'waiting' };
	return splitParts( group ).map( ( p ) => `${ p.value } ${ words[ p.key ] }` ).join( ' · ' );
}

function groupParams( group ) {
	return mode.value === 'page'
		? { wiki: group.wiki, page: group.name, author: null }
		: { wiki: group.wiki, author: group.name, page: null };
}

function reviewGroup( group ) {
	Object.assign( filter, groupParams( group ) );
	mode.value = 'focus';
	sort.value = 'oldest';
	reload();
}

async function groupRows( group ) {
	const rows = [];
	let cursor = null;
	do {
		const response = await api.autoReviewItems( { view: view.value, ...groupParams( group ), per_page: 200, cursor } );
		rows.push( ...response.data );
		cursor = response.meta.next;
	} while ( cursor !== null && rows.length < 2000 );
	return rows;
}

async function batchGroup( group ) {
	working.value = true;
	try {
		const rows = await groupRows( group );
		closeBatch.add( rows );
		notify( `${ rows.length } flags for ${ group.name } added to the close batch.` );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		working.value = false;
	}
}

async function confirmMerge() {
	const group = mergeTarget.value;
	if ( !group ) {
		return;
	}
	working.value = true;
	try {
		const rows = await groupRows( group );
		const response = await api.autoReviewMerge( {
			case_ids: rows.map( ( r ) => r.id ),
			note: `Flags for ${ group.name } on ${ group.wiki } merged in Automation.`
		} );
		summary.counts = response.counts;
		closeBatch.remove( rows.map( ( r ) => r.id ).filter( ( id ) => id !== response.into.id ) );
		notify( `${ response.merged } merged into ${ response.into.reference }.` );
		mergeTarget.value = null;
		await loadGroups();
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		working.value = false;
	}
}

async function classifyWaiting( failed ) {
	working.value = true;
	try {
		const response = await api.autoReviewClassify( { failed } );
		summary.counts = response.counts;
		notify( response.queued
			? `${ response.queued.toLocaleString() } sent to the AI. They'll appear in their buckets as answers come back.`
			: 'Nothing needed sorting.' );
		startPolling();
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		working.value = false;
	}
}

async function fold() {
	working.value = true;
	try {
		const response = await api.autoReviewFold();
		summary.counts = response.counts;
		notify( response.folded
			? `${ response.folded } repeat flags on the same edit merged into the first.`
			: 'No edit had been flagged more than once.' );
		if ( response.folded ) {
			await reload();
		}
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		working.value = false;
	}
}

function onMenu( value ) {
	menuChoice.value = null;
	if ( value === 'sort' ) {
		classifyWaiting( false );
	} else if ( value === 'retry' ) {
		classifyWaiting( true );
	} else if ( value === 'fold' ) {
		fold();
	} else if ( value === 'queue' ) {
		router.push( { name: 'queue', query: { source: view.value && view.value !== 'all' && view.value !== 'failed' ? `automated:${ view.value }` : 'automated' } } );
	}
}

function onBatchClosed( { ids, counts: fresh } ) {
	if ( fresh ) {
		summary.counts = fresh;
	}
	tally.closed += ids.length;
	const gone = new Set( ids );
	const current = items.value[ index.value ]?.id;
	items.value = items.value.filter( ( r ) => !gone.has( r.id ) );
	total.value = Math.max( 0, total.value - ids.length );
	const stillThere = items.value.findIndex( ( r ) => r.id === current );
	select( stillThere === -1 ? Math.min( index.value, items.value.length - 1 ) : stillThere );
	if ( mode.value !== 'focus' ) {
		loadGroups();
	}
}

async function loadStats() {
	statsLoading.value = true;
	try {
		stats.value = await api.autoReviewStats();
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		statsLoading.value = false;
	}
}

function onStatsToggle( event ) {
	if ( event.target.open ) {
		loadStats();
	}
}

function decided( row ) {
	return row.bucket === 'unlikely' ? row.no_action + row.acted + row.moved : row.agreed + row.no_action + row.moved;
}

function movedSummary( row ) {
	return BUCKETS
		.filter( ( b ) => row.moved_to[ b.value ] )
		.map( ( b ) => `${ row.moved_to[ b.value ] } to ${ b.short.toLowerCase() }` )
		.join( ', ' );
}

let poll = null;
function startPolling() {
	clearInterval( poll );
	poll = setInterval( async () => {
		await loadSummary();
		if ( !counts.value.queued ) {
			clearInterval( poll );
			poll = null;
			if ( mode.value === 'focus' && !items.value.length ) {
				reload();
			}
		}
	}, 8000 );
}

function onKeydown( event ) {
	if ( showBatch.value || showKeys.value || mergeTarget.value || event.metaKey || event.ctrlKey || event.altKey || isTyping( event ) ) {
		return;
	}

	if ( event.key === '?' ) {
		event.preventDefault();
		showKeys.value = true;
		return;
	}

	if ( event.key === 'b' || event.key === 'B' ) {
		if ( closeBatch.items.length ) {
			event.preventDefault();
			showBatch.value = true;
		}
		return;
	}

	if ( mode.value !== 'focus' || !currentRow.value ) {
		return;
	}

	const actions = {
		j: () => move( 1 ),
		ArrowDown: () => move( 1 ),
		k: () => move( -1 ),
		ArrowUp: () => move( -1 ),
		y: doAgree,
		x: toggleBatch,
		t: () => !takenByOther.value && takeIt(),
		A: batchAllLoaded,
		c: closeNow,
		Enter: openCase,
		o: openOnWiki,
		1: () => moveTo( 'urgent' ),
		2: () => moveTo( 'review' ),
		3: () => moveTo( 'unlikely' )
	};

	const action = actions[ event.key ];
	if ( action ) {
		if ( event.key === 'Enter' && event.target instanceof Element && event.target.closest( 'button, a, summary' ) ) {
			return;
		}
		event.preventDefault();
		action();
	}
}

watch( () => counts.value.queued, ( queued ) => {
	if ( queued && !poll ) {
		startPolling();
	}
} );

onMounted( async () => {
	window.addEventListener( 'keydown', onKeydown );

	await loadSummary();

	if ( focusCase.value ) {
		try {
			const response = await api.autoReviewItem( focusCase.value );
			details[ focusCase.value ] = response.data;
			view.value ??= response.data.review?.bucket ?? ( response.data.review?.state === 'failed' ? 'failed' : 'waiting' );
		} catch ( e ) {
			focusCase.value = null;
		}
	}

	if ( view.value === null ) {
		view.value = [ 'urgent', 'review', 'unlikely', 'waiting' ].find( ( v ) => counts.value[ v ] ) ?? 'urgent';
	}

	await reload();
} );

onUnmounted( () => {
	window.removeEventListener( 'keydown', onKeydown );
	clearInterval( poll );
	clearTimeout( debounce );
} );
</script>
