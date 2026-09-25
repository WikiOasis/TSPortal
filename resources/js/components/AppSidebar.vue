<template>
	<div class="ts-shell">
		<header class="ts-topbar">
			<cdx-button
				ref="toggleRef"
				class="ts-topbar__toggle"
				weight="quiet"
				:aria-label="menuLabel"
				:aria-expanded="isNarrow ? drawerOpen : !collapsed"
				aria-controls="ts-sections"
				@click="toggle"
			>
				<cdx-icon :icon="cdxIconMenu" />
			</cdx-button>

			<router-link :to="{ name: 'dashboard' }" class="ts-topbar__brand">
				{{ appName }}
			</router-link>

			<div class="ts-topbar__spacer" />

			<cdx-button class="ts-topbar__search" weight="quiet" @click="openSearch">
				<cdx-icon :icon="cdxIconSearch" />
				<span class="ts-topbar__search-label">Search</span>
				<kbd class="ts-topbar__kbd">{{ shortcutHint }}</kbd>
			</cdx-button>

			<cdx-button
				class="ts-topbar__action"
				action="progressive"
				weight="primary"
				@click="openFile"
			>
				<cdx-icon :icon="cdxIconAdd" />
				<span class="ts-topbar__action-label">Open investigation</span>
			</cdx-button>

			<div class="ts-topbar__user">
				<span class="ts-topbar__username">{{ session.user.username }}</span>

				<form method="POST" :action="logoutUrl">
					<input type="hidden" name="_token" :value="csrf">
					<cdx-button type="submit" weight="quiet" size="small">Sign out</cdx-button>
				</form>
			</div>
		</header>

		<div class="ts-shell__body">
			<button
				v-if="isNarrow && drawerOpen"
				type="button"
				class="ts-scrim"
				aria-label="Close the sections"
				@click="closeDrawer"
			/>

			<nav
				id="ts-sections"
				ref="navRef"
				class="ts-sidebar"
				:class="{
					'ts-sidebar--collapsed': collapsed,
					'ts-sidebar--open': isNarrow && drawerOpen
				}"
				:inert="isNarrow && !drawerOpen"
				aria-label="Sections"
			>
				<router-link
					v-for="link in links"
					:key="link.name"
					:to="{ name: link.name }"
					class="ts-sidebar__link"
					:class="{ 'ts-sidebar__link--active': isActive( link ) }"
					:aria-current="isActive( link ) ? 'page' : undefined"
					:title="collapsed && !isNarrow ? link.label : undefined"
				>
					<cdx-icon :icon="link.icon" />
					<span class="ts-sidebar__label">{{ link.label }}</span>
				</router-link>

				<div class="ts-sidebar__foot">
					<span class="ts-sidebar__whoami">
						Signed in as {{ session.user.username }}
					</span>

					<form method="POST" :action="logoutUrl">
						<input type="hidden" name="_token" :value="csrf">
						<cdx-button type="submit" weight="quiet">Sign out</cdx-button>
					</form>
				</div>
			</nav>

			<div class="ts-shell__main">
				<slot />
			</div>
		</div>

		<OpenInvestigationDialog v-model:open="showOpenFile" @opened="onOpened" />
		<QuickSearch v-model:open="showSearch" />
	</div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { CdxButton, CdxIcon } from '@wikimedia/codex';
import {
	cdxIconAdd, cdxIconArticle, cdxIconBlock, cdxIconChart, cdxIconFlag, cdxIconHistory,
	cdxIconMenu, cdxIconRobot, cdxIconSearch, cdxIconTrash, cdxIconUserAvatar, cdxIconUserGroup,
	cdxIconViewDetails
} from '@wikimedia/codex-icons';
import OpenInvestigationDialog from './OpenInvestigationDialog.vue';
import QuickSearch from './QuickSearch.vue';
import { session } from '../lib/session.js';

const route = useRoute();
const router = useRouter();

const appName = window.TSPortal?.appName ?? 'TSPortal';
const logoutUrl = window.TSPortal?.logoutUrl ?? '/logout';
const csrf = document.querySelector( 'meta[name="csrf-token"]' )?.content ?? '';

const STORAGE_KEY = 'tsportal:sidebar-collapsed';

const DRAWER_QUERY = '( max-width: 48rem )';

