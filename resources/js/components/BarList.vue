<template>
	<figure class="ts-chart">
		<figcaption class="ts-chart__caption">
			<span class="ts-chart__title">{{ title }}</span>
			<span v-if="subtitle" class="ts-meta">{{ subtitle }}</span>
		</figcaption>

		<ul v-if="showsOverlay" class="ts-chart__legend">
			<li><span class="ts-chart__key ts-chart__key--main" />{{ label }}</li>
			<li><span class="ts-chart__key ts-chart__key--overlay" />{{ overlayLabel }}</li>
		</ul>

		<div v-if="!rows.length" class="ts-empty">{{ emptyText }}</div>

		<ol v-else class="ts-barlist">
			<li v-for="row in rows" :key="row.key ?? '—'" class="ts-barlist__row">
				<span class="ts-barlist__label" :title="row.label">{{ row.label }}</span>

				<span class="ts-barlist__track">
					<span
						class="ts-barlist__bar"
						:style="{ width: `${ width( row.total ) }%` }"
						:title="`${ row.label }: ${ row.total.toLocaleString() }`"
                    />

					<span
						v-if="showsOverlay && row.overlay"
						class="ts-barlist__bar ts-barlist__bar--overlay"
						:style="{ width: `${ width( row.overlay ) }%` }"
						:title="`${ row.label }: ${ row.overlay.toLocaleString() } ${ overlayLabel.toLowerCase() }`"
					/>
				</span>

				<span class="ts-barlist__value">
					{{ row.total.toLocaleString() }}
					<em v-if="showsOverlay && row.overlay">/ {{ row.overlay.toLocaleString() }}</em>
				</span>
			</li>
		</ol>

		<p v-if="withheld" class="ts-meta ts-barlist__note">{{ withheld }}</p>
	</figure>
</template>

<script setup>
import { computed } from 'vue';


const props = defineProps( {
	title: { type: String, required: true },
	subtitle: { type: String, default: '' },
	data: { type: Array, default: () => [] },
	label: { type: String, default: 'Total' },
	overlayLabel: { type: String, default: '' },
	emptyText: { type: String, default: 'Nothing in this period.' },
	limit: { type: Number, default: 12 },
	withheld: { type: String, default: '' },
	nullLabel: { type: String, default: 'Not recorded' }
} );

const rows = computed( () => ( props.data ?? [] )
	.map( ( row ) => ( {
		key: row.key ?? null,
		label: row.label ?? ( row.key === null || row.key === undefined
			? props.nullLabel
			: String( row.key ) ),
		total: Number( row.total ?? 0 ),
		overlay: Number( row.overlay ?? 0 )
	} ) )
	.sort( ( a, b ) => b.total - a.total )
	.slice( 0, props.limit ) );

const max = computed( () => Math.max( ...rows.value.map( ( r ) => r.total ), 1 ) );

const showsOverlay = computed( () =>
	props.overlayLabel !== '' && rows.value.some( ( row ) => row.overlay > 0 ) );

function width( value ) {
	return Math.max( 1, ( value / max.value ) * 100 );
}
</script>
