import {
	cdxIconArticle, cdxIconBlock, cdxIconChart, cdxIconFlag, cdxIconHistory, cdxIconRobot,
	cdxIconSearch, cdxIconTrash, cdxIconUserAvatar, cdxIconUserGroup, cdxIconViewDetails
} from '@wikimedia/codex-icons';
import { session } from './session.js';

export function sections() {
	return [
		{ name: 'dashboard', label: 'Dashboard', icon: cdxIconViewDetails, match: [ 'dashboard' ], words: 'home overview' },
		{ name: 'queue', label: 'Queue', icon: cdxIconFlag, match: [ 'queue', 'case' ], words: 'reports cases inbox', kind: 'case' },
		{ name: 'automation', label: 'Automation', icon: cdxIconRobot, match: [ 'automation' ], words: 'automated flags review ai' },
		{ name: 'investigations', label: 'Investigations', icon: cdxIconUserGroup, match: [ 'investigations', 'investigation' ], words: 'files', kind: 'investigation' },
		{ name: 'subjects', label: 'Accounts', icon: cdxIconUserAvatar, match: [ 'subjects', 'subject' ], words: 'users subjects people', kind: 'subject' },
		{ name: 'sanctions', label: 'Actions', icon: cdxIconBlock, match: [ 'sanctions' ], words: 'sanctions blocks bans locks', kind: 'sanction' },
		{ name: 'data-removals', label: 'Erasures', icon: cdxIconTrash, match: [ 'data-removals' ], words: 'removals data deletion gdpr', kind: 'removal' },
		{ name: 'analytics', label: 'Activity', icon: cdxIconChart, match: [ 'analytics' ], words: 'analytics stats charts' },
		{ name: 'checkuser', label: 'CheckUser log', icon: cdxIconSearch, match: [ 'checkuser' ], words: 'cu checks ip' },
		{ name: 'transparency', label: 'Transparency', icon: cdxIconArticle, match: [ 'transparency', 'transparency-report' ], words: 'reports published' },
		{ name: 'audit', label: 'Audit log', icon: cdxIconHistory, match: [ 'audit' ], words: 'history log' },
		...( session.can( 'user-manager' )
			? [ { name: 'staff', label: 'Team', icon: cdxIconUserGroup, match: [ 'staff' ], words: 'staff people members' } ]
			: [] )
	];
}
