<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Services\Safety\DataRemovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DataRemovalController extends Controller
{
    public function __construct(private readonly DataRemovalService $removals) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'state' => ['nullable', 'string'],
            'outstanding' => ['nullable', 'boolean'],
            'subject' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = DataRemoval::query()->with(['subject', 'requester', 'approver', 'investigation', 'safetyCase']);

        if (! empty($filters['state'])) {
            $query->whereIn('state', explode(',', $filters['state']));
        }
        if ($filters['outstanding'] ?? false) {
            $query->outstanding();
        }
        if (! empty($filters['subject'])) {
            $query->where('subject_id', (int) $filters['subject']);
        }

        $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 25)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (DataRemoval $r) => $this->payload($r))->all(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
            'enabled' => (bool) config('mediawiki.pii.enabled'),
        ]);
    }

    public function show(DataRemoval $dataRemoval): JsonResponse
    {
        $dataRemoval->load(['subject', 'requester', 'approver', 'investigation', 'safetyCase']);

        return response()->json($this->payload($dataRemoval));
    }

    public function store(Request $request, Subject $subject): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:20000'],
            'legal_basis' => ['nullable', 'string', 'max:32'],
            'case_reference' => ['nullable', 'string', 'max:32'],
            'investigation_reference' => ['nullable', 'string', 'max:32'],
            'wikis' => ['nullable', 'array', 'max:200'],
            'wikis.*' => ['string', 'max:64'],

            'hold' => ['nullable', 'boolean'],
        ]);

        $existing = DataRemoval::query()
            ->where('subject_id', $subject->id)
            ->outstanding()
            ->first();

        if ($existing !== null) {
            return response()->json([
                'error' => 'already-requested',
                'message' => sprintf(
                    '%s is already being erased under %s (%s).',
                    $subject->username,
                    $existing->reference,
                    $existing->label(),
                ),
                'reference' => $existing->reference,
            ], 409);
        }

        $removal = $this->removals->request(
            $subject,
            $data,
            $request->user(),
            ! empty($data['case_reference'])
                ? SafetyCase::query()->where('reference', $data['case_reference'])->first()
                : null,
            ! empty($data['investigation_reference'])
                ? Investigation::query()->where('reference', $data['investigation_reference'])->first()
                : null,
            (bool) ($data['hold'] ?? false),
        );

        $started = $removal->state !== DataRemoval::STATE_REQUESTED;

        return response()->json([
            'id' => $removal->id,
            'reference' => $removal->reference,
            'becomes' => $removal->target_username,
            'state' => $removal->state,
            'state_label' => $removal->label(),
            'started' => $started,
            'error' => $removal->error,
            'message' => $started
                ? 'Started. The rename goes out within the minute and the erasure follows it.'
                : 'Written down and not started.',
        ], 201);
    }

    public function erase(Request $request, DataRemoval $dataRemoval): JsonResponse
    {
        try {
            $this->removals->erase($dataRemoval, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['error' => 'refused', 'message' => $e->getMessage()], 422);
        }

        $fresh = $dataRemoval->fresh();

        return response()->json([
            'ok' => true,
            'state' => $fresh?->state,
            'state_label' => $fresh?->label(),
            'error' => $fresh?->error,
        ]);
    }

    public function refuse(Request $request, DataRemoval $dataRemoval): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:1', 'max:5000']]);

        try {
            $this->removals->refuse($dataRemoval, $request->user(), $data['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['error' => 'refused', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'state' => $dataRemoval->fresh()?->state]);
    }

    public function retry(Request $request, DataRemoval $dataRemoval): JsonResponse
    {
        $result = $dataRemoval->result ?? [];
        unset($result['waited']);

        $dataRemoval->forceFill([
            'state' => DataRemoval::STATE_FAILED,
            'result' => $result,
            'error' => null,
            'last_problem' => null,
            'next_attempt_at' => null,
        ])->save();

        try {
            $this->removals->advance($dataRemoval);
        } catch (RuntimeException $e) {
            return response()->json(['error' => 'refused', 'message' => $e->getMessage()], 422);
        }

        $fresh = $dataRemoval->fresh();

        return response()->json([
            'ok' => true,
            'state' => $fresh?->state,
            'state_label' => $fresh?->label(),
            'error' => $fresh?->error,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DataRemoval $removal): array
    {
        $result = $removal->result ?? [];

        return [
            'id' => $removal->id,
            'reference' => $removal->reference,
            'account' => $removal->previous_username,
            'subject_id' => $removal->subject_id,
            'becomes' => $removal->target_username,
            'state' => $removal->state,
            'state_label' => $removal->label(),
            'legal_basis' => $removal->legal_basis,
            'reason' => $removal->reason,
            'requested_by' => $removal->requester?->username,
            'approved_by' => $removal->approver?->username,
            'approved_at' => $removal->approved_at?->toIso8601String(),
            'refusal_reason' => $removal->refusal_reason,
            'renamed_at' => $removal->renamed_at?->toIso8601String(),
            'completed_at' => $removal->completed_at?->toIso8601String(),
            'requested_at' => $removal->created_at?->toIso8601String(),
            'error' => $removal->error,

            'waiting' => $removal->isWaiting(),
            'waiting_on' => $removal->isWaiting() ? $removal->last_problem : null,
            'next_attempt_at' => $removal->next_attempt_at?->toIso8601String(),
            'case' => $removal->safetyCase?->reference,
            'case_id' => $removal->case_id,
            'investigation' => $removal->investigation?->reference,
            'investigation_id' => $removal->investigation_id,

            'wikis' => [
                'pending' => array_values((array) ($result['pending'] ?? [])),
                'finished' => array_values((array) ($result['finished'] ?? [])),
                'failures' => (array) ($result['failures'] ?? []),
            ],

            'may_erase' => $removal->state === DataRemoval::STATE_REQUESTED,
        ];
    }
}
