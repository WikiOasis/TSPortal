<template>
	<section class="ts-feed" :aria-label="heading">
		<div class="ts-feed__toolbar">
			<h2 class="ts-section__title ts-feed__heading">{{ heading }}</h2>
			<cdx-toggle-button v-if="kind === 'case'" v-model="publicOnly" class="ts-feed__filter">
				<cdx-icon :icon="cdxIconEye" size="small" />
				What the reporter has seen
			</cdx-toggle-button>
		</div>

		<cdx-progress-bar v-if="loading && !entries.length" aria-label="Loading the history" />

		<p v-else-if="!groups.length" class="ts-meta">
			{{ publicOnly ? 'The reporter hasn\'t been sent anything yet.' : 'Nothing has happened yet.' }}
		</p>

		<ol v-else class="ts-feed__list">
			<li v-for="group in groups" :key="group.key" class="ts-feed__group">
				<ul v-if="group.events.length" class="ts-feed__events">
					<li
						v-for="( ev, i ) in group.events"
						:key="i"
						class="ts-feed__event"
						:class="`ts-feed__event--${ tone( ev ) }`"
					>
						<span class="ts-feed__event-icon" aria-hidden="true">
							<cdx-icon :icon="icon( ev )" size="x-small" />
						</span>
						<div class="ts-feed__event-main">
							<div class="ts-feed__event-line">
								<strong v-if="ev.actor && !( ev.kind === 'status-note' && i > 0 )" class="ts-feed__actor">{{ actorOf( ev ) }}</strong>
								<span>{{ sentence( ev ) }}</span>
								<router-link v-if="link( ev )" :to="link( ev )" class="ts-mono">
									{{ ev.meta.reference || 'open' }}
								</router-link>
								<span v-if="kind === 'case' && ev.visibility === 'internal' && ev.kind !== 'audit'" class="ts-feed__lock" title="Not shown to the reporter">
									<cdx-icon :icon="cdxIconLock" size="x-small" />
								</span>
								<time class="ts-meta" :datetime="ev.at" :title="dateTime( ev.at )">{{ ago( ev.at ) }}</time>
							</div>

							<RichText v-if="ev.body" class="ts-feed__event-body" :text="ev.body" />

							<dl v-if="ev.kind === 'action'" class="ts-dl ts-feed__facts">
								<template v-if="ev.meta.account">
									<dt>Against</dt>
									<dd>{{ ev.meta.account }}</dd>
								</template>
								<template v-if="ev.meta.scope">
									<dt>Where</dt>
									<dd>{{ ev.meta.scope }}</dd>
								</template>
								<dt>Ends</dt>
								<dd>{{ ev.meta.expires ? dateTime( ev.meta.expires ) : 'no end date' }}</dd>
								<dt>On the wiki</dt>
								<dd><StatusChip kind="push" :value="ev.meta.push_state" /></dd>
								<template v-if="ev.meta.internal_reason">
									<dt>Internal</dt>
									<dd><RichText :text="ev.meta.internal_reason" /></dd>
								</template>
							</dl>
						</div>
					</li>
				</ul>

				<article
					v-if="group.message"
					:id="idOf( group.message ) ? `message-${ idOf( group.message ) }` : undefined"
					class="ts-msg"
					:class="[
						`ts-msg--${ role( group.message ) }`,
						{ 'ts-msg--flash': flashed !== null && flashed === idOf( group.message ) }
					]"
				>
					<span class="ts-msg__avatar" :style="{ '--hue': hue( actorOf( group.message ) ) }" aria-hidden="true">
						{{ initials( actorOf( group.message ) ) }}
					</span>

					<div class="ts-msg__card">
						<header class="ts-msg__head">
							<strong
								class="ts-msg__author"
								:title="!publicOnly && group.message.public_actor && group.message.visibility === 'public'
									? `The reporter sees this as from ${ group.message.public_actor }`
									: undefined"
							>{{ actorOf( group.message ) || 'Someone' }}</strong>
							<span class="ts-msg__badge">
								<cdx-icon v-if="role( group.message ) === 'internal'" :icon="cdxIconLock" size="x-small" />
								<cdx-icon v-else-if="role( group.message ) === 'outgoing'" :icon="cdxIconEye" size="x-small" />
								{{ badge( group.message ) }}
							</span>
							<time class="ts-meta" :datetime="group.message.at" :title="dateTime( group.message.at )">
								{{ ago( group.message.at ) }}
							</time>
							<span class="ts-msg__spacer" />
							<span
								v-if="role( group.message ) === 'outgoing'"
								class="ts-meta"
								:title="group.message.meta.synced ? 'The wiki has it' : 'Waiting for the wiki to pick it up'"
							>
								{{ group.message.meta.synced ? 'Delivered' : 'Sending…' }}
							</span>
							<button
								v-if="idOf( group.message ) && replyable"
								type="button"
								class="ts-msg__reply"
								@click="replying = quoteOf( group.message )"
							>
								<cdx-icon :icon="cdxIconSpeechBubble" size="x-small" />
								Reply
							</button>
						</header>

						<button
							v-if="group.message.reply_to && byId[ group.message.reply_to ]"
							type="button"
							class="ts-msg__quoted"
							@click="jump( group.message.reply_to )"
						>
							<span class="ts-msg__quoted-who">{{ actorOf( byId[ group.message.reply_to ] ) }}</span>
							{{ excerpt( byId[ group.message.reply_to ].body ) }}
						</button>

						<RichText class="ts-msg__body" :text="group.message.body || ''" />
						<p v-if="!group.message.body" class="ts-meta">
							{{ kind === 'case' ? 'No summary was given.' : 'No premise was written down.' }}
						</p>
					</div>
				</article>

				<ReplyComposer
					v-if="item && replying && group.message && replying.id === idOf( group.message )"
					:key="replying.id"
					:kind="kind"
					:item="item"
					:quote="replying"
					class="ts-feed__reply"
					@cancel="replying = null"
					@done="onReplied"
				/>
			</li>
		</ol>
	</section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { CdxIcon, CdxProgressBar, CdxToggleButton } from '@wikimedia/codex';
