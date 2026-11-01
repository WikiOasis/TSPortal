<template>
	<div ref="rootRef" class="ts-notifs">
		<cdx-button
			weight="quiet"
			class="ts-notifs__bell"
			:aria-expanded="open"
			:aria-label="unread ? `Notifications, ${ unread } unread` : 'Notifications'"
			@click="toggle"
		>
			<cdx-icon :icon="cdxIconBell" />
			<span v-if="unread" class="ts-notifs__badge" aria-hidden="true">{{ unread > 9 ? '9+' : unread }}</span>
		</cdx-button>

		<div v-if="open" class="ts-notifs__pop" role="dialog" aria-label="Notifications" @keydown.esc="open = false">
			<div class="ts-notifs__head">
				<strong>Notifications</strong>
				<span class="ts-notifs__tools">
					<cdx-button weight="quiet" size="small" :disabled="!unread" @click="readAll">Mark all read</cdx-button>
					<cdx-button weight="quiet" size="small" aria-label="Notification settings" @click="settings">
						<cdx-icon :icon="cdxIconSettings" size="small" />
					</cdx-button>
				</span>
			</div>

			<ul v-if="items.length" class="ts-notifs__list">
				<li v-for="n in items" :key="n.id">
					<button type="button" class="ts-notif" :class="{ 'ts-notif--unread': !n.read }" @click="go( n )">
						<UserAvatar :name="n.actor ?? 'system'" :url="n.actor_avatar" size="2rem" />
						<span class="ts-notif__text">
							<span class="ts-notif__summary">
								<strong>{{ n.actor ?? 'Someone' }}</strong>
								{{ lower( n.summary ) }}
							</span>
							<span class="ts-notif__about">
								<span class="ts-mono">{{ n.reference }}</span>
								<template v-if="n.title"> · {{ n.title }}</template>
							</span>
							<time class="ts-meta" :datetime="n.at" :title="dateTime( n.at )">{{ ago( n.at ) }}</time>
						</span>
						<span v-if="!n.read" class="ts-notif__dot" aria-label="Unread" />
					</button>
				</li>
			</ul>

			<div v-else class="ts-notifs__empty">
				<cdx-icon :icon="cdxIconBell" />
				<p>Nothing yet.</p>
				<p class="ts-meta">Watch a case or investigation and updates to it will show up here.</p>
			</div>
		</div>
	</div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { CdxButton, CdxIcon } from '@wikimedia/codex';
import { cdxIconBell, cdxIconSettings } from '@wikimedia/codex-icons';
import UserAvatar from './UserAvatar.vue';
import { api } from '../lib/api.js';
import { ago, dateTime } from '../lib/format.js';
import { ui } from '../lib/ui.js';

const router = useRouter();

const rootRef = ref( null );
const open = ref( false );
const items = ref( [] );
const unread = ref( 0 );

let poll = null;

const lower = ( text ) => ( text ? text.charAt( 0 ).toLowerCase() + text.slice( 1 ) : '' );

async function load() {
	try {
		const response = await api.notifications();
		items.value = response.data;
		unread.value = response.unread;
	} catch ( e ) {
	}
}

function toggle() {
	open.value = !open.value;
	if ( open.value ) {
		load();
	}
}

async function go( n ) {
	open.value = false;

	if ( !n.read ) {
		n.read = true;
		unread.value = Math.max( 0, unread.value - 1 );
		api.readNotification( n.id ).catch( () => {} );
	}

	router.push( { name: n.object_type === 'case' ? 'case' : 'investigation', params: { id: n.object_id } } );
}

async function readAll() {
	items.value.forEach( ( n ) => {
		n.read = true;
	} );
	unread.value = 0;
	api.readAllNotifications().catch( () => {} );
}

function settings() {
	open.value = false;
	ui.openSettings( 'notifications' );
}

function onOutside( event ) {
	if ( open.value && !rootRef.value?.contains( event.target ) ) {
		open.value = false;
	}
}

onMounted( () => {
	load();
	poll = setInterval( load, 60000 );
	document.addEventListener( 'pointerdown', onOutside );
} );

onBeforeUnmount( () => {
	clearInterval( poll );
	document.removeEventListener( 'pointerdown', onOutside );
} );
</script>
