import { reactive } from 'vue';
import { api } from './api.js';

export const session = reactive( {
	loaded: false,
	user: null,
	wiki: {},

	get signedIn() {
		return this.user !== null;
	},

	get isStaff() {
		return this.user !== null && this.user.flags.includes( 'ts' );
	},

	can( flag ) {
		return this.user !== null && this.user.flags.includes( flag );
	},

	async load() {
		try {
			const data = await api.session();
			this.user = data.user;
			this.wiki = data.wiki ?? {};
		} catch ( e ) {
			this.user = null;
		} finally {
			this.loaded = true;
		}
	}
} );
