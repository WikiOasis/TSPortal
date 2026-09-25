<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\AutomatedReviewResource;
use App\Models\AutomatedReview;
use App\Models\SafetyCase;
use App\Services\AutoReview\AutoReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AutoReviewController extends Controller
{
    private const VIEWS = ['urgent', 'review', 'unlikely', 'waiting', 'failed', 'all'];

    public function __construct(private readonly AutoReview $autoReview) {}

    public function summary(): JsonResponse
    {
        return response()->json([
            'enabled' => $this->autoReview->enabled(),
            'model' => $this->autoReview->model(),
            'counts' => $this->autoReview->counts(),
            'labels' => AutomatedReview::BUCKET_LABELS,
        ]);
    }

    public function stats(): JsonResponse
    {
        return response()->json($this->autoReview->stats());
    }

    public function items(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'view' => ['nullable', 'string', 'in:'.implode(',', self::VIEWS)],
            'wiki' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', 'in:confidence,oldest,newest,page,editor'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
            'cursor' => ['nullable', 'integer', 'min:1'],
            'taken' => ['nullable', 'string', 'in:hide,show'],
        ]);

        $query = $this->openAutomated()
            ->with(['automatedReview.overrider', 'automatedReview.confirmer', 'categories', 'assignee']);

        if (($filters['taken'] ?? 'hide') === 'hide') {
            $me = $request->user()->id;
            $query->where(fn (Builder $q) => $q->whereNull('cases.assigned_to')->orWhere('cases.assigned_to', $me));
        }

        $this->scopeToView($query, $filters['view'] ?? 'urgent');

        foreach (['wiki' => 'wiki', 'page' => 'page_title', 'author' => 'author'] as $param => $column) {
            if (isset($filters[$param]) && $filters[$param] !== '') {
                $query->where('automated_reviews.'.$column, $filters[$param]);
            }
        }

        if (! empty($filters['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';
            $query->where(fn (Builder $q) => $q
                ->where('automated_reviews.page_title', 'like', $like)
                ->orWhere('automated_reviews.author', 'like', $like)
                ->orWhere('cases.reference', 'like', $like)
                ->orWhere('automated_reviews.reason', 'like', $like));
        }

        match ($filters['sort'] ?? 'confidence') {
            'oldest' => $query->orderBy('cases.created_at')->orderBy('cases.id'),
            'newest' => $query->orderByDesc('cases.created_at')->orderByDesc('cases.id'),
            'page' => $query->orderBy('automated_reviews.wiki')->orderBy('automated_reviews.page_title')->orderBy('cases.created_at')->orderBy('cases.id'),
            'editor' => $query->orderBy('automated_reviews.wiki')->orderBy('automated_reviews.author')->orderBy('cases.created_at')->orderBy('cases.id'),
            default => $query->orderByRaw('automated_reviews.confidence IS NULL')->orderByDesc('automated_reviews.confidence')->orderBy('cases.created_at')->orderBy('cases.id'),
        };

        $perPage = (int) ($filters['per_page'] ?? 50);
        $offset = (int) ($filters['cursor'] ?? 0);
        $total = (clone $query)->count('cases.id');

        $cases = $query->skip($offset)->take($perPage)->get();

        return response()->json([
            'data' => $cases->map(fn (SafetyCase $case) => $this->row($case, $request))->all(),
            'meta' => [
                'total' => $total,
                'next' => $offset + $perPage < $total ? $offset + $perPage : null,
            ],
        ]);
    }

    public function show(Request $request, SafetyCase $case): JsonResponse
    {
        if (! $case->automated) {
            return response()->json(['error' => 'not-automated', 'message' => "{$case->reference} was filed by a person."], 404);
        }

        $case->load(['automatedReview.overrider', 'automatedReview.confirmer', 'categories', 'assignee']);

        return response()->json(['data' => $this->row($case, $request, true)]);
    }

    public function groups(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'by' => ['nullable', 'string', 'in:page,editor'],
            'view' => ['nullable', 'string', 'in:'.implode(',', self::VIEWS)],
            'min' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $by = $filters['by'] ?? 'page';
        $column = $by === 'editor' ? 'automated_reviews.author' : 'automated_reviews.page_title';
        $other = $by === 'editor' ? 'automated_reviews.page_title' : 'automated_reviews.author';

        $query = $this->openAutomated()->whereNotNull($column);
        $this->scopeToView($query, $filters['view'] ?? 'all');

        $bucket = 'coalesce(automated_reviews.staff_bucket, automated_reviews.bucket)';

        $rows = $query->toBase()
            ->select(['automated_reviews.wiki', DB::raw($column.' as name')])
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when {$bucket} = 'urgent' then 1 else 0 end) as urgent")
            ->selectRaw("sum(case when {$bucket} = 'review' then 1 else 0 end) as review")
            ->selectRaw("sum(case when {$bucket} = 'unlikely' then 1 else 0 end) as unlikely")
            ->selectRaw("sum(case when {$bucket} is null then 1 else 0 end) as waiting")
            ->selectRaw("count(distinct {$other}) as spread")
            ->selectRaw('min(cases.created_at) as oldest')
            ->selectRaw('max(cases.created_at) as newest')
            ->groupBy('automated_reviews.wiki', $column)
            ->havingRaw('count(*) >= ?', [(int) ($filters['min'] ?? 2)])
            ->orderByDesc('total')
            ->limit(150)
            ->get();

        return response()->json([
            'by' => $by,
            'data' => $rows->map(fn ($row) => [
                'wiki' => $row->wiki,
                'name' => $row->name,
                'total' => (int) $row->total,
                'urgent' => (int) $row->urgent,
                'review' => (int) $row->review,
                'unlikely' => (int) $row->unlikely,
                'waiting' => (int) $row->waiting,
                'spread' => (int) $row->spread,
                'oldest' => $row->oldest !== null ? Carbon::parse($row->oldest)->toIso8601String() : null,
                'newest' => $row->newest !== null ? Carbon::parse($row->newest)->toIso8601String() : null,
            ])->all(),
        ]);
    }

    public function classify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'case_ids' => ['nullable', 'array', 'max:500'],
            'case_ids.*' => ['integer'],
            'failed' => ['nullable', 'boolean'],
        ]);

        if (! $this->autoReview->enabled()) {
            return response()->json([
                'error' => 'autoreview-off',
                'message' => 'Automated review is not set up. Put an OpenRouter key in OPENROUTER_API_KEY.',
            ], 409);
        }

        $queued = $this->autoReview->queueWaiting(
            failed: (bool) ($data['failed'] ?? false),
            caseIds: isset($data['case_ids']) ? array_map('intval', $data['case_ids']) : null,
        );

        return response()->json(['queued' => $queued, 'counts' => $this->autoReview->counts()]);
    }

    public function override(Request $request, SafetyCase $case): JsonResponse
    {
        $data = $request->validate([
            'bucket' => ['nullable', 'string', 'in:'.implode(',', AutomatedReview::BUCKETS)],
        ]);

        try {
            $this->autoReview->override($case, $data['bucket'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'cannot-override', 'message' => $e->getMessage()], 422);
        }

        $case->refresh()->load(['automatedReview.overrider', 'automatedReview.confirmer', 'categories', 'assignee']);

        return response()->json([
            'data' => $this->row($case, $request),
            'counts' => $this->autoReview->counts(),
        ]);
    }

    public function take(Request $request, SafetyCase $case): JsonResponse
    {
        try {
            $this->autoReview->take($case, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'cannot-take', 'message' => $e->getMessage()], 422);
        }

        $case->refresh()->load(['automatedReview.overrider', 'automatedReview.confirmer', 'categories', 'assignee']);

        return response()->json([
            'data' => $this->row($case, $request),
            'counts' => $this->autoReview->counts(),
        ]);
    }

    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'case_ids' => ['required', 'array', 'min:1', 'max:500'],
            'case_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', 'string', 'in:'.implode(',', AutoReview::CLOSE_STATUSES)],
        ]);

        $result = $this->autoReview->close(
            array_map('intval', $data['case_ids']),
            $request->user(),
            $data['note'] ?? null,
            $data['status'] ?? SafetyCase::STATUS_REJECTED,
        );

        return response()->json($result + ['counts' => $this->autoReview->counts()]);
    }

    public function merge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'case_ids' => ['required', 'array', 'min:2', 'max:500'],
            'case_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $result = $this->autoReview->merge(array_map('intval', $data['case_ids']), $request->user(), $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'cannot-merge', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'into' => ['id' => $result['into']?->id, 'reference' => $result['into']?->reference],
            'merged' => $result['merged'],
            'counts' => $this->autoReview->counts(),
        ]);
    }

    public function fold(Request $request): JsonResponse
    {
        $folded = $this->autoReview->foldSameRevision($request->user());

        return response()->json(['folded' => $folded, 'counts' => $this->autoReview->counts()]);
    }

    /**
     * @return Builder<SafetyCase>
     */
    private function openAutomated(): Builder
    {
        $this->autoReview->trackMissing();

        return SafetyCase::query()
            ->select('cases.*')
            ->join('automated_reviews', 'automated_reviews.case_id', '=', 'cases.id')
            ->where('cases.automated', true)
            ->whereIn('cases.status', SafetyCase::OPEN_STATUSES);
    }

    /**
     * @param  Builder<SafetyCase>  $query
     */
    private function scopeToView(Builder $query, string $view): void
    {
        $bucket = 'COALESCE(automated_reviews.staff_bucket, automated_reviews.bucket)';

        match ($view) {
            'urgent', 'review', 'unlikely' => $query->whereRaw("{$bucket} = ?", [$view]),
            'waiting' => $query->whereRaw("{$bucket} IS NULL")->where('automated_reviews.state', '!=', AutomatedReview::STATE_FAILED),
            'failed' => $query->whereRaw("{$bucket} IS NULL")->where('automated_reviews.state', AutomatedReview::STATE_FAILED),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SafetyCase $case, Request $request, bool $evidence = false): array
    {
        $answers = (array) ($case->answers ?? []);
        $jev = (array) ($answers['jev'] ?? []);
        $review = $case->automatedReview;

        $data = $review !== null
            ? (new AutomatedReviewResource($review))->withEvidence($evidence)->resolve($request)
            : null;

        if ($data !== null && $data['links']['revision'] === null && is_string($answers['revision_url'] ?? null)) {
            $data['links']['revision'] = $answers['revision_url'];
        }

        return [
            'id' => $case->id,
            'reference' => $case->reference,
            'subject' => $case->subject_line,
            'status' => $case->status,
            'priority' => $case->priority,
            'filed' => $case->created_at?->toIso8601String(),
            'wiki' => $case->wiki,
            'assignee' => $case->relationLoaded('assignee') && $case->assignee !== null
                ? ['id' => $case->assignee->id, 'username' => $case->assignee->username]
                : null,
            'category' => $case->categories->first()?->label,
            'edit_summary' => is_string($answers['edit_summary'] ?? null) ? $answers['edit_summary'] : null,
            'jev' => [
                'harm' => is_numeric($jev['harm'] ?? null) ? (float) $jev['harm'] : null,
                'vandalism' => is_numeric($jev['vandalism'] ?? null) ? (float) $jev['vandalism'] : null,
            ],
            'summary' => $evidence ? $case->summary : null,
            'review' => $data,
        ];
    }
}