import {
	cdxIconAlert, cdxIconArticle, cdxIconBlock, cdxIconCalendar, cdxIconCheck, cdxIconClock, cdxIconCopy,
	cdxIconEye, cdxIconFlag, cdxIconHistory, cdxIconLock, cdxIconSpeechBubble, cdxIconTrash,
	cdxIconUnBlock, cdxIconUserAvatar, cdxIconUserGroup
} from '@wikimedia/codex-icons';
import StatusChip from './StatusChip.vue';
import RichText from './RichText.vue';
import ReplyComposer from './ReplyComposer.vue';
import { plain } from '../lib/markdown.js';
import { ago, dateTime, TIMELINE_KINDS } from '../lib/format.js';

const props = defineProps( {
	kind: { type: String, default: 'case' },
	entries: { type: Array, default: () => [] },
	loading: { type: Boolean, default: false },
	replyable: { type: Boolean, default: true },
	item: { type: Object, default: null }
} );
const emit = defineEmits( [ 'changed' ] );

const publicOnly = ref( false );
const flashed = ref( null );
const replying = ref( null );

function onReplied( data ) {
	replying.value = null;
	emit( 'changed', data );
}

const heading = computed( () => ( props.kind === 'case' ? 'History and conversation' : 'The file, in order' ) );

const MESSAGES = {
	case: [ 'comment', 'filed' ],
	investigation: [ 'note', 'opened' ]
};
const isMessage = ( e ) => MESSAGES[ props.kind ].includes( e.kind );
const idOf = ( e ) => e.comment_id ?? e.note_id ?? null;

const actorOf = ( e ) => ( publicOnly.value && e.public_actor ) ? e.public_actor : e.actor;

const byId = computed( () => Object.fromEntries(
	props.entries.filter( ( e ) => idOf( e ) ).map( ( e ) => [ idOf( e ), e ] )
) );

const groups = computed( () => {
	const list = publicOnly.value
		? props.entries.filter( ( e ) => e.visibility === 'public' )
		: props.entries;

	const out = [];
	let current = null;

	for ( const entry of list ) {
		const at = Date.parse( entry.at ?? '' ) || 0;
		const who = entry.actor_id ?? entry.actor ?? null;
		const fits = current &&
			!current.message &&
			!( isMessage( entry ) && entry.reply_to ) &&
			who !== null &&
			current.who === who &&
			Math.abs( at - current.at ) < 90 * 1000;

		if ( !fits ) {
			current = { key: `${ entry.kind }-${ entry.at }-${ out.length }`, who, at, events: [], message: null };
			out.push( current );
		}

		if ( isMessage( entry ) ) {
			current.message = entry;
		} else {
			current.events.push( entry );
		}
	}

	for ( const group of out ) {
		group.events.sort( ( a, b ) => ( a.kind === 'status-note' ) - ( b.kind === 'status-note' ) );
	}

	return out;
} );

