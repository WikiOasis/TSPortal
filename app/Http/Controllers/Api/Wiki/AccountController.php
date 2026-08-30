<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Wiki;

use App\Http\Controllers\Controller;
use App\Models\CaseComment;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function reports(Request $request, string $account): JsonResponse
    {
        $subject = $this->resolve($account);

        if ($subject === null) {
            return response()->json(['reports' => [], 'hasAnonymous' => false]);
        }

        $cases = SafetyCase::query()
            ->visibleTo($subject)
            ->with(['sanction', 'publicComments' => fn ($q) => $q->orderBy('created_at')])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'reports' => $cases->map(fn (SafetyCase $c) => $this->reportPayload($c))->all(),

            'hasAnonymous' => SafetyCase::query()
                ->where('reporter_subject_id', $subject->id)
                ->where('anonymous', true)
                ->exists(),
        ]);
    }

    public function report(Request $request, string $account, string $reference): JsonResponse
    {
        $subject = $this->resolve($account);

        $case = $subject === null ? null : SafetyCase::query()
            ->visibleTo($subject)
            ->where('reference', $reference)
            ->with(['sanction', 'publicComments' => fn ($q) => $q->orderBy('created_at')])
            ->first();

        if ($case === null) {
            return response()->json(['error' => 'not-found'], 404);
        }

        return response()->json($this->reportPayload($case));
    }

    public function standing(Request $request, string $account): JsonResponse
    {
        $subject = $this->resolve($account);

        if ($subject === null) {
            return response()->json([
                'standing' => Subject::STANDING_GOOD,
                'banned' => false,
                'registered' => null,
                'actions' => [],
            ]);
        }

        $actions = $subject->sanctions()
            ->whereNotIn('type', [Sanction::TYPE_NOTE])
            ->orderByDesc('issued_at')
            ->get();

        return response()->json([
            'standing' => $subject->standing,
            'banned' => $subject->banned,
            'registered' => $subject->registered_at?->toIso8601String(),
            'actions' => $actions->map(fn (Sanction $s) => [
                'id' => $s->reference,
                'type' => $s->label,
                'scope' => $s->scope,
                'issued' => $s->issued_at?->toIso8601String(),
                'expires' => $s->expires_at?->toIso8601String(),
                'active' => $s->isInForce(),
                'reason' => $s->reason,
                'appealable' => $s->appealable && $s->isInForce(),
            ])->all(),
        ]);
    }

    public function loginStatus(Request $request, string $account): JsonResponse
    {
        $subject = $this->resolve($account);

        if ($subject === null || ! $subject->banned) {
            return response()->json(['banned' => false]);
        }

        $lock = $subject->sanctions()
            ->where('type', Sanction::TYPE_LOCK)
            ->where('active', true)
            ->orderByDesc('issued_at')
            ->first();

        return response()->json([
            'banned' => true,
            'standing' => $subject->standing,
            'reference' => $lock?->reference,
            'reason' => $lock?->reason,
            'issued' => $lock?->issued_at?->toIso8601String(),
            'expires' => $lock?->expires_at?->toIso8601String(),
            'appealable' => (bool) $lock?->appealable,
            'appeal_pending' => $lock !== null && SafetyCase::query()
                ->where('type', SafetyCase::TYPE_APPEAL)
                ->where('sanction_id', $lock->id)
                ->whereIn('status', SafetyCase::OPEN_STATUSES)
                ->exists(),
        ]);
    }

    private function resolve(string $account): ?Subject
    {
        if (ctype_digit($account)) {
            return Subject::query()->where('mw_central_id', (int) $account)->first();
        }

        return Subject::query()->where('username_key', Subject::key(rawurldecode($account)))->first();
    }

    /** @return array<string, mixed> */
    private function reportPayload(SafetyCase $case): array
    {
        return [
            'id' => $case->reference,
            'type' => $case->type,
            'subject' => $case->subject_line,
            'filed' => $case->created_at?->toIso8601String(),
            'updated' => $case->updated_at?->toIso8601String(),
            'status' => $case->status,
            'anonymous' => false,
            'about' => $case->about ?? [],
            'summary' => $case->summary,

            'appeal' => $case->isAppeal() ? [
                'action' => $case->sanction === null ? null : [
                    'reference' => $case->sanction->reference,
                    'label' => $case->sanction->label,
                    'type' => $case->sanction->type,
                    'reason' => $case->sanction->reason,
                    'issued' => $case->sanction->issued_at?->toIso8601String(),
                    'expires' => $case->sanction->expires_at?->toIso8601String(),
                    'in_force' => $case->sanction->isInForce(),
                    'lifted' => $case->sanction->lifted_at?->toIso8601String(),
                    'where' => $case->sanction->whereItApplies(),
                ],
                'outcome' => $case->appeal_outcome,
                'decided' => $case->appeal_decided_at?->toIso8601String(),
            ] : null,

            'comments' => $case->publicComments->map(fn (CaseComment $c) => [
                'author' => $c->author_type === CaseComment::AUTHOR_SUBJECT ? 'you' : 'staff',
                'name' => $c->author_type === CaseComment::AUTHOR_SUBJECT ? null : $c->author_label,
                'date' => $c->created_at?->toIso8601String(),
                'text' => $c->body,
            ])->values()->all(),
        ];
    }
}
