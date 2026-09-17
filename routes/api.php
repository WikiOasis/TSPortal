<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Portal;
use App\Http\Controllers\Api\Wiki;
use App\Models\User;
use App\Services\Safety\AttachmentStore;
use Illuminate\Support\Facades\Route;

Route::prefix('wiki/v1')->middleware('wiki.signed')->name('wiki.')->group(function () {

    Route::get('/health', fn () => response()->json([
        'ok' => true,
        'portal' => config('app.name'),
        'time' => now()->toIso8601String(),
        'supported_actions' => array_values((array) config('mediawiki.supported_actions', [])),

        'attachments' => (function () {
            $store = app(AttachmentStore::class);

            return [
                'enabled' => $store->accepting(),

                'max_bytes' => $store->effectiveMaxBytes(),
                'configured_max_bytes' => $store->maxBytes(),

                'max_per_case' => $store->maxPerCase(),
                'accepted_mime' => $store->allowedMime(),
            ];
        })(),
    ]))->name('health');

    Route::post('/submissions', [Wiki\SubmissionController::class, 'store'])->name('submissions.store');

    Route::post('/appeals', [Wiki\AppealController::class, 'store'])->name('appeals.store');

    Route::get('/accounts/{account}/reports', [Wiki\AccountController::class, 'reports'])->name('accounts.reports');
    Route::get('/accounts/{account}/reports/{reference}', [Wiki\AccountController::class, 'report'])->name('accounts.report');
    Route::get('/accounts/{account}/standing', [Wiki\AccountController::class, 'standing'])->name('accounts.standing');

    Route::get('/accounts/{account}/login-status', [Wiki\AccountController::class, 'loginStatus'])->name('accounts.login-status');

    Route::post('/cases/{reference}/comments', [Wiki\CommentController::class, 'store'])->name('cases.comments');

    Route::post('/cases/{reference}/attachments', [Wiki\AttachmentController::class, 'store'])->name('cases.attachments');

    Route::post('/wikis', [Wiki\WikiListController::class, 'store'])->name('wikis.store');

    Route::post('/checkuser-checks', [Wiki\CheckUserController::class, 'store'])->name('checkuser.store');

    Route::post('/actions/{reference}/progress', [Wiki\ActionProgressController::class, 'store'])->name('actions.progress');

    Route::get('/removals/{reference}', [Wiki\DataRemovalController::class, 'check'])->name('removals.check');
    Route::post('/removals/{reference}/progress', [Wiki\DataRemovalController::class, 'progress'])->name('removals.progress');
});

