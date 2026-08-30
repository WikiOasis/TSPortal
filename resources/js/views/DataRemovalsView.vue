<template>
	<main class="ts-page">
		<PageHeader
			title="Erasures"
			subtitle="Removing an account's personal data from the wikis. The one thing here that cannot be undone."
		/>

		<cdx-message v-if="enabled === false" type="warning" :allow-user-dismiss="false">
			Erasure is disabled on this portal (<code>MW_PII_ENABLED</code>). Requests can be
			created and approved, but nothing will go to the farm.
		</cdx-message>

		<cdx-message v-if="waiting.length" type="notice" :allow-user-dismiss="false">
			{{ waiting.length }} request{{ waiting.length === 1 ? '' : 's' }} created but not
			started.
		</cdx-message>

		<div class="ts-toolbar">
			<cdx-field>
				<template #label>Show</template>
				<cdx-select v-model:selected="scope" :menu-items="scopeOptions" @update:selected="reload" />
			</cdx-field>
		</div>

		<LoadError :error="error" :retry="reload" />
		<cdx-progress-bar v-if="loading" aria-label="Loading erasures" />

		<div class="ts-stack">
			<p v-if="!rows.length && !loading" class="ts-empty">Nothing matches.</p>

			<div v-for="r in rows" :key="r.id" class="ts-panel ts-stack">
				<div class="ts-row">
					<div class="ts-inline">
						<span class="ts-mono">{{ r.reference }}</span>
						<strong>{{ r.account }}</strong>
						<StatusChip kind="removal" :value="r.state" />
					</div>
					<span class="ts-meta">asked for {{ ago( r.requested_at ) }}</span>
				</div>

				<dl class="ts-dl">
					<dt>Becomes</dt>
					<dd class="ts-mono">{{ r.becomes }}</dd>
					<dt>Basis</dt>
					<dd>{{ basisLabel( r.legal_basis ) }}</dd>
					<dt>Asked for by</dt>
					<dd>{{ r.requested_by || 'unknown' }}</dd>
					<dt v-if="r.approved_by">{{ r.state === 'refused' ? 'Refused by' : 'Carried out by' }}</dt>
					<dd v-if="r.approved_by">{{ r.approved_by }} · {{ dateTime( r.approved_at ) }}</dd>
					<dt>Why</dt>
					<dd class="ts-prose">{{ r.reason }}</dd>
					<template v-if="r.refusal_reason">
						<dt>Refused because</dt>
						<dd>{{ r.refusal_reason }}</dd>
					</template>
					<template v-if="r.case">
						<dt>Data request</dt>
						<dd>
							<router-link :to="{ name: 'case', params: { id: r.case_id } }" class="ts-mono">
								{{ r.case }}
							</router-link>
						</dd>
					</template>
					<template v-if="r.investigation">
						<dt>File</dt>
						<dd>
							<router-link :to="{ name: 'investigation', params: { id: r.investigation_id } }" class="ts-mono">
								{{ r.investigation }}
							</router-link>
						</dd>
					</template>
				</dl>

				<div v-if="r.wikis.pending.length || r.wikis.finished.length">
					<div class="ts-meta">
						{{ r.wikis.finished.length }} of
						{{ r.wikis.finished.length + r.wikis.pending.length }} wikis done
					</div>
					<div class="ts-inline">
						<cdx-info-chip v-for="w in r.wikis.finished" :key="`d-${ w }`" status="success">
							{{ w }}
						</cdx-info-chip>
						<cdx-info-chip v-for="w in r.wikis.pending" :key="`p-${ w }`">
							{{ w }}
						</cdx-info-chip>
						<cdx-info-chip
							v-for="( why, w ) in r.wikis.failures"
							:key="`f-${ w }`"
							status="error"
						>
							{{ w }}
						</cdx-info-chip>
					</div>
				</div>

				<cdx-message
					v-if="r.waiting"
					type="notice"
					:inline="true"
					:allow-user-dismiss="false"
				>
					Waiting on the wiki{{ r.waiting_on ? `: ${ r.waiting_on }` : '' }}. Trying again
					{{ r.next_attempt_at ? ago( r.next_attempt_at ) : 'shortly' }}.
				</cdx-message>

				<cdx-message
					v-else-if="r.error"
					type="error"
					:inline="true"
					:allow-user-dismiss="false"
				>
					{{ r.error }}
				</cdx-message>

				<div class="ts-inline">
					<cdx-button
						v-if="r.may_erase"
						action="destructive"
						weight="primary"
						@click="startErase( r )"
					>
						Erase this account
					</cdx-button>

					<cdx-button v-if="r.may_erase" @click="startRefuse( r )">Refuse</cdx-button>

					<cdx-button
						v-if="r.state === 'failed'"
						@click="retry( r )"
					>
						Try again
					</cdx-button>
				</div>
			</div>
		</div>

		<div v-if="meta.last_page > 1" class="ts-pager">
			<cdx-button :disabled="meta.current_page <= 1" @click="go( meta.current_page - 1 )">Previous</cdx-button>
			<span class="ts-meta">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
			<cdx-button :disabled="meta.current_page >= meta.last_page" @click="go( meta.current_page + 1 )">Next</cdx-button>
		</div>

		<cdx-dialog
			v-model:open="showErase"
			title="Erase this account"
			:use-close-button="true"
			:primary-action="{
				label: starting ? 'Starting…' : 'Erase it now',
				actionType: 'destructive',
				disabled: starting
			}"
			:default-action="{ label: 'Cancel' }"
			@primary="erase"
			@default="showErase = false"
		>
			<div class="ts-stack">
				<cdx-message type="error" :allow-user-dismiss="false">
					<p>This cannot be undone and there is no attempt to build an undo.</p>
					<p>
						<strong>{{ erasing?.account }}</strong> will be renamed to
						<span class="ts-mono">{{ erasing?.becomes }}</span> across every wiki it is
						attached to; its user pages will be deleted with suppression; real
						name, IP records and check-user entries will be removed or blanked.
					</p>
				</cdx-message>
			</div>
		</cdx-dialog>

		<cdx-dialog
			v-model:open="showRefuse"
			title="Refuse this erasure"
			:use-close-button="true"
			:primary-action="{ label: 'Refuse it', actionType: 'progressive', disabled: !refusal.trim() }"
			:default-action="{ label: 'Cancel' }"
			@primary="refuse"
			@default="showRefuse = false"
		>
			<div class="ts-stack">
				<p>
					The request stays on file as refused, with your reason. Somebody asked for their
					data to be removed and is owed an answer either way.
				</p>
				<cdx-field>
					<template #label>Why</template>
					<cdx-text-area v-model="refusal" rows="3" />
				</cdx-field>
			</div>
		</cdx-dialog>
	</main>
