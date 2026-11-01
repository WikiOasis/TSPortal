<template>
	<main class="ts-page">
		<PageHeader
			title="Actions"
			:subtitle="meta.total ? `${ meta.total.toLocaleString() } ${ meta.total === 1 ? 'action matches' : 'actions match' }` : ''"
		/>

		<FilterBar
			v-model="filters"
			v-model:search="search"
			:fields="fields"
			:defaults="defaultFilters"
			:searchable="true"
			search-placeholder="Find an account or investigation"
			@update:search="debouncedReload"
			@change="go( 1 )"
		/>

		<LoadError :error="error" :retry="reload" />
		<cdx-progress-bar v-if="loading" aria-label="Loading actions" />

		<p v-if="!rows.length && !loading" class="ts-empty-state">No actions match.</p>

		<ul class="ts-acc">
			<li
				v-for="row in rows"
				:key="row.id"
				class="ts-acc__item"
				:class="{
					'ts-acc__item--open': isOpen( row.id ),
					'ts-acc__item--attention': needsAttention( row )
				}"
			>
				<button
					type="button"
					class="ts-acc__head"
					:aria-expanded="isOpen( row.id )"
					:aria-controls="`action-${ row.id }`"
					@click="toggle( row.id )"
				>
					<cdx-icon :icon="cdxIconNext" size="small" class="ts-acc__chevron" />
					<span class="ts-acc__title">
						<strong>{{ row.subject || row.where }}</strong>
						<span class="ts-meta">{{ row.label }}</span>
					</span>
					<span class="ts-acc__aside">
						<cdx-info-chip v-if="needsAttention( row )" status="error">
							{{ PUSH_LABELS[ row.push_state ] ?? row.push_state }}
						</cdx-info-chip>
						<cdx-info-chip :status="row.active ? 'success' : 'notice'">
							{{ row.active ? 'In force' : 'Not in force' }}
						</cdx-info-chip>
						<time :datetime="row.issued" :title="dateTime( row.issued )">{{ ago( row.issued ) }}</time>
					</span>
				</button>

				<div :id="`action-${ row.id }`" class="ts-acc__body" :inert="!isOpen( row.id )">
					<div>
						<div class="ts-acc__inner">
							<dl class="ts-facts">
								<dt>Reference</dt>
								<dd class="ts-mono">{{ row.reference }}</dd>

								<template v-if="row.investigation_id">
									<dt>From</dt>
									<dd>
										<router-link
											:to="{ name: 'investigation', params: { id: row.investigation_id } }"
											class="ts-cu-link"
										>
											<cdx-icon :icon="cdxIconLink" size="small" />
											<span class="ts-mono">{{ row.investigation }}</span>
											<span v-if="row.investigation_title"> — {{ row.investigation_title }}</span>
										</router-link>
									</dd>
								</template>

								<dt v-if="row.subject_id">Account</dt>
								<dd v-if="row.subject_id">
									<router-link :to="{ name: 'subject', params: { id: row.subject_id } }">{{ row.subject }}</router-link>
								</dd>

								<dt>Where</dt>
								<dd class="ts-mono">{{ row.where }}</dd>
								<dt>Given by</dt>
								<dd>{{ row.issuer || 'unknown' }} · {{ dateTime( row.issued ) }}</dd>
								<dt>Ends</dt>
								<dd>{{ row.expires ? dateTime( row.expires ) : 'no end date' }}</dd>
								<template v-if="row.reason_category_label">
									<dt>Category</dt>
									<dd>{{ row.reason_category_label }}</dd>
								</template>
								<dt>Reason</dt>
								<dd class="ts-prose">{{ row.reason }}</dd>
								<dt>On the wiki</dt>
								<dd>
									<StatusChip kind="push" :value="row.push_state" />
									<p v-if="row.push_error" class="ts-meta">{{ row.push_error }}</p>
								</dd>
							</dl>

							<div class="ts-inline">
								<cdx-button
									v-if="row.can_acknowledge"
									:disabled="acknowledging === row.id"
									@click="acknowledge( row )"
								>
									{{ acknowledging === row.id ? 'Marking…' : 'Mark as done by hand' }}
								</cdx-button>

								<cdx-button
									v-if="row.active && canLift( row )"
									@click="startLift( row )"
								>
									Lift this action
								</cdx-button>
								<span v-else-if="row.active" class="ts-meta">Lifting this needs the admin flag.</span>
							</div>
						</div>
					</div>
				</div>
			</li>
		</ul>

		<div v-if="meta.last_page > 1" class="ts-pager">
			<cdx-button :disabled="meta.current_page <= 1" @click="go( meta.current_page - 1 )">Previous</cdx-button>
			<span class="ts-meta">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
			<cdx-button :disabled="meta.current_page >= meta.last_page" @click="go( meta.current_page + 1 )">Next</cdx-button>
		</div>

		<cdx-dialog
			v-model:open="showLift"
			title="Lift this action"
			:use-close-button="true"
			:primary-action="{
				label: lifting ? 'Lifting…' : 'Lift it',
				actionType: 'destructive',
				disabled: !liftReason.trim() || lifting
			}"
			:default-action="{ label: 'Cancel' }"
			@primary="lift"
			@default="showLift = false"
		>
			<div class="ts-stack">
				<p>
					<strong>{{ liftTarget?.label }}</strong> on
					<strong>{{ liftTarget?.subject || liftTarget?.where }}</strong>
					will be taken off the wiki and the account told.
				</p>
				<cdx-field>
					<template #label>Why</template>
					<cdx-text-area v-model="liftReason" rows="3" />
				</cdx-field>
			</div>
		</cdx-dialog>
	</main>
