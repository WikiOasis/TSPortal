const DATE = new Intl.DateTimeFormat( undefined, { dateStyle: 'medium' } );
const DATETIME = new Intl.DateTimeFormat( undefined, { dateStyle: 'medium', timeStyle: 'short' } );

export function date( iso ) {
	return iso ? DATE.format( new Date( iso ) ) : '—';
}

export function dateTime( iso ) {
	return iso ? DATETIME.format( new Date( iso ) ) : '—';
}

export function ago( iso ) {
	if ( !iso ) {
		return '—';
	}

	const seconds = Math.round( ( Date.now() - new Date( iso ).getTime() ) / 1000 );
	if ( seconds < 60 ) {
		return 'just now';
	}

	const rtf = new Intl.RelativeTimeFormat( undefined, { numeric: 'auto' } );
	const units = [
		[ 'year', 31536000 ],
		[ 'month', 2592000 ],
		[ 'week', 604800 ],
		[ 'day', 86400 ],
		[ 'hour', 3600 ],
		[ 'minute', 60 ]
	];

	for ( const [ unit, size ] of units ) {
		if ( Math.abs( seconds ) >= size ) {
			return rtf.format( -Math.round( seconds / size ), unit );
		}
	}

	return 'just now';
}

export function daysSince( iso ) {
	return iso ? Math.floor( ( Date.now() - new Date( iso ).getTime() ) / 86400000 ) : 0;
}

export const STATUS_LABELS = {
	received: 'Incoming',
	'in-review': 'Reviewing',
	investigating: 'Under investigation',
	'action-taken': 'Action taken',
	closed: 'Closed',
	rejected: 'Closed, no action'
};

export const STATUS_CHIP = {
	received: 'warning',
	'in-review': 'notice',
	investigating: 'notice',
	'action-taken': 'success',
	closed: 'notice',
	rejected: 'notice'
};

export const TYPE_LABELS = {
	report: 'Report',
	appeal: 'Appeal',
	contact: 'Message',
	data: 'Data request'
};

export const STANDING_LABELS = {
	good: 'Good standing',
	restricted: 'Restricted',
	suspended: 'Disabled'
};

export const STANDING_CHIP = {
	good: 'success',
	restricted: 'warning',
	suspended: 'error'
};

export const TIMELINE_KINDS = {
	filed: { label: 'Filed', tone: 'neutral' },
	comment: { label: 'Conversation', tone: 'public' },
	system: { label: 'Recorded', tone: 'neutral' },
	status: { label: 'Status', tone: 'neutral' },
	audit: { label: 'Change', tone: 'neutral' },
	action: { label: 'Action', tone: 'action' },
	'action-lifted': { label: 'Lifted', tone: 'neutral' },
	'action-expired': { label: 'Expired', tone: 'neutral' },
	investigation: { label: 'Investigation', tone: 'internal' },
	opened: { label: 'Opened', tone: 'internal' },
	note: { label: 'Note', tone: 'internal' },
	case: { label: 'Report', tone: 'neutral' },
	'data-removal': { label: 'Erasure', tone: 'action' },
	concluded: { label: 'Concluded', tone: 'action' }
};

export const SANCTION_TYPES = [
	{ value: 'note', label: 'Note on file', wikis: false, account: true },
	{ value: 'warning', label: 'Formal warning', wikis: false, account: true },
	{ value: 'block', label: 'Block on some wikis', wikis: true, account: true },
	{ value: 'lock', label: 'Disable account', wikis: false, account: true },
	{ value: 'wiki-deletion', label: 'Delete a wiki', wikis: true, account: false },
	{ value: 'other', label: 'Log something else', wikis: false, account: true, custom: true }
];

export const RETIRED_SANCTION_LABELS = {
	iban: 'Interaction ban',
	'partial-block': 'Partial block'
};

export function sanctionType( value ) {
	return SANCTION_TYPES.find( ( t ) => t.value === value ) ?? null;
}

export const PUSH_LABELS = {
	pending: 'Not sent yet',
	pushed: 'In force',
	recorded: 'Recorded',
	queued: 'Queued',
	manual: 'Needs doing by hand',
	failed: 'Failed',
	partial: 'Recorded, not enforced',
	acknowledged: 'Done by hand'
};

export const PUSH_CHIP = {
	pending: 'notice',
	pushed: 'success',
	recorded: 'notice',
	queued: 'notice',
	manual: 'warning',
	failed: 'error',
	partial: 'error',
	acknowledged: 'success'
};

export const FORCE_LABELS = {
	'in-force': 'In force',
	lifted: 'No longer in force'
};

