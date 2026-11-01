<template>
	<cdx-dialog
		:open="open"
		class="ts-settings"
		title="User settings"
		:use-close-button="true"
		:default-action="{ label: 'Done' }"
		@update:open="$emit( 'update:open', $event )"
		@default="$emit( 'update:open', false )"
	>
		<cdx-tabs v-model:active="ui.settingsTab" class="ts-settings__tabs" :framed="false">
			<cdx-tab name="profile" label="Profile">
				<div class="ts-settings__pane">
					<div class="ts-profile">
						<UserAvatar :name="session.user.username" :url="session.user.avatar_url" size="5rem" />
						<div class="ts-profile__text">
							<strong>{{ session.user.username }}</strong>
							<span v-if="session.user.real_name" class="ts-meta">{{ session.user.real_name }}</span>
							<span class="ts-meta">Shown to other staff in the portal. Reporters never see it.</span>
						</div>
						<div class="ts-profile__actions">
							<cdx-button :disabled="uploading" @click="picker.click()">
								<cdx-icon :icon="cdxIconUpload" size="small" />
								{{ uploading ? 'Uploading…' : 'Upload a picture' }}
							</cdx-button>
							<cdx-button v-if="session.user.avatar_url" weight="quiet" action="destructive" @click="removePicture">
								Remove
							</cdx-button>
							<input ref="picker" type="file" accept="image/png,image/jpeg,image/webp,image/gif" hidden @change="onPick">
						</div>
					</div>

					<p class="ts-meta">PNG, JPEG, WebP or GIF, up to 2 MB. It is cropped to a circle.</p>
					<cdx-message v-if="uploadError" type="error" :inline="true" :allow-user-dismiss="false">{{ uploadError }}</cdx-message>

					<cdx-field>
						<template #label>Display name</template>
						<template #description>
							How other staff see you. Leave it empty to use {{ session.user.real_name || session.user.username }}.
						</template>
						<cdx-text-input v-model="profile.display_name" maxlength="80" :placeholder="session.user.real_name || session.user.username" />
					</cdx-field>

					<cdx-field>
						<template #label>Bio</template>
						<template #description>Shown on your profile page. Plain text, {{ profile.bio.length }} of 1000.</template>
						<cdx-text-area v-model="profile.bio" rows="4" maxlength="1000" placeholder="What you work on, languages you cover, when you are around." />
					</cdx-field>

					<div class="ts-settings__actions ts-settings__actions--start">
						<cdx-button action="progressive" weight="primary" :disabled="!profileDirty || savingProfile" @click="saveProfile">
							{{ savingProfile ? 'Saving…' : 'Save profile' }}
						</cdx-button>
						<cdx-button @click="viewProfile">
							<cdx-icon :icon="cdxIconUserAvatar" size="small" />
							View my profile
						</cdx-button>
					</div>
				</div>
			</cdx-tab>

			<cdx-tab name="appearance" label="Appearance">
				<div class="ts-settings__pane">
					<div class="ts-setting">
						<div class="ts-setting__text">
							<h3>Colour mode</h3>
							<p>Light or dark, or follow your device.</p>
						</div>
						<cdx-toggle-button-group v-model="preferences.theme" :buttons="themes" />
					</div>

					<div class="ts-setting">
						<div class="ts-setting__text">
							<h3>Layout</h3>
							<p>How much room things get.</p>
						</div>
						<cdx-toggle-button-group v-model="preferences.density" :buttons="densities" />
					</div>

					<div class="ts-setting">
						<div class="ts-setting__text">
							<h3>Text size</h3>
							<p>Scales the whole portal.</p>
						</div>
						<cdx-toggle-button-group v-model="preferences.textSize" :buttons="sizes" />
					</div>

					<div class="ts-setting ts-setting--end">
						<div class="ts-setting__text">
							<h3>Start again</h3>
							<p>Put every setting back to how it came.</p>
						</div>
						<cdx-button @click="reset">Reset all settings</cdx-button>
					</div>
				</div>
			</cdx-tab>

			<cdx-tab name="themes" label="Themes">
				<div class="ts-settings__pane">
					<template v-if="!editing">
						<section>
							<div class="ts-settings__title">
								<h3>Your themes</h3>
								<span class="ts-settings__actions">
									<cdx-button @click="importing = !importing">
										<cdx-icon :icon="cdxIconDownload" />
										Import
									</cdx-button>
									<cdx-button action="progressive" weight="primary" @click="startNew">
										<cdx-icon :icon="cdxIconAdd" />
										New theme
									</cdx-button>
								</span>
							</div>

							<div v-if="importing" class="ts-settings__import">
								<cdx-field :status="importError ? 'error' : 'default'" :messages="{ error: importError }">
									<cdx-text-input v-model="shared" placeholder="Paste a theme someone shared" aria-label="Theme text" @keydown.enter.prevent="doImport" />
								</cdx-field>
								<cdx-button :disabled="!shared.trim()" @click="doImport">Add it</cdx-button>
							</div>

							<p v-if="!preferences.themes.length" class="ts-meta">
								You have not made any yet. Start from scratch, or edit a preset below to make it yours.
							</p>

							<div class="ts-themes">
								<div
									v-for="t in preferences.themes"
									:key="t.id"
									class="ts-theme"
									:class="{ 'ts-theme--on': preferences.palette === t.id }"
								>
									<button type="button" class="ts-theme__pick" :aria-pressed="preferences.palette === t.id" @click="preferences.palette = t.id">
										<span class="ts-theme__preview" :style="previewStyle( t )"><i /><i /></span>
										<span class="ts-theme__name">{{ t.name }}</span>
									</button>
									<span class="ts-theme__tools">
										<cdx-button weight="quiet" size="small" aria-label="Edit this theme" @click="edit( t )"><cdx-icon :icon="cdxIconEdit" size="small" /></cdx-button>
										<cdx-button weight="quiet" size="small" aria-label="Copy this theme to share" @click="share( t )"><cdx-icon :icon="cdxIconShare" size="small" /></cdx-button>
										<cdx-button weight="quiet" size="small" action="destructive" aria-label="Delete this theme" @click="removeTheme( t )"><cdx-icon :icon="cdxIconTrash" size="small" /></cdx-button>
									</span>
								</div>
							</div>
						</section>

						<section>
							<div class="ts-settings__title"><h3>Presets</h3></div>
							<div class="ts-themes">
								<div
									v-for="t in presets"
									:key="t.id"
									class="ts-theme"
									:class="{ 'ts-theme--on': preferences.palette === t.id }"
								>
									<button type="button" class="ts-theme__pick" :aria-pressed="preferences.palette === t.id" @click="preferences.palette = t.id">
										<span class="ts-theme__preview" :style="previewStyle( t )"><i /><i /></span>
										<span class="ts-theme__name">{{ t.name }}</span>
									</button>
									<span class="ts-theme__tools">
										<cdx-button v-if="t.accent" weight="quiet" size="small" aria-label="Make a copy to edit" @click="customise( t )">
											<cdx-icon :icon="cdxIconEdit" size="small" />
										</cdx-button>
									</span>
								</div>
							</div>
						</section>

						<details class="ts-settings__more">
							<summary>Custom CSS</summary>
							<cdx-field>
								<template #description>
									Applied last, for you alone. Codex colour variables such as
									<code>--background-color-base</code> can be overridden on <code>:root</code>.
								</template>
								<cdx-text-area v-model="preferences.css" rows="6" class="ts-mono" placeholder=":root { --color-base: #f5e0dc; }" />
							</cdx-field>
						</details>
					</template>

					<template v-else>
						<div class="ts-settings__title">
							<h3>{{ isNew ? 'New theme' : 'Edit theme' }}</h3>
						</div>

						<cdx-field>
							<template #label>Name</template>
							<cdx-text-input v-model="draft.name" placeholder="Midnight orchid" />
						</cdx-field>

						<cdx-field>
							<template #label>Accent colour</template>
							<template #description>Buttons, links, selected items and focus rings.</template>
							<div class="ts-colour">
								<input v-model="draft.accent" type="color" aria-label="Accent colour">
								<cdx-text-input v-model="accentHex" aria-label="Accent colour as hex" placeholder="#7c5cff" />
							</div>
						</cdx-field>

						<cdx-field>
							<template #label>Background</template>
							<template #description>Optional. Leave it off to keep the normal light or dark surfaces, with the tint below.</template>
							<div class="ts-colour">
								<cdx-toggle-switch v-model="draft.hasBackground" aria-label="Use a custom background">Custom background</cdx-toggle-switch>
								<input v-if="draft.hasBackground" v-model="draft.background" type="color" aria-label="Background colour">
							</div>
						</cdx-field>

						<cdx-field v-if="!draft.hasBackground">
							<template #label>Surface tint · {{ draft.tint }}%</template>
							<template #description>How much of the accent is mixed into the backgrounds.</template>
							<input v-model.number="draft.tint" class="ts-settings__range" type="range" min="0" max="16" step="1" aria-label="Surface tint">
						</cdx-field>

						<cdx-field>
							<template #label>Background image</template>
							<template #description>Optional. Sits behind the pages, which turn slightly glassy so it shows through.</template>

							<div class="ts-bgimage">
								<span v-if="draft.image" class="ts-bgimage__thumb" :style="{ backgroundImage: `url( '${ draft.image }' )` }" />
								<cdx-button :disabled="uploadingBg" @click="bgPicker.click()">
									<cdx-icon :icon="cdxIconUpload" size="small" />
									{{ uploadingBg ? 'Uploading…' : ( draft.image ? 'Replace' : 'Choose an image' ) }}
								</cdx-button>
								<cdx-button v-if="draft.image" weight="quiet" action="destructive" @click="dropImage">Remove</cdx-button>
								<input ref="bgPicker" type="file" accept="image/png,image/jpeg,image/webp,image/gif" hidden @change="onPickBg">
							</div>
							<cdx-message v-if="bgError" type="error" :inline="true" :allow-user-dismiss="false">{{ bgError }}</cdx-message>
						</cdx-field>

						<cdx-field v-if="draft.image">
							<template #label>Image dimming · {{ draft.dim }}%</template>
							<template #description>Fades the picture into the background colour so text stays readable.</template>
							<input v-model.number="draft.dim" class="ts-settings__range" type="range" min="0" max="90" step="5" aria-label="Image dimming">
						</cdx-field>

						<div class="ts-theme__live" :style="previewStyle( draft )">
							<span>Preview</span>
							<i /><i />
						</div>

						<div class="ts-settings__actions">
							<cdx-button @click="editing = false">Cancel</cdx-button>
							<cdx-button action="progressive" weight="primary" :disabled="!draft.name.trim() || !validHex( draft.accent )" @click="saveDraft">
								Save and use
							</cdx-button>
						</div>
					</template>
				</div>
			</cdx-tab>

			<cdx-tab name="notifications" label="Notifications">
				<div class="ts-settings__pane">
					<section>
						<div class="ts-settings__title">
							<h3>Tell me when something I watch…</h3>
						</div>
						<div class="ts-toggles">
							<div v-for="e in events" :key="e.key" class="ts-toggle">
								<div class="ts-toggle__text">
									<strong>{{ e.label }}</strong>
									<span class="ts-meta">{{ e.hint }}</span>
								</div>
								<cdx-toggle-switch v-model="preferences.notify.events[ e.key ]" :aria-label="e.label" />
							</div>
						</div>
					</section>

					<section>
						<div class="ts-settings__title">
							<h3>Start watching automatically</h3>
						</div>
						<div class="ts-toggles">
							<div v-for="a in autos" :key="a.key" class="ts-toggle">
								<div class="ts-toggle__text"><strong>{{ a.label }}</strong></div>
								<cdx-toggle-switch v-model="preferences.notify.auto[ a.key ]" :aria-label="a.label" />
							</div>
						</div>
						<p class="ts-meta">You can always watch or stop watching a case or investigation from its page. You are never told about your own changes.</p>
					</section>
				</div>
			</cdx-tab>
		</cdx-tabs>
	</cdx-dialog>
