<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\CaseResource;
use App\Models\SafetyCase;
use App\Models\User;
use App\Services\Safety\CaseSearch;
use App\Services\Safety\CaseService;
use App\Services\Safety\DuplicateReports;
use App\Services\Safety\Timeline;
use App\Services\Safety\Triage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CaseController extends Controller
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly CaseSearch $search,
        private readonly DuplicateReports $duplicates,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'assignee' => ['nullable'],
            'q' => ['nullable', 'string', 'max:200'],
            'open' => ['nullable', 'boolean'],
            'investigation' => ['nullable'],
            'priority' => ['nullable', 'string', 'in:'.implode(',', SafetyCase::PRIORITIES)],
            'category' => ['nullable', 'string', 'max:400'],

            'threat' => ['nullable', 'boolean'],
            'source' => ['nullable', 'string', 'in:people,automated'],
            'bucket' => ['nullable', 'string', 'in:urgent,review,unlikely,waiting'],

            'sort' => ['nullable', 'string', 'in:oldest,newest,updated,priority,status,reference'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = SafetyCase::query()->with(['reporter', 'assignee', 'investigation', 'categories', 'automatedReview', 'subjects']);

        if (! empty($filters['type'])) {
            $kinds = array_values(array_intersect(
                array_map('trim', explode(',', $filters['type'])),
                SafetyCase::TYPES,
            ));

            $query->whereIn('type', $kinds ?: ['']);
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->whereIn('status', explode(',', $filters['status']));
        }
        if (($filters['open'] ?? false)) {
            $query->whereIn('status', SafetyCase::OPEN_STATUSES);
        }
        if (isset($filters['assignee'])) {
            $filters['assignee'] === 'none'
                ? $query->whereNull('assigned_to')
                : $query->where('assigned_to', (int) $filters['assignee']);
        }
        if (isset($filters['investigation'])) {
            $filters['investigation'] === 'none'
                ? $query->whereNull('investigation_id')
                : $query->where('investigation_id', (int) $filters['investigation']);
        }
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (($filters['threat'] ?? false)) {
            $query->threatToLife();
        }
        if (! empty($filters['source'])) {
            $query->where('automated', $filters['source'] === 'automated');
        }
        if (! empty($filters['bucket'])) {
            $query->where('automated', true);

            $filters['bucket'] === 'waiting'
                ? $query->whereDoesntHave('automatedReview', fn (Builder $q) => $q->whereRaw('COALESCE(staff_bucket, bucket) IS NOT NULL'))
                : $query->whereHas('automatedReview', fn (Builder $q) => $q->inBucket($filters['bucket']));
        }
        if (! empty($filters['category'])) {
            $wanted = array_values(array_filter(array_map('trim', explode(',', $filters['category']))));

            $filters['category'] === 'none'
                ? $query->whereNull('category')
                : $query->whereHas('categories', fn (Builder $q) => $q->whereIn('category', $wanted));
        }
        if (! empty($filters['q'])) {
            $this->search->apply($query, $filters['q'], $request->user());
        }

        $this->sort($query, $filters['sort'] ?? 'oldest');

        $cases = $query->paginate($filters['per_page'] ?? 25)->withQueryString();

        return CaseResource::collection($cases);
    }

    public function show(SafetyCase $case): CaseResource
    {
        $case->load([
            'reporter',
            'assignee',
            'decider',
            'investigation',
            'duplicateOf',
            'duplicateMarker',
            'duplicates' => fn ($q) => $q->orderBy('created_at'),
            'categories',
            'automatedReview.overrider',
            'subjects',
            'attachments',
            'sanctionsIssued',
            'comments' => fn ($q) => $q->orderBy('created_at'),
        ]);

        if ($case->isAppeal()) {
            $case->load([
                'sanction',
                'appealDecider',
                'reporter.sanctions' => fn ($q) => $q->orderByDesc('issued_at')->limit(25),
            ]);
        }

        return new CaseResource($case);
    }

    public function markDuplicate(Request $request, SafetyCase $case): JsonResponse
    {
        $data = $request->validate([
            'of_id' => ['nullable', 'integer', 'exists:cases,id'],
            'of' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $canonical = isset($data['of_id'])
            ? SafetyCase::find($data['of_id'])
            : (! empty($data['of'])
                ? SafetyCase::query()->where('reference', trim($data['of']))->first()
                : null);

        if ($canonical === null) {
            return response()->json([
                'error' => 'no-such-report',
                'message' => empty($data['of'])
                    ? 'Say which report this duplicates.'
                    : sprintf('There is no report numbered %s.', trim((string) $data['of'])),
            ], 422);
        }

        try {
            $this->duplicates->merge($case, $canonical, $request->user(), $data['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'not-a-duplicate', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'of' => $canonical->reference,
            'of_id' => $canonical->id,
            'case' => $this->show($case->refresh()),
        ]);
    }

    public function undoDuplicate(Request $request, SafetyCase $case): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', SafetyCase::OPEN_STATUSES)],
        ]);

        try {
            $this->duplicates->unmerge($case, $request->user(), $data['status'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'not-a-duplicate', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'case' => $this->show($case->refresh()),
        ]);
    }

    public function duplicateCandidates(SafetyCase $case): JsonResponse
    {
        return response()->json([
            'data' => $this->duplicates->candidatesFor($case)->map(fn (SafetyCase $other) => [
                'id' => $other->id,
                'reference' => $other->reference,
                'subject' => $other->subject_line,
                'status' => $other->status,
                'type' => $other->type,
                'filed' => $other->created_at?->toIso8601String(),
                'anonymous' => $other->anonymous,
                'assignee' => $other->assignee?->username,
                'accounts' => $other->subjects->pluck('username')->all(),
                'because' => $other->getAttribute('duplicate_because'),
            ])->all(),
        ]);
    }

    public function timeline(SafetyCase $case, Timeline $timeline): JsonResponse
    {
        return response()->json(['data' => $timeline->forCase($case)]);
    }

    public function categorise(Request $request, SafetyCase $case): CaseResource
    {
        $data = $request->validate([
            'categories' => ['present', 'array', 'max:20'],
            'categories.*.id' => ['required', 'string', 'max:64'],
            'categories.*.label' => ['nullable', 'string', 'max:250'],
            'categories.*.group' => ['nullable', 'string', 'max:64'],
        ]);

        $this->cases->categorise($case, $data['categories'], $request->user());

        return new CaseResource($case->load(['categories', 'reporter', 'assignee', 'investigation']));
    }

    public function update(Request $request, SafetyCase $case): CaseResource
    {
        $data = $request->validate([
            'status' => [
                'nullable',
                'string',
                'in:'.implode(',', array_diff(SafetyCase::STATUSES, [SafetyCase::STATUS_DUPLICATE])),
            ],
            'priority' => ['nullable', 'string', 'in:'.implode(',', SafetyCase::PRIORITIES)],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'resolution' => ['nullable', 'string', 'max:5000'],
        ]);

        if (array_key_exists('assigned_to', $data)) {
            $this->cases->assign($case, $data['assigned_to'] === null ? null : User::find($data['assigned_to']));
        }

        if (isset($data['priority'])) {
            $this->cases->setPriority($case, $data['priority']);
        }

        if (isset($data['status'])) {
            $this->cases->setStatus($case, $data['status'], $request->user(), $data['resolution'] ?? null);
        }

        return $this->show($case->refresh());
    }

    public function claim(Request $request, SafetyCase $case): CaseResource
    {
        $this->cases->assign($case, $request->user());

        if ($case->status === SafetyCase::STATUS_RECEIVED) {
            $this->cases->setStatus($case, SafetyCase::STATUS_IN_REVIEW, $request->user());
        }

        return $this->show($case->refresh());
    }

    /**
     * @param  Builder<SafetyCase>  $query
     */
    private function sort(Builder $query, string $sort): void
    {
        $query->orderByRaw('CASE WHEN status IN (?, ?, ?) THEN 0 ELSE 1 END', [
            SafetyCase::STATUS_RECEIVED,
            SafetyCase::STATUS_IN_REVIEW,
            SafetyCase::STATUS_INVESTIGATING,
        ]);

        $query->orderByRaw('CASE WHEN priority = ? AND status IN (?, ?, ?) THEN 0 ELSE 1 END', [
            SafetyCase::PRIORITY_URGENT,
            SafetyCase::STATUS_RECEIVED,
            SafetyCase::STATUS_IN_REVIEW,
            SafetyCase::STATUS_INVESTIGATING,
        ]);

        $query->orderByRaw('CASE WHEN automated = ? AND status IN (?, ?, ?) THEN 1 + COALESCE(('
            .'SELECT CASE COALESCE(automated_reviews.staff_bucket, automated_reviews.bucket) '
            ."WHEN 'urgent' THEN 0 WHEN 'review' THEN 1 WHEN 'unlikely' THEN 3 END "
            .'FROM automated_reviews WHERE automated_reviews.case_id = cases.id'
            .'), 2) ELSE 0 END', [
                true,
                SafetyCase::STATUS_RECEIVED,
                SafetyCase::STATUS_IN_REVIEW,
                SafetyCase::STATUS_INVESTIGATING,
            ]);

        match ($sort) {
            'newest' => $query->orderByDesc('created_at'),
            'updated' => $query->orderByDesc('updated_at'),
            'reference' => $query->orderByDesc('reference'),
            'priority' => $query
                ->orderByRaw(Triage::orderByPrioritySql())
                ->orderBy('created_at'),
            'status' => $query
                ->orderByRaw(self::STATUS_ORDER)
                ->orderBy('created_at'),
            default => $query->orderBy('created_at'),
        };
    }

    private const STATUS_ORDER = "CASE status WHEN 'received' THEN 0 WHEN 'in-review' THEN 1 "
        ."WHEN 'investigating' THEN 2 WHEN 'action-taken' THEN 3 WHEN 'closed' THEN 4 ELSE 5 END";

    public function comment(Request $request, SafetyCase $case): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:20000'],
            'visibility' => ['required', 'string', 'in:public,internal'],
        ]);

        $comment = $this->cases->comment(
            $case,
            $data['body'],
            $data['visibility'],
            staff: $request->user(),
        );

        return response()->json([
            'id' => $comment->id,
            'author' => $comment->author_label,
            'author_type' => $comment->author_type,
            'body' => $comment->body,
            'visibility' => $comment->visibility,
            'date' => $comment->created_at?->toIso8601String(),
        ], 201);
    }
}