export const FORCE_CHIP = {
	'in-force': 'error',
	lifted: 'notice'
};

export const INVESTIGATION_STATUS_LABELS = {
	open: 'Open',
	monitoring: 'Monitoring',
	concluded: 'Concluded',
	closed: 'Closed'
};

export const INVESTIGATION_STATUS_CHIP = {
	open: 'warning',
	monitoring: 'notice',
	concluded: 'success',
	closed: 'notice'
};

export const OUTCOME_LABELS = {
	'no-action': 'No action taken',
	warned: 'Warned',
	restricted: 'Restricted',
	suspended: 'Disabled',
	referred: 'Referred on',
	unfounded: 'Not borne out',
	'insufficient-evidence': 'Not enough to act on'
};

export const OUTCOMES = [
	{ value: 'suspended', label: 'Disabled the account' },
	{ value: 'restricted', label: 'Restricted the account' },
	{ value: 'warned', label: 'Warned the account' },
	{ value: 'no-action', label: 'No action taken' },
	{ value: 'unfounded', label: 'Looked into it; not borne out' },
	{ value: 'insufficient-evidence', label: 'Looked into it; not enough to act on' },
	{ value: 'referred', label: 'Referred on to someone else' }
];

export const NOTE_KINDS = [
	{ value: 'note', label: 'Note' },
	{ value: 'finding', label: 'Finding' },
	{ value: 'decision', label: 'Decision' },
	{ value: 'contact', label: 'Contact' },
	{ value: 'evidence', label: 'Evidence' }
];

export const NOTE_KIND_LABELS = {
	note: 'Note',
	finding: 'Finding',
	decision: 'Decision',
	contact: 'Contact',
	evidence: 'Evidence'
};

export const SUBJECT_ROLES = [
	{ value: 'subject', label: 'Subject of the file' },
	{ value: 'witness', label: 'Witness' },
	{ value: 'reporter', label: 'Reporter' },
	{ value: 'related', label: 'Related account' }
];

export const ROLE_LABELS = {
	subject: 'Subject',
	witness: 'Witness',
	reporter: 'Reporter',
	related: 'Related',
	reported: 'Reported',
	mentioned: 'Mentioned'
};

export const ROLE_CHIP = {
	subject: 'error',
	reported: 'error',
	witness: 'notice',
	reporter: 'notice',
	related: 'notice',
	mentioned: 'notice'
};

export function chipTone( kind, value ) {
	const maps = {
		case: STATUS_CHIP,
		force: FORCE_CHIP,
		standing: STANDING_CHIP,
		push: PUSH_CHIP,
		investigation: INVESTIGATION_STATUS_CHIP,
		priority: PRIORITY_CHIP,
		removal: REMOVAL_CHIP,
		role: ROLE_CHIP,
		'appeal-outcome': APPEAL_OUTCOME_CHIP,
		'appeal-confidence': APPEAL_CONFIDENCE_CHIP
	};

	return maps[ kind ]?.[ value ] ?? 'notice';
}

export const PRIORITIES = [
	{ value: 'urgent', label: 'Urgent' },
	{ value: 'high', label: 'High' },
	{ value: 'normal', label: 'Normal' },
	{ value: 'low', label: 'Low' }
];

export const PRIORITY_LABELS = {
	urgent: 'Urgent',
	high: 'High',
	normal: 'Normal',
	low: 'Low'
};

export const PRIORITY_CHIP = {
	urgent: 'error',
	high: 'warning',
	normal: 'notice',
	low: 'notice'
};

export const REMOVAL_LABELS = {
	requested: 'Waiting for a second review',
	approved: 'Approved',
	renaming: 'Renaming',
	renamed: 'Renamed',
	scrubbing: 'Removing data',
	done: 'Done',
	refused: 'Refused',
	failed: 'Could not be completed'
};

export const REMOVAL_CHIP = {
	requested: 'warning',
	approved: 'notice',
	renaming: 'notice',
	renamed: 'notice',
	scrubbing: 'notice',
	done: 'success',
	refused: 'notice',
	failed: 'error'
};

export const DATA_KINDS = [
	{ value: 'erasure', label: 'Deletion of their data' },
	{ value: 'rectification', label: 'Correcting something' },
	{ value: 'other', label: 'Something else' }
];

export const DATA_KIND_LABELS = {
	erasure: 'Deletion of their data',
	rectification: 'Correcting something',
	other: 'Something else'
};

export const DATA_DECISION_LABELS = {
	approved: 'Approved',
	declined: 'Declined'
};