</template>

<script setup>
import { computed, inject, reactive, ref, watch } from 'vue';
import {
	CdxButton, CdxDialog, CdxField, CdxIcon, CdxMessage, CdxTab, CdxTabs, CdxTextArea,
	CdxTextInput, CdxToggleButtonGroup, CdxToggleSwitch
} from '@wikimedia/codex';
import {
	cdxIconAdd, cdxIconCheck, cdxIconDownload, cdxIconEdit, cdxIconShare, cdxIconTrash, cdxIconUpload, cdxIconUserAvatar
} from '@wikimedia/codex-icons';
import { useRouter } from 'vue-router';
import UserAvatar from './UserAvatar.vue';
import { api } from '../lib/api.js';
import {
	allThemes, AUTO_SUBSCRIBE, exportTheme, newThemeId, NOTIFY_EVENTS, parseTheme,
	preferences, resetPreferences
} from '../lib/preferences.js';
import { session } from '../lib/session.js';
import { ui } from '../lib/ui.js';

defineProps( { open: { type: Boolean, default: false } } );
const emit = defineEmits( [ 'update:open' ] );

const router = useRouter();

function viewProfile() {
	emit( 'update:open', false );
	router.push( { name: 'profile', params: { id: session.user.id } } );
}

const notify = inject( 'notify' );

