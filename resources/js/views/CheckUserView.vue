<template>
	<main class="ts-page">
		<PageHeader
			title="CheckUser log"
			:subtitle="meta.total ? `${ meta.total.toLocaleString() } ${ meta.total === 1 ? 'check' : 'checks' }` : ''"
		/>

		<FilterBar
			v-model="filters"
			:fields="fields"
			@change="go( 1 )"
		/>

		<LoadError :error="error" :retry="reload" />
		<cdx-progress-bar v-if="loading" aria-label="Loading checks" />

		<p v-if="!rows.length && !loading" class="ts-empty-state">No checks match.</p>

		<section v-for="group in groups" :key="group.day" class="ts-cu-day">
			<h2 class="ts-group-label">
				{{ group.label }} <span class="ts-group-label__count">{{ group.rows.length }}</span>
			</h2>

			<ul class="ts-cu-list">
				<li v-for="row in group.rows" :key="row.id ?? `${ row.checked_at }-${ row.checker }-${ row.target }`" class="ts-cu-row">
					<time class="ts-cu-row__time" :datetime="row.checked_at" :title="dateTime( row.checked_at )">
						{{ time( row.checked_at ) }}
					</time>

					<div class="ts-cu-row__main">
						<div class="ts-cu-row__line">
							<button type="button" class="ts-linkish ts-cu-row__who" @click="addFilter( 'checker', row.checker )">
								{{ row.checker }}
							</button>
							<cdx-info-chip>{{ row.type_label }}</cdx-info-chip>
							<button
								type="button"
								class="ts-linkish"
								:class="{ 'ts-mono': !row.target_name }"
								@click="addFilter( 'target', row.target )"
							>
								{{ row.target }}
							</button>
							<span v-if="row.target_kind" class="ts-meta">({{ targetLabel( row.target_kind ).toLowerCase() }})</span>
						</div>
						<p v-if="row.reason_given" class="ts-cu-row__reason">{{ row.reason }}</p>
						<p v-else class="ts-cu-row__reason ts-cu-row__reason--none">No reason given</p>
					</div>

					<button type="button" class="ts-cu-row__wiki ts-mono" @click="addFilter( 'wiki', row.wiki )">
						{{ row.wiki }}
					</button>
				</li>
			</ul>
		</section>

		<div v-if="meta.last_page > 1" class="ts-pager">
			<cdx-button :disabled="meta.current_page <= 1" @click="go( meta.current_page - 1 )">Previous</cdx-button>
			<span class="ts-meta">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
			<cdx-button :disabled="meta.current_page >= meta.last_page" @click="go( meta.current_page + 1 )">Next</cdx-button>
		</div>
	</main>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { CdxButton, CdxInfoChip, CdxProgressBar } from '@wikimedia/codex';
import { cdxIconArticle, cdxIconSearch, cdxIconUserAvatar, cdxIconAlert } from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import LoadError from '../components/LoadError.vue';
import FilterBar from '../components/FilterBar.vue';
import { api } from '../lib/api.js';
import { dateTime, CHECK_TARGET_LABELS, CHECK_TYPE_LABELS } from '../lib/format.js';

const route = useRoute();
const router = useRouter();

const rows = ref( [] );
const meta = ref( { current_page: 1, last_page: 1, total: 0, unexplained: 0 } );
const loading = ref( false );
const error = ref( null );
const page = ref( 1 );

const filters = ref( Object.fromEntries( [
	[ 'wiki', route.query.wiki ],
	[ 'checker', route.query.checker ],
	[ 'target', route.query.target ],
	[ 'unexplained', route.query.unexplained ? true : undefined ]
].filter( ( [ , v ] ) => v ) ) );

const fields = [
	{ key: 'checker', label: 'Checker', icon: cdxIconUserAvatar, text: true, placeholder: 'Username' },
	{ key: 'target', label: 'Target', icon: cdxIconSearch, text: true, placeholder: 'Name or fingerprint' },
	{ key: 'wiki', label: 'Wiki', icon: cdxIconArticle, text: true, placeholder: 'Wiki ID' },
	{ key: 'unexplained', label: 'No reason given', icon: cdxIconAlert, boolean: true }
];

const dayFormat = new Intl.DateTimeFormat( undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } );
const timeFormat = new Intl.DateTimeFormat( undefined, { hour: '2-digit', minute: '2-digit' } );

function time( value ) {
	return timeFormat.format( new Date( value ) );
}

function dayLabel( value ) {
	const d = new Date( value );
	const today = new Date();
	const yesterday = new Date( Date.now() - 86400000 );

	if ( d.toDateString() === today.toDateString() ) {
		return 'Today';
	}
	if ( d.toDateString() === yesterday.toDateString() ) {
		return 'Yesterday';
	}
	return dayFormat.format( d );
}

const groups = computed( () => {
	const out = [];

	for ( const row of rows.value ) {
		const day = new Date( row.checked_at ).toDateString();
		let group = out[ out.length - 1 ];

		if ( !group || group.day !== day ) {
			group = { day, label: dayLabel( row.checked_at ), rows: [] };
			out.push( group );
		}
		group.rows.push( row );
	}

	return out;
} );

function targetLabel( kind ) {
	return CHECK_TARGET_LABELS[ kind ] ?? kind;
}

function addFilter( field, value ) {
	filters.value = { ...filters.value, [ field ]: value };
	go( 1 );
}

function go( to ) {
	page.value = to;
	reload();
}

async function reload() {
	loading.value = true;
	error.value = null;

	const params = {
		page: page.value,
		wiki: filters.value.wiki || undefined,
		checker: filters.value.checker || undefined,
		target: filters.value.target || undefined,
		unexplained: filters.value.unexplained ? 1 : undefined
	};

	router.replace( { query: { ...params, page: undefined } } );

	try {
		const response = await api.checkUserChecks( params );
		rows.value = response.data.map( ( row ) => ( {
			...row,
			type_label: row.type_label ?? CHECK_TYPE_LABELS[ row.type ] ?? row.type
		} ) );
		meta.value = response.meta;
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

onMounted( reload );
</script>
