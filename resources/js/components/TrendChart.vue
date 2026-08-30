<template>
	<figure class="ts-chart">
		<figcaption class="ts-chart__caption">
			<span class="ts-chart__title">{{ title }}</span>
			<span v-if="subtitle" class="ts-meta">{{ subtitle }}</span>
		</figcaption>

		<div v-if="!points.length" class="ts-empty">Nothing in this period.</div>

		<div
			v-else
			ref="plot"
			class="ts-chart__plot"
			@mousemove="track"
			@mouseleave="hover = null"
			@touchstart.passive="track"
			@touchmove.passive="track"
		>
			<svg
				:viewBox="`0 0 ${ WIDTH } ${ HEIGHT }`"
				preserveAspectRatio="none"
				class="ts-chart__svg"
				role="img"
				:aria-label="`${ title }. ${ describe }`"
			>
				<line
					v-for="tick in ticks"
					:key="tick.value"
					class="ts-chart__grid"
					x1="0"
					:y1="tick.y"
					:x2="WIDTH"
					:y2="tick.y"
				/>

				<path class="ts-chart__area" :d="area" />
				<path class="ts-chart__line" :d="line" />

				<circle class="ts-chart__end" :cx="last.x" :cy="last.y" r="4" />

				<line
					v-if="hover"
					class="ts-chart__crosshair"
					:x1="hover.x"
					y1="0"
					:x2="hover.x"
					:y2="HEIGHT"
				/>
				<circle v-if="hover" class="ts-chart__end" :cx="hover.x" :cy="hover.y" r="4" />
			</svg>

			<div class="ts-chart__ticks" aria-hidden="true">
				<span v-for="tick in ticks" :key="tick.value" :style="{ top: `${ tick.y / HEIGHT * 100 }%` }">
					{{ tick.value.toLocaleString() }}
				</span>
			</div>

			<div
				v-if="hover"
				class="ts-chart__tooltip"
				:style="tooltipStyle"
				role="status"
			>
				<strong>{{ hover.total.toLocaleString() }}</strong>
				<span class="ts-meta">{{ bucketLabel( hover.bucket ) }}</span>
			</div>
		</div>

		<div v-if="points.length" class="ts-chart__axis">
			<span>{{ bucketLabel( points[ 0 ].bucket ) }}</span>
			<span>{{ bucketLabel( points[ points.length - 1 ].bucket ) }}</span>
		</div>
	</figure>
</template>

<script setup>
import { computed, ref } from 'vue';
import { date } from '../lib/format.js';

const props = defineProps( {
	title: { type: String, required: true },
	subtitle: { type: String, default: '' },
	data: { type: Array, default: () => [] },
	bucket: { type: String, default: 'day' }
} );

const WIDTH = 600;
const HEIGHT = 160;

const PAD_TOP = 12;
const PAD_BOTTOM = 8;

const plot = ref( null );
const hover = ref( null );

const scale = computed( () => niceScale(
	Math.max( ...( props.data ?? [] ).map( ( r ) => r.total ), 1 )
) );

const points = computed( () => {
	const rows = props.data ?? [];
	if ( !rows.length ) {
		return [];
	}

	const { top } = scale.value;
	const step = rows.length > 1 ? WIDTH / ( rows.length - 1 ) : 0;
	const usable = HEIGHT - PAD_TOP - PAD_BOTTOM;

	return rows.map( ( row, i ) => ( {
		...row,
		x: rows.length > 1 ? i * step : WIDTH / 2,
		y: PAD_TOP + usable - ( row.total / top ) * usable
	} ) );
} );

const last = computed( () => points.value[ points.value.length - 1 ] ?? { x: 0, y: 0 } );

const tooltipStyle = computed( () => {
	if ( !hover.value ) {
		return {};
	}

	const fraction = hover.value.x / WIDTH;

	return {
		left: `calc( var( --ts-chart-gutter ) + ( 100% - var( --ts-chart-gutter ) ) * ${ fraction.toFixed( 4 ) } )`,
		transform: `translateX( -${ ( fraction * 100 ).toFixed( 1 ) }% )`
	};
} );

const line = computed( () => points.value
	.map( ( p, i ) => `${ i === 0 ? 'M' : 'L' }${ p.x.toFixed( 1 ) } ${ p.y.toFixed( 1 ) }` )
	.join( ' ' ) );

const area = computed( () => {
	if ( !points.value.length ) {
		return '';
	}
	const first = points.value[ 0 ];
	return `${ line.value } L${ last.value.x.toFixed( 1 ) } ${ HEIGHT } L${ first.x.toFixed( 1 ) } ${ HEIGHT } Z`;
} );

const ticks = computed( () => {
	const { step, top } = scale.value;
	const usable = HEIGHT - PAD_TOP - PAD_BOTTOM;
	const rows = [];

	for ( let value = 0; value <= top; value += step ) {
		rows.push( { value, y: PAD_TOP + usable - ( value / top ) * usable } );
	}

	return rows;
} );

function niceScale( peak ) {
	for ( let power = 0; power <= 12; power++ ) {
		for ( const multiple of [ 1, 2, 5 ] ) {
			const step = multiple * 10 ** power;
			const top = Math.max( step, Math.ceil( peak / step ) * step );

			if ( top / step <= 5 ) {
				return { step, top };
			}
		}
	}

	return { step: peak, top: peak };
}

const describe = computed( () => {
	const rows = props.data ?? [];
	if ( !rows.length ) {
		return 'No data.';
	}
	const total = rows.reduce( ( sum, r ) => sum + r.total, 0 );
	return `${ total.toLocaleString() } in total between ${ bucketLabel( rows[ 0 ].bucket ) } and ` +
		`${ bucketLabel( rows[ rows.length - 1 ].bucket ) }.`;
} );

function track( event ) {
	const box = plot.value?.getBoundingClientRect();
	if ( !box || !points.value.length ) {
		return;
	}

	const clientX = event.touches ? event.touches[ 0 ].clientX : event.clientX;
	const x = ( ( clientX - box.left ) / box.width ) * WIDTH;

	hover.value = points.value.reduce(
		( closest, p ) => ( Math.abs( p.x - x ) < Math.abs( closest.x - x ) ? p : closest ),
		points.value[ 0 ]
	);
}

function bucketLabel( key ) {
	return props.bucket === 'month'
		? new Date( key ).toLocaleDateString( undefined, { month: 'short', year: 'numeric' } )
		: date( key );
}
</script>