const themes = [
	{ value: 'system', label: 'Device' },
	{ value: 'light', label: 'Light' },
	{ value: 'dark', label: 'Dark' }
];
const densities = [ { value: 'compact', label: 'Compact' }, { value: 'roomy', label: 'Roomy' } ];
const sizes = [
	{ value: 'small', label: 'Small' },
	{ value: 'default', label: 'Default' },
	{ value: 'large', label: 'Large' },
	{ value: 'xlarge', label: 'Larger' }
];

const events = NOTIFY_EVENTS;
const autos = AUTO_SUBSCRIBE;

const presets = computed( () => [
	{ id: 'default', name: 'Default', accent: null },
	...allThemes().filter( ( t ) => t.preset )
] );

const profile = reactive( { display_name: session.user.display_name ?? '', bio: session.user.bio ?? '' } );
const savingProfile = ref( false );

watch( () => [ session.user.display_name, session.user.bio ], ( [ name, bio ] ) => {
	profile.display_name = name ?? '';
	profile.bio = bio ?? '';
} );

const profileDirty = computed( () => profile.display_name !== ( session.user.display_name ?? '' )
	|| profile.bio !== ( session.user.bio ?? '' ) );

async function saveProfile() {
	savingProfile.value = true;

	try {
		const saved = await api.saveProfile( { display_name: profile.display_name, bio: profile.bio } );
		session.user.display_name = saved.display_name;
		session.user.bio = saved.bio;
		session.user.shown_name = saved.shown_name;
		notify( 'Profile saved.' );
	} catch ( e ) {
		notify( e.message, 'error' );
	} finally {
		savingProfile.value = false;
	}
}

