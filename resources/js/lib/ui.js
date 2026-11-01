import { reactive } from 'vue';

export const ui = reactive( {
	settingsOpen: false,
	settingsTab: 'profile',

	openSettings( tab = 'profile' ) {
		this.settingsTab = tab;
		this.settingsOpen = true;
	}
} );
