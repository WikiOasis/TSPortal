<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Services\MediaWiki\WikiClient;
use App\Services\MediaWiki\WikiProblem;
use App\Services\Safety\Audit;
use App\Services\Safety\SubjectResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    public function __construct(private readonly SubjectResolver $subjects) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'standing' => ['nullable', 'string', 'in:good,restricted,suspended'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = Subject::query()->withCount(['sanctions as active_sanctions_count' => fn ($q) => $q->where('active', true)]);

        if (! empty($filters['q'])) {
            $query->where('username_key', 'like', '%'.Subject::key($filters['q']).'%');
        }
        if (! empty($filters['standing'])) {
            $query->where('standing', $filters['standing']);
        }

        $page = $query->orderBy('username')->paginate($filters['per_page'] ?? 25)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Subject $s) => [
                'id' => $s->id,
                'username' => $s->username,
                'central_id' => $s->mw_central_id,
                'standing' => $s->standing,
                'banned' => $s->banned,
                'active_sanctions' => $s->active_sanctions_count,
                'unresolved' => $s->mw_central_id === null,
            ])->all(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
        ]);

        $username = Subject::normalise($data['username']);
        $key = Subject::key($username);

        $subject = Subject::query()->where('username_key', $key)->first();

        if ($subject === null) {
            try {
                $found = WikiClient::make()->lookupUser($username);
            } catch (WikiProblem $e) {
                return response()->json([
                    'error' => 'wiki-unreachable',
                    'message' => 'Could not check the wiki right now: '.$e->getMessage(),
                ], 503);
            }

            if ($found === null) {
                return response()->json([
                    'error' => 'no-such-account',
                    'message' => sprintf('There is no account named %s on the wiki.', $username),
                ], 404);
            }

            $subject = Subject::forUsername($found['username'], $found['central_id']);
            Audit::log('subject.resolved-from-wiki', $subject);
        } elseif ($subject->needsCentralId()) {
            $this->subjects->resolve($subject);
        }

        return response()->json([
            'id' => $subject->id,
            'username' => $subject->username,
            'central_id' => $subject->mw_central_id,
            'standing' => $subject->standing,
            'unresolved' => $subject->mw_central_id === null,
        ]);
    }

    public function show(Subject $subject): JsonResponse
    {
        $subject->load([
            'sanctions' => fn ($q) => $q->orderByDesc('issued_at')->with(['issuer', 'investigation']),
            'investigations' => fn ($q) => $q->orderByDesc('opened_at'),
            'dataRemovals' => fn ($q) => $q->orderByDesc('id'),
        ]);

        return response()->json([
            'id' => $subject->id,
            'username' => $subject->username,
            'central_id' => $subject->mw_central_id,
            'email' => $subject->email,
            'standing' => $subject->standing,
            'banned' => $subject->banned,
            'registered' => $subject->registered_at?->toIso8601String(),
            'notes' => $subject->notes,
            'unresolved' => $subject->mw_central_id === null,

            'sanctions' => $subject->sanctions->map(fn (Sanction $s) => [
                'id' => $s->id,
                'reference' => $s->reference,
                'type' => $s->type,
                'label' => $s->label,
                'scope' => $s->scope,
                'where' => $s->whereItApplies(),
                'wikis' => $s->wikis,
                'reason' => $s->reason,
                'internal_reason' => $s->internal_reason,
                'issued' => $s->issued_at?->toIso8601String(),
                'expires' => $s->expires_at?->toIso8601String(),
                'active' => $s->isInForce(),
                'expired' => $s->hasExpired(),
                'appealable' => $s->appealable,
                'issuer' => $s->issuer?->username,
                'lifted_at' => $s->lifted_at?->toIso8601String(),
                'lift_reason' => $s->lift_reason,
                'push_state' => $s->push_state,
                'push_error' => $s->push_error,
                'investigation' => $s->investigation?->reference,
                'investigation_id' => $s->investigation_id,
            ])->all(),

            'investigations' => $subject->investigations->map(fn (Investigation $i) => [
                'id' => $i->id,
                'reference' => $i->reference,
                'title' => $i->title,
                'status' => $i->status,
                'live' => $i->isLive(),
                'outcome' => $i->outcome,
                'role' => $i->pivot->role,
                'opened' => $i->opened_at?->toIso8601String(),
            ])->all(),

            'removals' => $subject->dataRemovals->map(fn (DataRemoval $r) => [
                'id' => $r->id,
                'reference' => $r->reference,
                'state' => $r->state,
                'state_label' => $r->label(),
                'becomes' => $r->target_username,
                'outstanding' => ! $r->isFinished(),
            ])->all(),

            'cases_filed' => SafetyCase::query()
                ->where('reporter_subject_id', $subject->id)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'reference', 'type', 'subject_line', 'status', 'created_at', 'anonymous'])
                ->map(fn (SafetyCase $c) => [
                    'reference' => $c->reference,
                    'type' => $c->type,
                    'subject' => $c->subject_line,
                    'status' => $c->status,
                    'filed' => $c->created_at?->toIso8601String(),
                    'anonymous' => $c->anonymous,
                ])->all(),

            'cases_about' => $subject->cases()
                ->orderByDesc('cases.created_at')
                ->limit(50)
                ->get(['cases.id', 'reference', 'type', 'subject_line', 'status', 'cases.created_at'])
                ->map(fn (SafetyCase $c) => [
                    'reference' => $c->reference,
                    'type' => $c->type,
                    'subject' => $c->subject_line,
                    'status' => $c->status,
                    'filed' => $c->created_at?->toIso8601String(),
                    'role' => $c->pivot->role,
                ])->all(),
        ]);
    }

    public function update(Request $request, Subject $subject): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:20000'],
        ]);

        $subject->notes = $data['notes'] ?? null;
        $subject->save();

        Audit::log('subject.notes', $subject);

        return response()->json(['ok' => true]);
    }
}