Route::prefix('portal')->name('portal.')->group(function () {

    Route::get('/session', Portal\SessionController::class)->name('session');

    Route::middleware('staff')->group(function () {
        Route::get('/dashboard', Portal\DashboardController::class)->name('dashboard');

        Route::get('/cases', [Portal\CaseController::class, 'index'])->name('cases.index');
        Route::get('/cases/{case}', [Portal\CaseController::class, 'show'])->name('cases.show');
        Route::patch('/cases/{case}', [Portal\CaseController::class, 'update'])->name('cases.update');
        Route::post('/cases/{case}/claim', [Portal\CaseController::class, 'claim'])->name('cases.claim');
        Route::post('/cases/{case}/comments', [Portal\CaseController::class, 'comment'])->name('cases.comment');
        Route::get('/cases/{case}/timeline', [Portal\CaseController::class, 'timeline'])->name('cases.timeline');

        Route::get('/cases/{case}/duplicates/candidates', [Portal\CaseController::class, 'duplicateCandidates'])->name('cases.duplicates.candidates');
        Route::post('/cases/{case}/duplicate', [Portal\CaseController::class, 'markDuplicate'])->name('cases.duplicate');
        Route::delete('/cases/{case}/duplicate', [Portal\CaseController::class, 'undoDuplicate'])->name('cases.duplicate.undo');

        Route::put('/cases/{case}/categories', [Portal\CaseController::class, 'categorise'])->name('cases.categorise');

        Route::get('/attachments/{attachment}', [Portal\AttachmentController::class, 'show'])->name('attachments.show');

        Route::put('/cases/{case}/appeal/action', [Portal\AppealController::class, 'link'])->name('appeals.link');
        Route::post('/cases/{case}/appeal/decision', [Portal\AppealController::class, 'decide'])->name('appeals.decide');

        Route::patch('/cases/{case}/data-request', [Portal\DataRequestController::class, 'setKind'])->name('data.kind');
        Route::post('/cases/{case}/data-request/approve', [Portal\DataRequestController::class, 'approve'])->name('data.approve');
        Route::post('/cases/{case}/data-request/decline', [Portal\DataRequestController::class, 'decline'])->name('data.decline');

        Route::get('/investigations', [Portal\InvestigationController::class, 'index'])->name('investigations.index');
        Route::post('/investigations', [Portal\InvestigationController::class, 'store'])->name('investigations.store');
        Route::get('/investigations/{investigation}', [Portal\InvestigationController::class, 'show'])->name('investigations.show');
        Route::patch('/investigations/{investigation}', [Portal\InvestigationController::class, 'update'])->name('investigations.update');
        Route::get('/investigations/{investigation}/timeline', [Portal\InvestigationController::class, 'timeline'])->name('investigations.timeline');
        Route::post('/investigations/{investigation}/notes', [Portal\InvestigationController::class, 'note'])->name('investigations.note');
        Route::post('/investigations/{investigation}/subjects', [Portal\InvestigationController::class, 'addSubject'])->name('investigations.subjects.add');
        Route::post('/investigations/{investigation}/subjects/bulk', [Portal\InvestigationController::class, 'addSubjects'])->name('investigations.subjects.bulk');
        Route::post('/investigations/subjects/preview', [Portal\InvestigationController::class, 'preview'])->name('investigations.subjects.preview');
        Route::post('/investigations/{investigation}/bulk-actions', [Portal\InvestigationController::class, 'bulkAction'])->name('investigations.bulk-actions');
        Route::delete('/investigations/{investigation}/subjects/{subject}', [Portal\InvestigationController::class, 'removeSubject'])->name('investigations.subjects.remove');
        Route::post('/investigations/{investigation}/cases', [Portal\InvestigationController::class, 'attachCase'])->name('investigations.cases.attach');
        Route::delete('/investigations/{investigation}/cases/{case}', [Portal\InvestigationController::class, 'detachCase'])->name('investigations.cases.detach');
        Route::post('/investigations/{investigation}/conclude', [Portal\InvestigationController::class, 'conclude'])->name('investigations.conclude');
        Route::post('/investigations/{investigation}/close', [Portal\InvestigationController::class, 'close'])->name('investigations.close');
        Route::post('/investigations/{investigation}/reopen', [Portal\InvestigationController::class, 'reopen'])->name('investigations.reopen');

        Route::get('/objects/search', [Portal\ObjectController::class, 'search'])->name('objects.search');
        Route::get('/search/help', [Portal\ObjectController::class, 'help'])->name('search.help');
        Route::get('/objects/{reference}', [Portal\ObjectController::class, 'show'])->name('objects.show');

        Route::get('/search', [Portal\SearchController::class, 'find'])->name('search.find');
        Route::get('/search/recents', [Portal\SearchController::class, 'recents'])->name('search.recents');
        Route::get('/search/preview/{kind}/{id}', [Portal\SearchController::class, 'preview'])
            ->whereNumber('id')
            ->name('search.preview');
        Route::get('/search/related/{kind}/{id}', [Portal\SearchController::class, 'related'])
            ->whereNumber('id')
            ->name('search.related');

        Route::get('/subjects', [Portal\SubjectController::class, 'index'])->name('subjects.index');
        Route::post('/subjects/resolve', [Portal\SubjectController::class, 'resolve'])->name('subjects.resolve');
        Route::get('/subjects/{subject}', [Portal\SubjectController::class, 'show'])->name('subjects.show');
        Route::patch('/subjects/{subject}', [Portal\SubjectController::class, 'update'])->name('subjects.update');

        Route::get('/sanctions', [Portal\SanctionController::class, 'index'])->name('sanctions.index');
        Route::post('/subjects/{subject}/sanctions', [Portal\SanctionController::class, 'store'])->name('sanctions.store');

        Route::post('/actions', [Portal\SanctionController::class, 'store'])->name('actions.store');
        Route::post('/sanctions/{sanction}/lift', [Portal\SanctionController::class, 'lift'])->name('sanctions.lift');
        Route::post('/sanctions/{sanction}/acknowledge', [Portal\SanctionController::class, 'acknowledge'])->name('sanctions.acknowledge');

        Route::get('/removals', [Portal\DataRemovalController::class, 'index'])->name('removals.index');
        Route::get('/removals/{dataRemoval}', [Portal\DataRemovalController::class, 'show'])->name('removals.show');
        Route::post('/subjects/{subject}/removals', [Portal\DataRemovalController::class, 'store'])->name('removals.store');
        Route::post('/removals/{dataRemoval}/erase', [Portal\DataRemovalController::class, 'erase'])->name('removals.erase');
        Route::post('/removals/{dataRemoval}/refuse', [Portal\DataRemovalController::class, 'refuse'])->name('removals.refuse');
        Route::post('/removals/{dataRemoval}/retry', [Portal\DataRemovalController::class, 'retry'])->name('removals.retry');

        Route::get('/wikis', [Portal\WikiController::class, 'index'])->name('wikis.index');

        Route::get('/analytics', Portal\AnalyticsController::class)->name('analytics');

        Route::get('/checkuser', [Portal\CheckUserController::class, 'index'])->name('checkuser.index');

        Route::get('/transparency', [Portal\TransparencyController::class, 'index'])->name('transparency.index');
        Route::post('/transparency', [Portal\TransparencyController::class, 'store'])->name('transparency.store');
        Route::get('/transparency/{transparencyReport}', [Portal\TransparencyController::class, 'show'])->name('transparency.show');
        Route::patch('/transparency/{transparencyReport}', [Portal\TransparencyController::class, 'update'])->name('transparency.update');
        Route::post('/transparency/{transparencyReport}/regenerate', [Portal\TransparencyController::class, 'regenerate'])->name('transparency.regenerate');
        Route::get('/transparency/{transparencyReport}/export', [Portal\TransparencyController::class, 'export'])->name('transparency.export');
        Route::post('/transparency/{transparencyReport}/publish', [Portal\TransparencyController::class, 'publish'])
            ->middleware('staff:'.User::FLAG_ADMIN)
            ->name('transparency.publish');

        Route::get('/audit', [Portal\AuditController::class, 'index'])->name('audit.index');

        Route::get('/staff', [Portal\StaffController::class, 'index'])->name('staff.index');
        Route::patch('/staff/{user}', [Portal\StaffController::class, 'update'])
            ->middleware('staff:'.User::FLAG_USER_MANAGER)
            ->name('staff.update');
    });
});
