<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvestigationResource;
use App\Models\Investigation;
use App\Models\InvestigationNote;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\BulkActions;
use App\Services\Safety\InvestigationService;
use App\Services\Safety\Timeline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvestigationController extends Controller
{
    public function __construct(
        private readonly InvestigationService $investigations,
        private readonly Timeline $timeline,
        private readonly BulkActions $bulk,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'live' => ['nullable', 'boolean'],
            'assignee' => ['nullable'],
            'subject' => ['nullable', 'integer'],
            'due' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', 'in:oldest,newest,updated,review,priority'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = Investigation::query()
            ->with(['assignee', 'opener'])
            ->withCount(['cases', 'sanctions', 'subjects', 'notes']);

        if (! empty($filters['status'])) {
            $query->whereIn('status', explode(',', $filters['status']));
        }
        if ($filters['live'] ?? false) {
            $query->live();
        }
        if ($filters['due'] ?? false) {
            $query->dueForReview();
        }
        if (isset($filters['assignee'])) {
            $filters['assignee'] === 'none'
                ? $query->whereNull('assigned_to')
                : $query->where('assigned_to', (int) $filters['assignee']);
        }
        if (! empty($filters['subject'])) {
            $query->whereHas('subjects', fn ($q) => $q->whereKey((int) $filters['subject']));
        }
        if (! empty($filters['q'])) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($q) => $q
                ->where('reference', 'like', $term)
                ->orWhere('title', 'like', $term)
                ->orWhere('premise', 'like', $term));
        }

        $this->sort($query, $filters['sort'] ?? 'updated');

        return InvestigationResource::collection(
            $query->paginate($filters['per_page'] ?? 25)->withQueryString()
        );
    }

    public function show(Investigation $investigation): InvestigationResource
    {
        $investigation->load([
            'opener', 'closer', 'assignee', 'subjects', 'notes.author',
            'cases' => fn ($q) => $q->orderBy('created_at'),
            'sanctions' => fn ($q) => $q->orderByDesc('issued_at')->with(['subject', 'issuer']),
            'dataRemovals' => fn ($q) => $q->orderByDesc('created_at'),
        ]);

        return new InvestigationResource($investigation);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'premise' => ['nullable', 'string', 'max:20000'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'case_id' => ['nullable', 'integer', 'exists:cases,id'],
            'review_at' => ['nullable', 'date'],
            'assign_to_me' => ['nullable', 'boolean'],

            'subjects' => ['nullable', 'array', 'max:50'],
            'subjects.*.username' => ['required', 'string', 'max:255'],
            'subjects.*.central_id' => ['nullable', 'integer'],
            'subjects.*.role' => ['nullable', 'string', 'in:subject,witness,reporter,related'],
            'subjects.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        $from = isset($data['case_id']) ? SafetyCase::find($data['case_id']) : null;

        $investigation = $this->investigations->open($data, $request->user(), $from);

        return response()->json([
            'id' => $investigation->id,
            'reference' => $investigation->reference,
            'title' => $investigation->title,
        ], 201);
    }

    public function update(Request $request, Investigation $investigation): InvestigationResource
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'premise' => ['nullable', 'string', 'max:20000'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'status' => ['nullable', 'string', 'in:open,monitoring'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'review_at' => ['nullable', 'date'],
        ]);

        if (array_key_exists('assigned_to', $data)) {
            $this->investigations->assign(
                $investigation,
                $data['assigned_to'] === null ? null : User::find($data['assigned_to']),
            );
        }

        foreach (['title', 'premise', 'priority', 'review_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $investigation->{$field} = $data[$field];
            }
        }
        $investigation->save();

        if (isset($data['status'])) {
            $this->investigations->setStatus($investigation, $data['status'], $request->user());
        }

        return $this->show($investigation->refresh());
    }

    public function conclude(Request $request, Investigation $investigation): InvestigationResource
    {
        $data = $request->validate([
            'outcome' => ['nullable', 'string', 'in:'.implode(',', Investigation::OUTCOMES)],
            'findings' => ['nullable', 'string', 'max:50000'],
            'disclosable' => ['nullable', 'boolean'],
            'message' => ['nullable', 'string', 'max:20000'],
        ]);

        $this->investigations->conclude($investigation, $request->user(), $data);

        return $this->show($investigation->refresh());
    }

    public function close(Request $request, Investigation $investigation): InvestigationResource
    {
        $data = $request->validate(['why' => ['nullable', 'string', 'max:20000']]);

        $this->investigations->close($investigation, $request->user(), $data['why'] ?? null);

        return $this->show($investigation->refresh());
    }

    public function reopen(Request $request, Investigation $investigation): InvestigationResource
    {
        $this->investigations->setStatus($investigation, Investigation::STATUS_OPEN, $request->user());

        return $this->show($investigation->refresh());
    }

    public function note(Request $request, Investigation $investigation): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:50000'],
            'kind' => ['nullable', 'string', 'in:'.implode(',', InvestigationNote::KINDS)],
        ]);

        $note = $this->investigations->note(
            $investigation,
            $data['body'],
            $data['kind'] ?? InvestigationNote::KIND_NOTE,
            $request->user(),
        );

        return response()->json([
            'id' => $note->id,
            'kind' => $note->kind,
            'author' => $request->user()->username,
            'body' => $note->body,
            'date' => $note->created_at?->toIso8601String(),
        ], 201);
    }

    public function addSubject(Request $request, Investigation $investigation): InvestigationResource
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'central_id' => ['nullable', 'integer'],
            'role' => ['nullable', 'string', 'in:subject,witness,reporter,related'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->investigations->addSubject(
            $investigation,
            $data['username'],
            $data['central_id'] ?? null,
            $data['role'] ?? 'subject',
            $data['note'] ?? null,
        );

        return $this->show($investigation->refresh());
    }

    public function addSubjects(Request $request, Investigation $investigation): JsonResponse
    {
        $data = $request->validate([
            'usernames' => ['nullable', 'array', 'max:500'],
            'usernames.*' => ['string', 'max:255'],
            'text' => ['nullable', 'string', 'max:50000'],
            'role' => ['nullable', 'string', 'in:subject,witness,reporter,related'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $names = array_merge(
            array_map('strval', (array) ($data['usernames'] ?? [])),
            InvestigationService::namesIn((string) ($data['text'] ?? '')),
        );

        if ($names === []) {
            return response()->json([
                'error' => 'nothing-named',
                'message' => 'No account names were found in that.',
            ], 422);
        }

        if (count($names) > 500) {
            return response()->json([
                'error' => 'too-many',
                'message' => 'That is more than 500 names. Do it in smaller batches.',
            ], 422);
        }

        $report = $this->investigations->addSubjects(
            $investigation,
            $names,
            $data['role'] ?? 'subject',
            $data['note'] ?? null,
        );

        return response()->json([
            'added' => $report['added'],
            'skipped' => $report['skipped'],
            'data' => $this->show($investigation->refresh()),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:50000']]);

        return response()->json(['data' => InvestigationService::namesIn($data['text'])]);
    }

    public function bulkAction(Request $request, Investigation $investigation): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', BulkActions::KINDS)],

            'subject_ids' => ['required', 'array', 'min:1', 'max:200'],
            'subject_ids.*' => ['integer'],

            'reason' => ['required', 'string', 'min:1', 'max:20000'],

            'type' => ['nullable', 'string', 'in:'.implode(',', Sanction::TYPES), 'required_if:kind,action'],
            'label' => ['nullable', 'string', 'max:120', 'required_if:type,other'],
            'scope' => ['nullable', 'string', 'max:255'],
            'wikis' => ['nullable', 'array', 'max:1000'],
            'wikis.*' => ['string', 'max:64'],
            'internal_reason' => ['nullable', 'string', 'max:20000'],
            'reason_category' => [
                'nullable',
                'string',
                'in:'.implode(',', array_keys((array) config('categories.action_reasons', []))),
            ],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'appealable' => ['nullable', 'boolean'],
            'case_reference' => ['nullable', 'string', 'max:32'],

            'legal_basis' => ['nullable', 'string', 'max:32'],
            'hold' => ['nullable', 'boolean'],
        ]);

        $needsAdmin = $data['kind'] === BulkActions::KIND_ACTION
            && in_array($data['type'] ?? '', [Sanction::TYPE_LOCK, Sanction::TYPE_WIKI_DELETION], true);

        if ($needsAdmin && ! $request->user()->hasFlag(User::FLAG_ADMIN)) {
            return response()->json([
                'error' => 'missing-flag',
                'message' => 'Suspending accounts needs the admin flag.',
            ], 403);
        }

        try {
            $outcome = $this->bulk->run($investigation, $request->user(), $data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'not-actionable', 'message' => $e->getMessage()], 422);
        }

        return response()->json($outcome + ['data' => $this->show($investigation->refresh())]);
    }

    public function removeSubject(Investigation $investigation, Subject $subject): InvestigationResource
    {
        $this->investigations->removeSubject($investigation, $subject);

        return $this->show($investigation->refresh());
    }

    public function attachCase(Request $request, Investigation $investigation): InvestigationResource
    {
        $data = $request->validate(['case_id' => ['required', 'integer', 'exists:cases,id']]);

        $this->investigations->attachCase($investigation, SafetyCase::findOrFail($data['case_id']), $request->user());

        return $this->show($investigation->refresh());
    }

    public function detachCase(Request $request, Investigation $investigation, SafetyCase $case): InvestigationResource
    {
        $this->investigations->detachCase($investigation, $case, $request->user());

        return $this->show($investigation->refresh());
    }

    public function timeline(Investigation $investigation): JsonResponse
    {
        return response()->json(['data' => $this->timeline->forInvestigation($investigation)]);
    }

    /**
     * @param  Builder<Investigation>  $query
     */
    private function sort(Builder $query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->orderBy('opened_at'),
            'newest' => $query->orderByDesc('opened_at'),
            'review' => $query->orderByRaw('CASE WHEN review_at IS NULL THEN 1 ELSE 0 END')->orderBy('review_at'),
            'priority' => $query
                ->orderByRaw(self::PRIORITY_ORDER)
                ->orderBy('opened_at'),
            default => $query
                ->orderByRaw('CASE WHEN status IN (?, ?) THEN 0 ELSE 1 END', [
                    Investigation::STATUS_OPEN,
                    Investigation::STATUS_MONITORING,
                ])
                ->orderByDesc('updated_at'),
        };
    }

    private const PRIORITY_ORDER = "CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END";
}
