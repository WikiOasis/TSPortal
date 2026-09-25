const csrf = () => document.querySelector( 'meta[name="csrf-token"]' )?.content ?? '';

export class ApiError extends Error {
	constructor( status, payload ) {
		super( payload?.message || `The portal returned HTTP ${ status }.` );
		this.name = 'ApiError';
		this.status = status;
		this.code = payload?.error ?? null;

		this.errors = payload?.errors ?? null;
	}
}

async function request( method, path, body, options = {} ) {
	const response = await fetch( `/api${ path }`, {
		method,
		credentials: 'same-origin',
		signal: options.signal,
		headers: {
			Accept: 'application/json',
			'X-Requested-With': 'XMLHttpRequest',
			...( body !== undefined ? { 'Content-Type': 'application/json' } : {} ),
			...( method === 'GET' ? {} : { 'X-CSRF-TOKEN': csrf() } )
		},
		body: body !== undefined ? JSON.stringify( body ) : undefined
	} );

	if ( response.status === 204 ) {
		return null;
	}

	let payload = null;
	try {
		payload = await response.json();
	} catch ( e ) {
		payload = null;
	}

	if ( !response.ok ) {
		throw new ApiError( response.status, payload );
	}

	return payload;
}

export function query( params ) {
	const usable = Object.entries( params ?? {} )
		.filter( ( [ , v ] ) => v !== null && v !== undefined && v !== '' && v !== false );

	return usable.length ? `?${ new URLSearchParams( usable ).toString() }` : '';
}