</template>

<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import {
	CdxButton, CdxDialog, CdxField, CdxInfoChip, CdxMessage,
	CdxProgressBar, CdxSelect, CdxTextArea
} from '@wikimedia/codex';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import StatusChip from '../components/StatusChip.vue';
import { api } from '../lib/api.js';
import { ago, dateTime, LEGAL_BASES } from '../lib/format.js';

const notify = inject( 'notify' );

const rows = ref( [] );
const meta = ref( { current_page: 1, last_page: 1 } );
const loading = ref( false );
const error = ref( null );
const enabled = ref( null );
const scope = ref( 'outstanding' );
const page = ref( 1 );

const showErase = ref( false );
const starting = ref( false );
const showRefuse = ref( false );
const erasing = ref( null );
const refusal = ref( '' );

const scopeOptions = [
	{ value: 'outstanding', label: 'Not finished' },
	{ value: '', label: 'Everything' },
	{ value: 'requested', label: 'Written down, not started' },
	{ value: 'failed', label: 'Failed' },
	{ value: 'done', label: 'Done' }
];

const waiting = computed( () => rows.value.filter( ( r ) => r.state === 'requested' ) );

function basisLabel( value ) {
	return LEGAL_BASES.find( ( b ) => b.value === value )?.label ?? value ?? 'not recorded';
}

async function reload() {
	loading.value = true;
	error.value = null;

	const params = { page: page.value };
	if ( scope.value === 'outstanding' ) {
		params.outstanding = 1;
	} else if ( scope.value ) {
		params.state = scope.value;
	}

	try {
		const response = await api.removals( params );
		rows.value = response.data;
		meta.value = response.meta;
		enabled.value = response.enabled;
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

function go( to ) {
	page.value = to;
	reload();
}

function startErase( removal ) {
	erasing.value = removal;
	showErase.value = true;
}

function startRefuse( removal ) {
	erasing.value = removal;
	refusal.value = '';
	showRefuse.value = true;
}

async function erase() {
	if ( starting.value ) {
		return;
	}

	starting.value = true;

	try {
		const response = await api.eraseRemoval( erasing.value.id );
		showErase.value = false;
		notify(
			response.error
				? `Started, but it has not got far: ${ response.error }`
				: 'Started. The rename goes out within the minute.',
			response.error ? 'warning' : 'success'
		);
		await reload();
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		starting.value = false;
	}
}

async function refuse() {
	try {
		await api.refuseRemoval( erasing.value.id, { reason: refusal.value } );
		showRefuse.value = false;
		notify( 'Refused, and the reason is on the record.' );
		await reload();
	} catch ( e ) {
		notify( e.message, 'error' );
	}
}

async function retry( removal ) {
	try {
		await api.retryRemoval( removal.id );
		notify( 'Started again.' );
		await reload();
	} catch ( e ) {
		notify( e.message, 'error' );
	}
}

onMounted( reload );
</script>
