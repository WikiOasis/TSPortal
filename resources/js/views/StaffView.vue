<template>
	<main class="ts-page">
		<PageHeader
			title="Team"
			:subtitle="members.length ? `${ members.length } ${ members.length === 1 ? 'person has' : 'people have' } access` : ''"
		/>

		<LoadError :error="error" :retry="load" />
		<cdx-progress-bar v-if="loading && !people.length" aria-label="Loading the team" />

		<ul class="ts-team">
			<li v-for="person in members" :key="person.id" class="ts-team__row">
				<UserAvatar :name="person.username" :url="person.avatar_url" size="2.25rem" />

				<div class="ts-team__who">
					<router-link :to="{ name: 'profile', params: { id: person.id } }"><strong>{{ person.shown_name || person.username }}</strong></router-link>
					<span class="ts-meta"> · {{ person.username }}</span>
					<span v-if="person.id === session.user.id" class="ts-meta"> (you)</span>
					<div class="ts-meta">
						{{ person.last_login ? `Last in ${ ago( person.last_login ) }` : 'Never signed in' }}
					</div>
				</div>

				<div class="ts-team__flags" role="group" :aria-label="`Access for ${ person.username }`">
					<button
						v-for="flag in availableFlags"
						:key="flag"
						type="button"
						class="ts-team__flag"
						:class="{ 'ts-team__flag--on': person.flags.includes( flag ) }"
						:aria-pressed="person.granted_flags.includes( flag )"
						:disabled="person.id === session.user.id || saving === person.id || !session.can( 'user-manager' )"
						:title="flagTitle( person, flag )"
						@click="toggle( person, flag, !person.granted_flags.includes( flag ) )"
					>
						{{ flag }}
						<span v-if="fromGroups( person ).includes( flag )" class="ts-team__flag-mark" aria-label="from an identity group">•</span>
					</button>
				</div>

				<cdx-button
					v-if="person.id !== session.user.id && session.can( 'user-manager' )"
					weight="quiet"
					action="destructive"
					size="small"
					:disabled="saving === person.id"
					:aria-label="`Remove ${ person.username } from the team`"
					@click="remove( person )"
				>
					<cdx-icon :icon="cdxIconTrash" size="small" />
					Remove
				</cdx-button>
				<span v-else class="ts-team__spacer" />
			</li>
		</ul>

		<p v-if="!members.length && !loading" class="ts-empty-state">Nobody has access.</p>

		<details v-if="removed.length" class="ts-team__removed">
			<summary>Removed <span class="ts-group-label__count">{{ removed.length }}</span></summary>
			<ul class="ts-team">
				<li v-for="person in removed" :key="person.id" class="ts-team__row ts-team__row--removed">
					<span class="ts-team__avatar" aria-hidden="true">{{ initial( person ) }}</span>
					<div class="ts-team__who">
						<strong>{{ person.username }}</strong>
						<div class="ts-meta">
							{{ person.real_name || 'No name on file' }}
							· {{ person.last_login ? `last in ${ ago( person.last_login ) }` : 'never signed in' }}
						</div>
					</div>
					<span class="ts-team__flags" />
					<cdx-button
						v-if="session.can( 'user-manager' )"
						size="small"
						:disabled="saving === person.id"
						@click="restore( person )"
					>
						Restore
					</cdx-button>
				</li>
			</ul>
		</details>
	</main>
</template>

<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import { CdxButton, CdxIcon, CdxProgressBar } from '@wikimedia/codex';
import { cdxIconTrash } from '@wikimedia/codex-icons';
import PageHeader from '../components/PageHeader.vue';
import UserAvatar from '../components/UserAvatar.vue';
import LoadError from '../components/LoadError.vue';
import { api } from '../lib/api.js';
import { ago } from '../lib/format.js';
import { session } from '../lib/session.js';

const notify = inject( 'notify' );

const people = ref( [] );
const availableFlags = ref( [] );
const loading = ref( false );
const error = ref( null );
const saving = ref( null );

const members = computed( () => people.value.filter( ( p ) => p.active ) );
const removed = computed( () => people.value.filter( ( p ) => !p.active ) );

function initial( person ) {
	return ( person.username ?? '?' ).slice( 0, 1 ).toUpperCase();
}

function fromGroups( person ) {
	return person.from_groups ?? [];
}

function flagTitle( person, flag ) {
	if ( person.id === session.user.id ) {
		return 'You cannot change your own access.';
	}
	if ( fromGroups( person ).includes( flag ) ) {
		return 'Also given by an identity group, which comes back at their next sign-in.';
	}
	return person.granted_flags.includes( flag ) ? `Take ${ flag } away` : `Give ${ flag }`;
}

async function load() {
	loading.value = true;
	error.value = null;
	try {
		const response = await api.staff();
		people.value = response.data;
		availableFlags.value = response.available_flags;
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

async function save( person, body, said ) {
	saving.value = person.id;

	try {
		await api.updateStaff( person.id, body );
		notify( said );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		saving.value = null;
		await load();
	}
}

function toggle( person, flag, on ) {
	const granted = on
		? [ ...person.granted_flags, flag ]
		: person.granted_flags.filter( ( f ) => f !== flag );

	return save( person, { granted_flags: granted }, `${ person.username }: ${ flag } ${ on ? 'granted' : 'removed' }.` );
}

function remove( person ) {
	return save( person, { active: false, granted_flags: [] }, `${ person.username } removed from the team.` );
}

function restore( person ) {
	return save( person, { active: true }, `${ person.username } restored. Their access flags were cleared, so grant what they need.` );
}

onMounted( load );
</script>
