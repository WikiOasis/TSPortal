<template>
	<main class="ts-page">
		<LoadError :error="error" :retry="load" />
		<cdx-progress-bar v-if="loading && !person" aria-label="Loading the profile" />

		<div v-if="person" class="ts-profile-themed" :class="{ 'ts-profile-themed--glass': glass }" :style="themeStyle">
			<div v-if="person.banner_url" class="ts-banner" :style="{ backgroundImage: `url( '${ person.banner_url }' )` }" role="img" :aria-label="`${ person.shown_name }'s banner`" />
			<div v-else-if="glass" class="ts-banner ts-banner--empty" aria-hidden="true" />

			<div class="ts-profile-card" :class="{ 'ts-profile-card--banner': person.banner_url || glass }">
				<UserAvatar :name="person.username" :url="person.avatar_url" size="6rem" />

				<div class="ts-profile-card__main">
					<div class="ts-profile-card__name">
						<h1 class="ts-page__title">{{ person.shown_name }}</h1>
						<cdx-button v-if="isMe" weight="quiet" aria-label="Edit your profile" title="Edit your profile" @click="ui.openSettings( 'profile' )">
							<cdx-icon :icon="cdxIconEdit" />
						</cdx-button>
					</div>
					<p class="ts-meta">
						{{ person.username }}
						<template v-if="person.real_name && person.real_name !== person.shown_name"> · {{ person.real_name }}</template>
					</p>

					<div class="ts-inline">
						<cdx-info-chip v-for="flag in person.flags" :key="flag">{{ flag }}</cdx-info-chip>
						<cdx-info-chip v-if="!person.active" status="error">Removed from the team</cdx-info-chip>
					</div>
				</div>

			</div>

			<div class="ts-split ts-section">
				<section aria-labelledby="bio-title">
					<h2 id="bio-title" class="ts-section__title">About</h2>
					<div class="ts-panel">
						<RichText v-if="person.bio" :text="person.bio" class="ts-prose ts-profile-card__bio" />
						<p v-else class="ts-meta">
							{{ isMe ? 'You have not written a bio yet.' : `${ person.shown_name } has not written a bio.` }}
							<a v-if="isMe" href="#" @click.prevent="ui.openSettings( 'profile' )">Add one</a>
						</p>
					</div>
				</section>

				<aside>
					<h2 class="ts-section__title">At a glance</h2>
					<div class="ts-panel">
						<dl class="ts-facts">
							<dt>Open cases</dt>
							<dd>
								<router-link :to="{ name: 'queue', query: { assignee: String( person.id ) } }">{{ person.open_cases }}</router-link>
							</dd>
							<dt>Open investigations</dt>
							<dd>
								<router-link :to="{ name: 'investigations', query: { assignee: String( person.id ) } }">{{ person.open_files }}</router-link>
							</dd>
							<dt>Last signed in</dt>
							<dd>{{ person.last_login ? ago( person.last_login ) : 'never' }}</dd>
							<dt>On the team since</dt>
							<dd>{{ person.joined ? date( person.joined ) : 'unknown' }}</dd>
						</dl>
					</div>
				</aside>
			</div>
		</div>
	</main>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { CdxButton, CdxIcon, CdxInfoChip, CdxProgressBar } from '@wikimedia/codex';
import { cdxIconEdit } from '@wikimedia/codex-icons';
import LoadError from '../components/LoadError.vue';
import RichText from '../components/RichText.vue';
import UserAvatar from '../components/UserAvatar.vue';
import { gradientCss } from '../lib/gradient.js';
import { isDark, themeRules } from '../lib/preferences.js';
import { api } from '../lib/api.js';
import { ago, date } from '../lib/format.js';
import { session } from '../lib/session.js';
import { ui } from '../lib/ui.js';

const props = defineProps( { id: { type: [ String, Number ], required: true } } );

const person = ref( null );
const loading = ref( false );
const error = ref( null );

const isMe = computed( () => person.value?.id === session.user.id );

const glass = computed( () => !!person.value?.profile_theme?.gradient );

const themeStyle = computed( () => {
	const t = person.value?.profile_theme;

	if ( !t ) {
		return {};
	}

	const style = {};
	const rules = t.accent
		? themeRules( { accent: t.accent, background: t.gradient ? undefined : t.background }, isDark() )
		: ( t.background ? themeRules( { accent: '#3366cc', background: t.background }, isDark() ) : {} );

	for ( const [ key, value ] of Object.entries( rules ) ) {
		style[ `--${ key }` ] = value;
	}

	if ( t.gradient ) {
		style.background = gradientCss( t.gradient );
	} else if ( t.background ) {
		style.backgroundColor = t.background;
	}

	return style;
} );

async function load() {
	loading.value = true;
	error.value = null;

	try {
		person.value = await api.staffMember( props.id );
	} catch ( e ) {
		error.value = e;
	} finally {
		loading.value = false;
	}
}

watch( () => [ session.user.shown_name, session.user.bio, session.user.avatar_url, session.user.banner_url, JSON.stringify( session.user.profile_theme ) ], () => {
	if ( isMe.value ) {
		load();
	}
} );

watch( () => props.id, load );
onMounted( load );
</script>