const picker = ref( null );
const uploading = ref( false );
const uploadError = ref( '' );

async function onPick( event ) {
	const file = event.target.files?.[ 0 ];
	event.target.value = '';

	if ( !file ) {
		return;
	}

	uploading.value = true;
	uploadError.value = '';

	try {
		const response = await api.uploadAvatar( file );
		session.user.avatar_url = response.avatar_url;
	} catch ( e ) {
		uploadError.value = e.message;
	} finally {
		uploading.value = false;
	}
}

async function removePicture() {
	try {
		await api.removeAvatar();
		session.user.avatar_url = null;
	} catch ( e ) {
		notify( e.message, 'error' );
	}
}

const editing = ref( false );
const isNew = ref( true );
const importing = ref( false );
const shared = ref( '' );
const importError = ref( '' );

const draft = reactive( { id: '', name: '', accent: '#7c5cff', tint: 6, hasBackground: false, background: '#1a1626', image: '', dim: 40 } );

const bgPicker = ref( null );
const uploadingBg = ref( false );
const bgError = ref( '' );
const staleImages = [];

const fileName = ( url ) => url.split( '/' ).pop();

async function onPickBg( event ) {
	const file = event.target.files?.[ 0 ];
	event.target.value = '';

	if ( !file ) {
		return;
	}

	uploadingBg.value = true;
	bgError.value = '';

	try {
		const { url } = await api.uploadBackground( file );
		if ( draft.image && draft.image !== originalImage ) {
			api.removeBackground( fileName( draft.image ) ).catch( () => {} );
		}
		draft.image = url;
	} catch ( e ) {
		bgError.value = e.message;
	} finally {
		uploadingBg.value = false;
	}
}