export const api = {
	get: ( path, options ) => request( 'GET', path, undefined, options ),
	post: ( path, body, options ) => request( 'POST', path, body ?? {}, options ),
	patch: ( path, body, options ) => request( 'PATCH', path, body ?? {}, options ),
	delete: ( path, options ) => request( 'DELETE', path, undefined, options ),

	session: () => request( 'GET', '/portal/session' ),
	dashboard: () => request( 'GET', '/portal/dashboard' ),

	cases: ( params ) => request( 'GET', `/portal/cases${ query( params ) }` ),
	case: ( id ) => request( 'GET', `/portal/cases/${ id }` ),
	updateCase: ( id, body ) => request( 'PATCH', `/portal/cases/${ id }`, body ),
	claimCase: ( id ) => request( 'POST', `/portal/cases/${ id }/claim`, {} ),
	commentOnCase: ( id, body ) => request( 'POST', `/portal/cases/${ id }/comments`, body ),
	caseTimeline: ( id ) => request( 'GET', `/portal/cases/${ id }/timeline` ),

	duplicateCandidates: ( id ) => request( 'GET', `/portal/cases/${ id }/duplicates/candidates` ),
	markDuplicate: ( id, body ) => request( 'POST', `/portal/cases/${ id }/duplicate`, body ),
	undoDuplicate: ( id, body ) => request( 'DELETE', `/portal/cases/${ id }/duplicate`, body ),

	setDataKind: ( id, body ) => request( 'PATCH', `/portal/cases/${ id }/data-request`, body ),
	linkAppealAction: ( id, reference ) => request( 'PUT', `/portal/cases/${ id }/appeal/action`, { reference } ),
	decideAppeal: ( id, body ) => request( 'POST', `/portal/cases/${ id }/appeal/decision`, body ),

	approveDataRequest: ( id, body ) => request( 'POST', `/portal/cases/${ id }/data-request/approve`, body ),
	declineDataRequest: ( id, body ) => request( 'POST', `/portal/cases/${ id }/data-request/decline`, body ),

	investigations: ( params ) => request( 'GET', `/portal/investigations${ query( params ) }` ),
	investigation: ( id ) => request( 'GET', `/portal/investigations/${ id }` ),
	openInvestigation: ( body ) => request( 'POST', '/portal/investigations', body ),
	openInvestigationsFromCases: ( body ) => request( 'POST', '/portal/investigations/from-cases', body ),
	updateInvestigation: ( id, body ) => request( 'PATCH', `/portal/investigations/${ id }`, body ),
	investigationTimeline: ( id ) => request( 'GET', `/portal/investigations/${ id }/timeline` ),
	addInvestigationNote: ( id, body ) => request( 'POST', `/portal/investigations/${ id }/notes`, body ),
	addInvestigationSubject: ( id, body ) => request( 'POST', `/portal/investigations/${ id }/subjects`, body ),
	addInvestigationSubjects: ( id, body ) => request( 'POST', `/portal/investigations/${ id }/subjects/bulk`, body ),
	previewSubjectNames: ( text, options ) => request( 'POST', '/portal/investigations/subjects/preview', { text }, options ),
	bulkInvestigationAction: ( id, body ) => request( 'POST', `/portal/investigations/${ id }/bulk-actions`, body ),
	previewPages: ( text, wiki, options ) => request( 'POST', '/portal/investigations/pages/preview', { text, wiki }, options ),
	addInvestigationPages: ( id, body ) => request( 'POST', `/portal/investigations/${ id }/pages`, body ),
	refreshInvestigationPages: ( id, body ) => request( 'POST', `/portal/investigations/${ id }/pages/refresh`, body ?? {} ),
	removeInvestigationPage: ( id, pageId ) => request( 'DELETE', `/portal/investigations/${ id }/pages/${ pageId }` ),
	removeInvestigationSubject: ( id, subjectId ) => request( 'DELETE', `/portal/investigations/${ id }/subjects/${ subjectId }` ),
	attachCase: ( id, body ) => request( 'POST', `/portal/investigations/${ id }/cases`, body ),
	detachCase: ( id, caseId ) => request( 'DELETE', `/portal/investigations/${ id }/cases/${ caseId }` ),
	concludeInvestigation: ( id, body ) => request( 'POST', `/portal/investigations/${ id }/conclude`, body ),
	closeInvestigation: ( id, body ) => request( 'POST', `/portal/investigations/${ id }/close`, body ),
	reopenInvestigation: ( id ) => request( 'POST', `/portal/investigations/${ id }/reopen`, {} ),

	subjects: ( params ) => request( 'GET', `/portal/subjects${ query( params ) }` ),
	subject: ( id ) => request( 'GET', `/portal/subjects/${ id }` ),
	updateSubject: ( id, body ) => request( 'PATCH', `/portal/subjects/${ id }`, body ),
	resolveSubject: ( username ) => request( 'POST', '/portal/subjects/resolve', { username } ),

	sanctions: ( params ) => request( 'GET', `/portal/sanctions${ query( params ) }` ),
	issueSanction: ( subjectId, body ) => request( 'POST', `/portal/subjects/${ subjectId }/sanctions`, body ),
	issueAction: ( body ) => request( 'POST', '/portal/actions', body ),
	deletePages: ( investigationId, body ) => request( 'POST', `/portal/investigations/${ investigationId }/page-deletions`, body ),
	liftSanction: ( id, body ) => request( 'POST', `/portal/sanctions/${ id }/lift`, body ),
	acknowledgeSanction: ( id, body ) => request( 'POST', `/portal/sanctions/${ id }/acknowledge`, body ?? {} ),

	removals: ( params ) => request( 'GET', `/portal/removals${ query( params ) }` ),
	removal: ( id ) => request( 'GET', `/portal/removals/${ id }` ),
	requestRemoval: ( subjectId, body ) => request( 'POST', `/portal/subjects/${ subjectId }/removals`, body ),
	eraseRemoval: ( id ) => request( 'POST', `/portal/removals/${ id }/erase`, {} ),
	refuseRemoval: ( id, body ) => request( 'POST', `/portal/removals/${ id }/refuse`, body ),
	retryRemoval: ( id ) => request( 'POST', `/portal/removals/${ id }/retry`, {} ),

	object: ( reference ) => request( 'GET', `/portal/objects/${ encodeURIComponent( reference ) }` ),
	findObjects: ( q, params ) => request( 'GET', `/portal/objects/search${ query( { q, ...params } ) }` ),
	searchHelp: () => request( 'GET', '/portal/search/help' ),

	search: ( q, params, options ) => request( 'GET', `/portal/search${ query( { q, ...params } ) }`, undefined, options ),
	searchRecents: ( options ) => request( 'GET', '/portal/search/recents', undefined, options ),
	searchPreview: ( kind, id, options ) => request( 'GET', `/portal/search/preview/${ kind }/${ id }`, undefined, options ),
	searchRelated: ( kind, id, params, options ) => request( 'GET', `/portal/search/related/${ kind }/${ id }${ query( params ) }`, undefined, options ),

	wikis: ( params ) => request( 'GET', `/portal/wikis${ query( params ) }` ),

	staff: () => request( 'GET', '/portal/staff' ),
	updateStaff: ( id, body ) => request( 'PATCH', `/portal/staff/${ id }`, body ),

	audit: ( params ) => request( 'GET', `/portal/audit${ query( params ) }` ),

	analytics: ( params ) => request( 'GET', `/portal/analytics${ query( params ) }` ),

	autoReview: () => request( 'GET', '/portal/autoreview' ),
	autoReviewStats: () => request( 'GET', '/portal/autoreview/stats' ),
	autoReviewItems: ( params, options ) => request( 'GET', `/portal/autoreview/items${ query( params ) }`, undefined, options ),
	autoReviewItem: ( id, options ) => request( 'GET', `/portal/autoreview/items/${ id }`, undefined, options ),
	autoReviewGroups: ( params ) => request( 'GET', `/portal/autoreview/groups${ query( params ) }` ),
	autoReviewClassify: ( body ) => request( 'POST', '/portal/autoreview/classify', body ?? {} ),
	autoReviewClose: ( body ) => request( 'POST', '/portal/autoreview/close', body ),
	autoReviewMerge: ( body ) => request( 'POST', '/portal/autoreview/merge', body ),
	autoReviewFold: () => request( 'POST', '/portal/autoreview/fold', {} ),
	autoReviewTake: ( id ) => request( 'POST', `/portal/autoreview/items/${ id }/take`, {} ),
	autoReviewBucket: ( id, bucket ) => request( 'PUT', `/portal/autoreview/items/${ id }/bucket`, { bucket } ),

	categoriseCase: ( id, categories ) => request( 'PUT', `/portal/cases/${ id }/categories`, { categories } ),

	checkUserChecks: ( params ) => request( 'GET', `/portal/checkuser${ query( params ) }` ),

	transparencyReports: ( params ) => request( 'GET', `/portal/transparency${ query( params ) }` ),
	transparencyReport: ( id ) => request( 'GET', `/portal/transparency/${ id }` ),
	openTransparencyReport: ( body ) => request( 'POST', '/portal/transparency', body ),
	updateTransparencyReport: ( id, body ) => request( 'PATCH', `/portal/transparency/${ id }`, body ),
	regenerateTransparencyReport: ( id ) => request( 'POST', `/portal/transparency/${ id }/regenerate`, {} ),
	publishTransparencyReport: ( id ) => request( 'POST', `/portal/transparency/${ id }/publish`, {} )
};
