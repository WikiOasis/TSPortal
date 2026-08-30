<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\SanctionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SanctionController extends Controller
{
    public function __construct(private readonly SanctionService $sanctions) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'active' => ['nullable', 'boolean'],
            'push_state' => ['nullable', 'string'],
            'investigation' => ['nullable', 'integer'],
            'subject' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = Sanction::query()->with(['subject', 'issuer', 'investigation']);

        if (array_key_exists('active', $filters)) {
            $query->where('active', (bool) $filters['active']);
        }
        if (! empty($filters['push_state'])) {
            $query->whereIn('push_state', explode(',', $filters['push_state']));
        }
        if (! empty($filters['investigation'])) {
            $query->where('investigation_id', (int) $filters['investigation']);
        }
        if (! empty($filters['subject'])) {
            $query->where('subject_id', (int) $filters['subject']);
        }

        $page = $query->orderByDesc('issued_at')->paginate($filters['per_page'] ?? 25)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Sanction $s) => [
                'id' => $s->id,
                'reference' => $s->reference,
                'subject' => $s->subject?->username,
                'subject_id' => $s->subject_id,
                'type' => $s->type,
                'label' => $s->label,
                'scope' => $s->scope,
                'reason' => $s->reason,
                'reason_category' => $s->reason_category,
                'reason_category_label' => $s->reasonCategoryLabel(),
                'issued' => $s->issued_at?->toIso8601String(),
                'expires' => $s->expires_at?->toIso8601String(),
                'active' => $s->isInForce(),
                'issuer' => $s->issuer?->username,
                'push_state' => $s->push_state,
                'push_error' => $s->push_error,
                'can_acknowledge' => in_array($s->push_state, Sanction::NEEDS_A_PERSON, true),
                'wikis' => $s->wikis,
                'where' => $s->whereItApplies(),
                'investigation' => $s->investigation?->reference,
                'investigation_id' => $s->investigation_id,
            ])->all(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, ?Subject $subject = null): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', Sanction::TYPES)],
            'scope' => ['nullable', 'string', 'max:255'],

            'label' => ['nullable', 'string', 'max:120', 'required_if:type,other'],

            'wikis' => ['nullable', 'array', 'max:1000'],
            'wikis.*' => ['string', 'max:64'],

            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],

            'reason' => ['required', 'string', 'min:1', 'max:5000'],
            'internal_reason' => ['nullable', 'string', 'max:20000'],

            'reason_category' => [
                'nullable',
                'string',
                'in:'.implode(',', array_keys((array) config('categories.action_reasons', []))),
            ],

            'expires_at' => ['nullable', 'date', 'after:now'],
            'appealable' => ['boolean'],
            'case_reference' => ['nullable', 'string', 'max:32'],
            'investigation_reference' => ['required', 'string', 'max:32'],
        ]);

        $subject ??= isset($data['subject_id']) ? Subject::find($data['subject_id']) : null;

        $needsAdmin = in_array($data['type'], [Sanction::TYPE_LOCK, Sanction::TYPE_WIKI_DELETION], true);

        if ($needsAdmin && ! $request->user()->hasFlag(User::FLAG_ADMIN)) {
            return response()->json([
                'error' => 'missing-flag',
                'message' => $data['type'] === Sanction::TYPE_LOCK
                    ? 'Suspending an account needs the admin flag.'
                    : 'Deleting a wiki needs the admin flag.',
            ], 403);
        }

        $investigation = Investigation::query()
            ->where('reference', $data['investigation_reference'])
            ->first();

        if ($investigation === null) {
            return response()->json([
                'error' => 'no-investigation',
                'message' => sprintf('There is no investigation numbered %s.', $data['investigation_reference']),
            ], 422);
        }

        if (! $investigation->isLive()) {
            return response()->json([
                'error' => 'investigation-closed',
                'message' => sprintf(
                    '%s is %s. Reopen it before taking an action under it.',
                    $investigation->reference,
                    $investigation->status,
                ),
            ], 422);
        }

        $case = ! empty($data['case_reference'])
            ? SafetyCase::query()->where('reference', $data['case_reference'])->first()
            : null;

        try {
            $sanction = $this->sanctions->issue($subject, $data, $request->user(), $investigation, $case);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'not-actionable', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'reference' => $sanction->reference,
            'investigation' => $investigation->reference,
            'wikis' => $sanction->wikis,
            'push_state' => $sanction->push_state,
            'push_error' => $sanction->push_error,
            'needs_manual_action' => in_array(
                $sanction->push_state,
                [Sanction::PUSH_MANUAL, Sanction::PUSH_PARTIAL, Sanction::PUSH_FAILED],
                true,
            ),
        ], 201);
    }

    public function lift(Request $request, Sanction $sanction): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        if (in_array($sanction->type, [Sanction::TYPE_LOCK, Sanction::TYPE_WIKI_DELETION], true)
            && ! $request->user()->hasFlag(User::FLAG_ADMIN)
        ) {
            return response()->json([
                'error' => 'missing-flag',
                'message' => 'Lifting this needs the admin flag.',
            ], 403);
        }

        $this->sanctions->lift($sanction, $request->user(), $data['reason']);

        return response()->json(['ok' => true, 'push_state' => $sanction->fresh()?->push_state]);
    }

    public function acknowledge(Request $request, Sanction $sanction): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->sanctions->acknowledge($sanction, $request->user(), $data['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'not-acknowledgeable', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'push_state' => $sanction->fresh()?->push_state]);
    }
}