const collapsed = ref( read() );
const drawerOpen = ref( false );
const isNarrow = ref( false );
const showOpenFile = ref( false );
const showSearch = ref( false );
const navRef = ref( null );
const toggleRef = ref( null );

const shortcutHint = /Mac|iPhone|iPad/.test( navigator.platform ?? navigator.userAgent )
	? '⌘K'
	: 'Ctrl K';

const menuLabel = computed( () => {
	if ( isNarrow.value ) {
		return drawerOpen.value ? 'Close the sections' : 'Show the sections';
	}
	return collapsed.value ? 'Show the section names' : 'Hide the section names';
} );

function read() {
	try {
		return window.localStorage.getItem( STORAGE_KEY ) === '1';
	} catch ( e ) {
		return false;
	}
}

let media = null;

function onMediaChange( event ) {
	isNarrow.value = event.matches;

	if ( !event.matches ) {
		drawerOpen.value = false;
	}
}

onMounted( () => {
	media = window.matchMedia( DRAWER_QUERY );
	isNarrow.value = media.matches;
	media.addEventListener( 'change', onMediaChange );
	window.addEventListener( 'keydown', onKeydown );
} );

onUnmounted( () => {
	media?.removeEventListener( 'change', onMediaChange );
	window.removeEventListener( 'keydown', onKeydown );
} );

function onKeydown( event ) {
	if ( event.key === 'Escape' && drawerOpen.value ) {
		closeDrawer();
	}
}

function toggle() {
	if ( isNarrow.value ) {
		if ( drawerOpen.value ) {
			closeDrawer();
		} else {
			openDrawer();
		}
		return;
	}

	collapsed.value = !collapsed.value;
	try {
		window.localStorage.setItem( STORAGE_KEY, collapsed.value ? '1' : '0' );
	} catch ( e ) { }
}

function openDrawer() {
	drawerOpen.value = true;

	nextTick( () => {
		const nav = navRef.value;
		if ( !nav ) {
			return;
		}
		void nav.offsetWidth;
		nav.querySelector( '.ts-sidebar__link' )?.focus();
	} );
}

function closeDrawer() {
	if ( !drawerOpen.value ) {
		return;
	}
	drawerOpen.value = false;
	nextTick( () => toggleRef.value?.$el?.focus?.() );
}

watch( () => route.fullPath, () => closeDrawer() );

function openSearch() {
	closeDrawer();
	showSearch.value = true;
}

function openFile() {
	closeDrawer();
	showOpenFile.value = true;
}

const links = computed( () => [
	{ name: 'dashboard', label: 'Dashboard', icon: cdxIconViewDetails, match: [ 'dashboard' ] },
	{ name: 'queue', label: 'Queue', icon: cdxIconFlag, match: [ 'queue', 'case' ] },
	{ name: 'automation', label: 'Automation', icon: cdxIconRobot, match: [ 'automation' ] },
	{ name: 'investigations', label: 'Investigations', icon: cdxIconUserGroup, match: [ 'investigations', 'investigation' ] },
	{ name: 'subjects', label: 'Accounts', icon: cdxIconUserAvatar, match: [ 'subjects', 'subject' ] },
	{ name: 'sanctions', label: 'Actions', icon: cdxIconBlock, match: [ 'sanctions' ] },
	{ name: 'data-removals', label: 'Erasures', icon: cdxIconTrash, match: [ 'data-removals' ] },
	{ name: 'analytics', label: 'Activity', icon: cdxIconChart, match: [ 'analytics' ] },
	{ name: 'checkuser', label: 'CheckUser log', icon: cdxIconSearch, match: [ 'checkuser' ] },
	{ name: 'transparency', label: 'Transparency', icon: cdxIconArticle, match: [ 'transparency', 'transparency-report' ] },
	{ name: 'audit', label: 'Audit log', icon: cdxIconHistory, match: [ 'audit' ] },
	...( session.can( 'user-manager' )
		? [ { name: 'staff', label: 'Team', icon: cdxIconUserGroup, match: [ 'staff' ] } ]
		: [] )
] );

function isActive( link ) {
	return link.match.includes( route.name );
}

function onOpened( investigation ) {
	router.push( { name: 'investigation', params: { id: investigation.id } } );
}
</script>
