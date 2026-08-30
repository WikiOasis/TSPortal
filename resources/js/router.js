import { createRouter, createWebHistory } from 'vue-router';
import { session } from './lib/session.js';

import DashboardView from './views/DashboardView.vue';
import QueueView from './views/QueueView.vue';
import CaseView from './views/CaseView.vue';
import InvestigationsView from './views/InvestigationsView.vue';
import InvestigationView from './views/InvestigationView.vue';
import DataRemovalsView from './views/DataRemovalsView.vue';
import SubjectsView from './views/SubjectsView.vue';
import SubjectView from './views/SubjectView.vue';
import SanctionsView from './views/SanctionsView.vue';
import StaffView from './views/StaffView.vue';
import AnalyticsView from './views/AnalyticsView.vue';
import CheckUserView from './views/CheckUserView.vue';
import TransparencyView from './views/TransparencyView.vue';
import TransparencyReportView from './views/TransparencyReportView.vue';
import AuditView from './views/AuditView.vue';
import LoginView from './views/LoginView.vue';
import NoAccessView from './views/NoAccessView.vue';
import ItemView from './views/ItemView.vue';
import NotFoundView from './views/NotFoundView.vue';

const routes = [
	{ path: '/', name: 'dashboard', component: DashboardView, meta: { title: 'Dashboard' } },
	{ path: '/queue', name: 'queue', component: QueueView, meta: { title: 'Queue' } },
	{ path: '/cases/:id', name: 'case', component: CaseView, props: true, meta: { title: 'Case' } },

	{ path: '/files', name: 'investigations', component: InvestigationsView, meta: { title: 'Investigations' } },
	{ path: '/files/:id', name: 'investigation', component: InvestigationView, props: true, meta: { title: 'Investigation' } },
	{ path: '/accounts', name: 'subjects', component: SubjectsView, meta: { title: 'Accounts' } },
	{ path: '/accounts/:id', name: 'subject', component: SubjectView, props: true, meta: { title: 'Account' } },
	{ path: '/actions', name: 'sanctions', component: SanctionsView, meta: { title: 'Actions' } },
	{ path: '/erasures', name: 'data-removals', component: DataRemovalsView, meta: { title: 'Erasures' } },
	{ path: '/activity', name: 'analytics', component: AnalyticsView, meta: { title: 'Activity' } },
	{ path: '/checkuser', name: 'checkuser', component: CheckUserView, meta: { title: 'CheckUser log' } },
	{ path: '/transparency', name: 'transparency', component: TransparencyView, meta: { title: 'Transparency reports' } },
	{ path: '/transparency/:id', name: 'transparency-report', component: TransparencyReportView, props: true, meta: { title: 'Transparency report' } },

	{ path: '/item/:reference', name: 'item', component: ItemView, props: true, meta: { title: 'Looking up' } },

	{ path: '/audit', name: 'audit', component: AuditView, meta: { title: 'Audit log' } },
	{ path: '/team', name: 'staff', component: StaffView, meta: { title: 'Team' } },

	{ path: '/login', name: 'login', component: LoginView, meta: { public: true, title: 'Sign in' } },
	{ path: '/no-access', name: 'no-access', component: NoAccessView, meta: { public: true, title: 'No access' } },
	{ path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundView, meta: { public: true, title: 'Not found' } }
];

const router = createRouter( {
	history: createWebHistory(),
	routes,
	scrollBehavior: ( to, from, saved ) => saved ?? { top: 0 }
} );

router.beforeEach( async ( to ) => {
	if ( !session.loaded ) {
		await session.load();
	}

	if ( to.meta.public ) {
		if ( to.name === 'login' && session.isStaff ) {
			return { name: 'dashboard' };
		}
		return true;
	}

	if ( !session.signedIn ) {
		return { name: 'login', query: { next: to.fullPath } };
	}

	if ( !session.isStaff ) {
		return { name: 'no-access' };
	}

	return true;
} );

router.afterEach( ( to ) => {
	const appName = window.TSPortal?.appName ?? 'TSPortal';
	document.title = to.meta.title ? `${ to.meta.title } · ${ appName }` : appName;
} );

export default router;
