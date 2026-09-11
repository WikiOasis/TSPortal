<template>
	<main class="ts-page">
		<PageHeader
			title="Team"
		/>

		<cdx-message type="notice" :allow-user-dismiss="false">
			A flag from a wiki group comes back on its own at the next sign-in. A flag
			granted here is a decision and stays until it is taken back here.
		</cdx-message>

		<LoadError :error="error" :retry="load" />
		<cdx-progress-bar v-if="loading" aria-label="Loading the team" />

		<div class="ts-stack">
			<div v-for="person in people" :key="person.id" class="ts-panel">
				<div class="ts-page__head" style="margin-bottom: 0.5rem;">
					<div>
						<strong>{{ person.username }}</strong>
						<span v-if="person.real_name" class="ts-meta"> · {{ person.real_name }}</span>
						<div class="ts-meta">
							Shown to reporters as “{{ person.public_label }}” ·
							{{ person.last_login ? `last in ${ ago( person.last_login ) }` : 'has never signed in' }}
						</div>
					</div>
					<cdx-info-chip :status="person.active ? 'success' : 'error'">
						{{ person.active ? 'Active' : 'Deactivated' }}
					</cdx-info-chip>
				</div>

				<div class="ts-inline">
					<cdx-checkbox
						v-for="flag in availableFlags"
						:key="flag"
						:model-value="person.granted_flags.includes( flag )"
						:disabled="person.id === session.user.id || saving === person.id"
						@update:model-value="( on ) => toggle( person, flag, on )"
					>
						{{ flag }}
						<span v-if="fromGroups( person ).includes( flag )" class="ts-meta">(also from an identity group)</span>
					</cdx-checkbox>
				</div>

				<div class="ts-meta" style="margin-top: 0.5rem;">
					In force: {{ person.flags.join( ', ' ) || 'nothing' }}
					<template v-if="person.idp_groups.length"> · identity groups: {{ person.idp_groups.join( ', ' ) }}</template>
				</div>

				<p v-if="person.id === session.user.id" class="ts-meta">
					You cannot change your own flags. Ask someone else holding
					<code>user-manager</code>.
				</p>
			</div>
		</div>
	</main>
</template>

<script setup>
import { inject, onMounted, ref } from 'vue';
import { CdxCheckbox, CdxInfoChip, CdxMessage, CdxProgressBar } from '@wikimedia/codex';
import PageHeader from '../components/PageHeader.vue';
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

function fromGroups( person ) {
	return person.from_groups ?? [];
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

async function toggle( person, flag, on ) {
	saving.value = person.id;

	const granted = on
		? [ ...person.granted_flags, flag ]
		: person.granted_flags.filter( ( f ) => f !== flag );

	try {
		await api.updateStaff( person.id, { granted_flags: granted } );
		notify( `${ person.username }: ${ flag } ${ on ? 'granted' : 'removed' }.` );
		await load();
	} catch ( e ) {
		notify( e.message, 'error' );
		await load();
	} finally {
		saving.value = null;
	}
}

onMounted( load );
</script>