function dropImage() {
	if ( draft.image && draft.image !== originalImage ) {
		api.removeBackground( fileName( draft.image ) ).catch( () => {} );
	} else if ( draft.image ) {
		staleImages.push( draft.image );
	}
	draft.image = '';
}

let originalImage = '';

const validHex = ( v ) => /^#[0-9a-f]{6}$/i.test( v ?? '' );

const accentHex = computed( {
	get: () => draft.accent,
	set: ( v ) => {
		if ( validHex( v.trim() ) ) {
			draft.accent = v.trim();
		}
	}
} );

function previewStyle( t ) {
	const night = document.documentElement.getAttribute( 'data-theme' ) === 'dark'
		|| ( document.documentElement.getAttribute( 'data-theme' ) !== 'light' && matchMedia( '( prefers-color-scheme: dark )' ).matches );
	const base = night ? '#101418' : '#ffffff';
	const surface = t.background ?? ( t.accent && t.tint ? `color-mix( in srgb, ${ t.accent } ${ t.tint }%, ${ base } )` : base );

	return {
		'--ts-prev-accent': t.accent ?? '#3366cc',
		'--ts-prev-surface': surface,
		'--ts-prev-image': t.image ? `url( '${ t.image }' )` : 'none'
	};
}

function beginEdit( t, fresh ) {
	Object.assign( draft, {
		id: fresh ? newThemeId() : t.id,
		name: fresh ? '' : t.name,
		accent: t.accent ?? '#7c5cff',
		tint: Number( t.tint ?? 6 ),
		hasBackground: !!t.background,
		background: t.background ?? '#1a1626',
		image: fresh ? '' : ( t.image ?? '' ),
		dim: Number( t.dim ?? 40 )
	} );
	originalImage = draft.image;
	staleImages.length = 0;
	bgError.value = '';
	isNew.value = fresh;
	editing.value = true;
}

const startNew = () => beginEdit( {}, true );
const edit = ( t ) => beginEdit( t, false );
const customise = ( t ) => beginEdit( { ...t, name: '' }, true );

function saveDraft() {
	const theme = {
		id: draft.id,
		name: draft.name.trim(),
		accent: draft.accent,
		tint: draft.hasBackground ? 0 : Number( draft.tint ),
		...( draft.hasBackground ? { background: draft.background } : {} ),
		...( draft.image ? { image: draft.image, dim: Number( draft.dim ) } : {} )
	};
	const at = preferences.themes.findIndex( ( t ) => t.id === theme.id );

	if ( at === -1 ) {
		preferences.themes.push( theme );
	} else {
		preferences.themes.splice( at, 1, theme );
	}

	staleImages.forEach( ( url ) => api.removeBackground( fileName( url ) ).catch( () => {} ) );
	preferences.palette = theme.id;
	editing.value = false;
}

function removeTheme( t ) {
	if ( t.image ) {
		api.removeBackground( fileName( t.image ) ).catch( () => {} );
	}
	preferences.themes = preferences.themes.filter( ( x ) => x.id !== t.id );
	if ( preferences.palette === t.id ) {
		preferences.palette = 'default';
	}
}

async function share( t ) {
	try {
		await navigator.clipboard.writeText( exportTheme( t ) );
		notify( 'Theme copied. Send it to someone and they can import it.' );
	} catch ( e ) {
		importing.value = true;
		shared.value = exportTheme( t );
	}
}

function doImport() {
	importError.value = '';

	try {
		const theme = parseTheme( shared.value );
		preferences.themes.push( theme );
		preferences.palette = theme.id;
		shared.value = '';
		importing.value = false;
	} catch ( e ) {
		importError.value = e instanceof SyntaxError ? 'That does not look like a theme.' : e.message;
	}
}

function reset() {
	resetPreferences();
	notify( 'Back to the defaults.' );
}
</script>
