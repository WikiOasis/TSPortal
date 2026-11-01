import { createApp } from 'vue';
import '@wikimedia/codex/dist/codex.style.css';
import '../less/app.less';

import './lib/preferences.js';
import AppRoot from './AppRoot.vue';
import router from './router.js';

createApp( AppRoot ).use( router ).mount( '#app' );