export const DATA_DECISION_CHIP = {
	approved: 'success',
	declined: 'notice'
};

export const APPEAL_OUTCOMES = [
	{ value: 'granted', label: 'Granted' },
	{ value: 'partly-granted', label: 'Partly granted' },
	{ value: 'declined', label: 'Declined' },
	{ value: 'withdrawn', label: 'Withdrawn' },
	{ value: 'invalid', label: 'Invalid' }
];

export const APPEAL_OUTCOME_LABELS = {
	granted: 'Granted',
	'partly-granted': 'Partly granted',
	declined: 'Declined',
	withdrawn: 'Withdrawn',
	invalid: 'Nothing to appeal'
};

export const APPEAL_OUTCOME_CHIP = {
	granted: 'success',
	'partly-granted': 'success',
	declined: 'notice',
	withdrawn: 'notice',
	invalid: 'notice'
};

export const APPEAL_LINK_LABELS = {
	stated: 'They gave this reference',
	quoted: 'Found in what they wrote',
	'only-action': 'The only action on their file',
	'latest-action': 'Their most recent action — a guess',
	staff: 'Set by hand',
	none: 'Not matched to an action'
};

export const APPEAL_CONFIDENCE_LABELS = {
	certain: 'Certain',
	likely: 'Likely',
	guess: 'A guess',
	none: 'No match'
};

export const APPEAL_CONFIDENCE_CHIP = {
	certain: 'success',
	likely: 'notice',
	guess: 'warning',
	none: 'warning'
};

export const LEGAL_BASES = [
	{ value: 'gdpr-17', label: 'GDPR Article 17 (erasure)' },
	{ value: 'gdpr-16', label: 'GDPR Article 16 (rectification)' },
	{ value: 'ccpa', label: 'CCPA' },
	{ value: 'minor', label: 'Account belonged to a child' },
	{ value: 'safety', label: 'Safety of the account holder' },
	{ value: 'goodwill', label: 'Goodwill' }
];

export function hours( value ) {
	if ( value === null || value === undefined ) {
		return '\u2014';
	}
	if ( value < 1 ) {
		return `${ Math.round( value * 60 ) } minutes`;
	}
	if ( value < 48 ) {
		return `${ Math.round( value ) } hours`;
	}

	const days = value / 24;

	return days < 60 ? `${ Math.round( days ) } days` : `${ ( days / 7 ).toFixed( 0 ) } weeks`;
}

export function share( value ) {
	return value === null || value === undefined
		? '\u2014'
		: `${ Math.round( value * 100 ) }% of checks`;
}

export const CHECK_TYPE_LABELS = {
	ipedits: 'Edits from an IP',
	ipusers: 'Accounts on an IP',
	'ipedits-xff': 'Edits from an IP (XFF)',
	'ipusers-xff': 'Accounts on an IP (XFF)',
	userips: 'IPs used by an account',
	useredits: 'Edits by an account',
	investigate: 'Investigate'
};

export const CHECK_TARGET_LABELS = {
	account: 'Account',
	ip: 'IP address',
	range: 'IP range',
	name: 'Name',
	unknown: 'Not recorded'
};

export const ACTION_REASONS = [
	{ value: 'harassment', label: 'Harassment' },
	{ value: 'threats', label: 'Threats or intimidation' },
	{ value: 'child-protection', label: 'Child protection' },
	{ value: 'self-harm', label: 'Risk of self-harm' },
	{ value: 'doxxing', label: 'Disclosure of personal information' },
	{ value: 'sockpuppetry', label: 'Sockpuppetry or block evasion' },
	{ value: 'spam', label: 'Spam or advertising' },
	{ value: 'vandalism', label: 'Persistent vandalism' },
	{ value: 'copyright', label: 'Copyright or licensing' },
	{ value: 'illegal-content', label: 'Illegal content' },
	{ value: 'legal-demand', label: 'Legal demand or court order' },
	{ value: 'data-protection', label: 'Data protection obligation' },
	{ value: 'security', label: 'Account or platform security' },
	{ value: 'other', label: 'Something else' }
];

export const ACTION_REASON_LABELS = Object.fromEntries(
	ACTION_REASONS.map( ( r ) => [ r.value, r.label ] )
);

export function fileSize( bytes ) {
	const value = Number( bytes );

	if ( !isFinite( value ) || value <= 0 ) {
		return '';
	}
	if ( value < 1024 ) {
		return value + ' B';
	}
	if ( value < 1024 * 1024 ) {
		return Math.round( value / 1024 ) + ' KB';
	}
	return ( value / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
}
