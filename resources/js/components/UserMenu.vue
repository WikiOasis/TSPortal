<template>
	<div ref="rootRef" class="ts-usermenu">
		<button
			type="button"
			class="ts-avatar"
			:aria-expanded="open"
			aria-haspopup="menu"
			:aria-label="`Account menu for ${ session.user.shown_name || session.user.username }`"
			@click="open = !open"
		>
			<UserAvatar :name="session.user.shown_name || session.user.username" :url="session.user.avatar_url" size="100%" />
		</button>

		<div v-if="open" class="ts-usermenu__pop" role="menu" @keydown.esc="close">
			<div class="ts-usermenu__who">
				<UserAvatar :name="session.user.shown_name || session.user.username" :url="session.user.avatar_url" size="2.5rem" />
				<div>
					<strong>{{ session.user.shown_name || session.user.username }}</strong>
					<div class="ts-meta">{{ session.user.username }}</div>
				</div>
			</div>

			<router-link :to="{ name: 'profile', params: { id: session.user.id } }" class="ts-usermenu__item" role="menuitem" @click="close">
				<cdx-icon :icon="cdxIconUserAvatar" size="small" />
				View profile
			</router-link>

			<button type="button" class="ts-usermenu__item" role="menuitem" @click="openSettings">
				<cdx-icon :icon="cdxIconSettings" size="small" />
				User settings
			</button>

			<form method="POST" :action="logoutUrl">
				<input type="hidden" name="_token" :value="csrf">
				<button type="submit" class="ts-usermenu__item" role="menuitem">
					<cdx-icon :icon="cdxIconLogOut" size="small" />
					Sign out
				</button>
			</form>
		</div>
	</div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { CdxIcon } from '@wikimedia/codex';
import { cdxIconLogOut, cdxIconSettings, cdxIconUserAvatar } from '@wikimedia/codex-icons';
import UserAvatar from './UserAvatar.vue';
import { session } from '../lib/session.js';
import { ui } from '../lib/ui.js';

const logoutUrl = window.TSPortal?.logoutUrl ?? '/logout';
const csrf = document.querySelector( 'meta[name="csrf-token"]' )?.content ?? '';

const rootRef = ref( null );
const open = ref( false );

function close() {
	open.value = false;
}

function openSettings() {
	close();
	ui.openSettings( 'profile' );
}

function onOutside( event ) {
	if ( open.value && !rootRef.value?.contains( event.target ) ) {
		close();
	}
}

onMounted( () => document.addEventListener( 'pointerdown', onOutside ) );
onBeforeUnmount( () => document.removeEventListener( 'pointerdown', onOutside ) );
</script>