</template>

<script setup>
import { inject, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
	CdxButton, CdxDialog, CdxField, CdxIcon, CdxInfoChip, CdxProgressBar, CdxTextArea
} from '@wikimedia/codex';
import { cdxIconAlert, cdxIconBlock, cdxIconFlag, cdxIconLink, cdxIconNext } from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import FilterBar from '../components/FilterBar.vue';
import StatusChip from '../components/StatusChip.vue';
import { api } from '../lib/api.js';
import { ago, dateTime, PUSH_LABELS, SANCTION_TYPES } from '../lib/format.js';
import { session } from '../lib/session.js';

const route = useRoute();
const router = useRouter();
const notify = inject( 'notify' );

const rows = ref( [] );
const meta = ref( { current_page: 1, last_page: 1, total: 0 } );
const loading = ref( false );
const error = ref( null );
const acknowledging = ref( null );
const page = ref( 1 );
const open = ref( [] );

const showLift = ref( false );
const lifting = ref( false );
const liftTarget = ref( null );
const liftReason = ref( '' );

const SCOPES = [ 'active', 'all', 'broken', 'partial', 'manual', 'failed', 'acknowledged' ];

const defaultFilters = { scope: 'active' };

const filters = ref( {
	...( route.query.scope === 'all' ? {} : { scope: SCOPES.includes( route.query.scope ) ? route.query.scope : 'active' } ),
	...( route.query.type ? { type: String( route.query.type ).split( ',' ) } : {} )
} );
const search = ref( typeof route.query.q === 'string' ? route.query.q : '' );

const fields = [
	{
		key: 'scope',
		label: 'Show',
		icon: cdxIconFlag,
		options: [
			{ value: 'active', label: 'In force' },
			{ value: 'broken', label: 'Not carried out' },
			{ value: 'partial', label: 'Recorded, not enforced' },
			{ value: 'manual', label: 'Needs doing by hand' },
			{ value: 'failed', label: 'Failed to send' },
			{ value: 'acknowledged', label: 'Done by hand' }
		]
	},
	{
		key: 'type',
		label: 'Kind',
		icon: cdxIconBlock,
		multi: true,
		options: SANCTION_TYPES.filter( ( t ) => !t.hidden ).map( ( t ) => ( { value: t.value, label: t.label } ) )
	}
];

function needsAttention( row ) {
	return [ 'partial', 'failed', 'manual' ].includes( row.push_state );
}

function canLift( row ) {
	return ![ 'lock', 'wiki-deletion' ].includes( row.type ) || session.can( 'admin' );
}

function isOpen( id ) {
	return open.value.includes( id );
}

function toggle( id ) {
	open.value = isOpen( id ) ? open.value.filter( ( i ) => i !== id ) : [ ...open.value, id ];
}

async function reload() {
	loading.value = true;
	error.value = null;

	const f = filters.value;
	const params = { page: page.value, q: search.value || undefined, type: f.type?.join( ',' ) || undefined };

	if ( f.scope === 'active' ) {
		params.active = 1;
	} else if ( f.scope === 'broken' ) {
		params.push_state = 'partial,failed,manual';
	} else if ( f.scope ) {
		params.push_state = f.scope;
	}

	const query = {};
	query.scope = f.scope ?? 'all';
	if ( query.scope === 'active' ) {
		delete query.scope;
	}
	if ( f.type?.length ) {
		query.type = f.type.join( ',' );
	}
	if ( search.value ) {
		query.q = search.value;
	}
	router.replace( { query } );

	try {
		const response = await api.sanctions( params );
		rows.value = response.data;
		meta.value = response.meta;
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

let timer = null;
function debouncedReload() {
	clearTimeout( timer );
	timer = setTimeout( () => go( 1 ), 300 );
}

function go( to ) {
	page.value = to;
	reload();
}

async function acknowledge( row ) {
	acknowledging.value = row.id;
	try {
		await api.acknowledgeSanction( row.id );
		row.push_state = 'acknowledged';
		row.can_acknowledge = false;
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		acknowledging.value = null;
	}
}

function startLift( row ) {
	liftTarget.value = row;
	liftReason.value = '';
	showLift.value = true;
}

async function lift() {
	if ( !liftReason.value.trim() || lifting.value ) {
		return;
	}

	lifting.value = true;

	try {
		await api.liftSanction( liftTarget.value.id, { reason: liftReason.value } );
		showLift.value = false;
		notify( `${ liftTarget.value.reference } lifted.` );
		await reload();
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		lifting.value = false;
	}
}

onMounted( reload );
</script>