function role( entry ) {
	if ( props.kind === 'investigation' ) {
		return 'note';
	}
	if ( entry.kind === 'filed' || entry.subkind === 'subject' ) {
		return 'incoming';
	}
	return entry.visibility === 'internal' ? 'internal' : 'outgoing';
}

function badge( entry ) {
	if ( entry.kind === 'filed' ) {
		return 'Filed the report';
	}
	if ( entry.kind === 'opened' ) {
		return 'Opened the file';
	}
	if ( entry.kind === 'note' ) {
		return entry.title;
	}
	return {
		incoming: 'Reporter',
		internal: 'Internal note',
		outgoing: 'Sent to the reporter'
	}[ role( entry ) ];
}

function sentence( ev ) {
	const title = ev.title ?? '';
	const words = ( v ) => String( v ?? '?' ).replace( /-/g, ' ' );
	if ( ev.subkind === 'case.status' ) {
		return `changed the status from ${ words( ev.meta.from ) } to ${ words( ev.meta.to ) }`;
	}
	if ( ev.subkind === 'investigation.status' ) {
		return `moved the file from ${ words( ev.meta.from ) } to ${ words( ev.meta.to ) }`;
	}
	if ( ev.subkind === 'case.assigned' || ev.subkind === 'investigation.assigned' ) {
		return ev.meta.assignee ? `assigned this to ${ ev.meta.assignee }` : 'unassigned this';
	}
	if ( ev.subkind === 'case.priority' || ev.subkind === 'investigation.priority' ) {
		return `set the priority to ${ words( ev.meta.to ) }`;
	}
	if ( ev.subkind === 'investigation.review' ) {
		return ev.meta.to
			? `set it to be looked at again on ${ dateOnly( ev.meta.to ) }`
			: 'cleared the date to look at it again';
	}
	if ( ev.kind === 'status-note' ) {
		return ev.visibility === 'public' ? 'and told the reporter:' : 'and noted:';
	}
	if ( ev.kind === 'action' ) {
		return `took an action: ${ title }`;
	}
	return ev.actor ? title.charAt( 0 ).toLowerCase() + title.slice( 1 ) : title;
}

const ICONS = {
	system: cdxIconHistory,
	audit: cdxIconHistory,
	action: cdxIconBlock,
	'action-lifted': cdxIconUnBlock,
	'action-expired': cdxIconClock,
	investigation: cdxIconUserGroup,
	note: cdxIconArticle,
	case: cdxIconFlag,
	duplicate: cdxIconCopy,
	'data-removal': cdxIconTrash,
	concluded: cdxIconCheck
};

function icon( ev ) {
	if ( ev.subkind === 'case.assigned' || ev.subkind === 'investigation.assigned' ) {
		return cdxIconUserAvatar;
	}
	if ( ev.subkind === 'case.status' || ev.subkind === 'investigation.status' ) {
		return cdxIconCheck;
	}
	if ( ev.subkind === 'investigation.review' ) {
		return cdxIconCalendar;
	}
	if ( ev.kind === 'status-note' ) {
		return cdxIconEye;
	}
	return ICONS[ ev.kind ] ?? cdxIconAlert;
}

function tone( ev ) {
	return TIMELINE_KINDS[ ev.kind ]?.tone ?? 'neutral';
}

function link( ev ) {
	if ( !ev.link?.route || !ev.link?.id ) {
		return null;
	}
	return { name: ev.link.route, params: { id: ev.link.id } };
}

function initials( name ) {
	const parts = String( name ?? '?' ).replace( /\(.*\)/, '' ).split( /[\s._-]+/ ).filter( Boolean );
	return ( ( parts[ 0 ]?.[ 0 ] ?? '?' ) + ( parts[ 1 ]?.[ 0 ] ?? '' ) ).toUpperCase();
}

function hue( name ) {
	let h = 0;
	for ( const ch of String( name ?? '' ) ) {
		h = ( h * 31 + ch.codePointAt( 0 ) ) % 360;
	}
	return h;
}

function excerpt( body ) {
	const text = plain( body );
	return text.length > 140 ? `${ text.slice( 0, 140 ) }…` : text;
}

function dateOnly( iso ) {
	return new Date( `${ iso }T00:00:00` ).toLocaleDateString( undefined, { day: 'numeric', month: 'short', year: 'numeric' } );
}

function quoteOf( entry ) {
	return {
		id: idOf( entry ),
		author: actorOf( entry ),
		excerpt: excerpt( entry.body ),
		visibility: entry.visibility
	};
}

function jump( id ) {
	document.getElementById( `message-${ id }` )?.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	flashed.value = id;
	setTimeout( () => {
		flashed.value = null;
	}, 1600 );
}
</script>
