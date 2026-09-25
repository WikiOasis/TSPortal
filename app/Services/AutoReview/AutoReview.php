<?php

declare(strict_types=1);

namespace App\Services\AutoReview;

use App\Jobs\ClassifyAutomatedCase;
use App\Models\AutomatedReview;
use App\Models\SafetyCase;
use App\Models\User;
use App\Services\Safety\Audit;
use App\Services\Safety\CaseService;
use App\Services\Safety\DuplicateReports;
use App\Services\Slack\SlackNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class AutoReview
{
    public const CLOSE_STATUSES = [SafetyCase::STATUS_REJECTED, SafetyCase::STATUS_CLOSED];

    public function __construct(
        private readonly WikiContent $content,
        private readonly Classifier $classifier,
        private readonly CaseService $cases,
        private readonly DuplicateReports $duplicates,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('autoreview.enabled', false)
            && (string) config('autoreview.openrouter.key', '') !== '';
    }

    public function model(): string
    {
        return (string) config('autoreview.openrouter.model');
    }

    public function received(SafetyCase $case): void
    {
        if (! $case->automated) {
            return;
        }

        try {
            $review = $this->track($case);

            if ($this->foldIntoEarlier($review, $case)) {
                return;
            }

            if ($this->enabled()) {
                $this->queue(collect([$review]));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function track(SafetyCase $case): AutomatedReview
    {
        if (! $case->automated) {
            throw new InvalidArgumentException("{$case->reference} was filed by a person, not by automated scanning.");
        }

        $existing = AutomatedReview::query()->where('case_id', $case->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $answers = (array) ($case->answers ?? []);
        $revision = $answers['revision'] ?? null;

        return AutomatedReview::query()->firstOrCreate(['case_id' => $case->id], [
            'state' => AutomatedReview::STATE_PENDING,
            'wiki' => $this->limit($this->first($answers['wiki'] ?? null) ?? $case->wiki, 64),
            'page_title' => $this->limit($this->first($answers['page'] ?? null), 255),
            'author' => $this->limit($this->first($answers['user'] ?? null), 255),
            'revision_id' => is_numeric($revision) ? (int) $revision : null,
            'scan_mode' => $this->first($answers['scan_mode'] ?? null),
        ]);
    }

    /**
     * @param  Collection<int, AutomatedReview>  $reviews
     */
    public function queue(Collection $reviews): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $automated = SafetyCase::query()
            ->whereKey($reviews->pluck('case_id')->all())
            ->where('automated', true)
            ->pluck('id')
            ->all();

        $reviews = $reviews->filter(fn (AutomatedReview $review) => in_array($review->case_id, $automated, true));

        $ids = $reviews->pluck('id')->all();

        AutomatedReview::query()->whereKey($ids)->update([
            'state' => AutomatedReview::STATE_QUEUED,
            'queued_at' => now(),
        ]);

        foreach ($reviews as $review) {
            ClassifyAutomatedCase::dispatch($review->case_id)->onQueue((string) config('autoreview.queue', 'default'));
        }

        return count($ids);
    }

    /**
     * @param  list<int>|null  $caseIds
     */
    public function queueWaiting(bool $failed = false, bool $again = false, ?int $limit = null, ?array $caseIds = null, bool $stale = false): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $this->trackMissing();

        $query = AutomatedReview::query()
            ->whereHas('case', fn (Builder $q) => $q->where('automated', true)->whereIn('status', SafetyCase::OPEN_STATUSES))
            ->where('state', '!=', AutomatedReview::STATE_MERGED)
            ->orderBy('case_id');

        if ($caseIds !== null) {
            $query->whereIn('case_id', $caseIds);
        } elseif (! $again) {
            $states = [AutomatedReview::STATE_PENDING];
            if ($failed) {
                $states[] = AutomatedReview::STATE_FAILED;
            }

            $query->where(function (Builder $q) use ($states, $stale) {
                $q->whereIn('state', $states);

                if ($stale) {
                    $q->orWhere(fn (Builder $w) => $w
                        ->where('state', AutomatedReview::STATE_QUEUED)
                        ->where('queued_at', '<', now()->subHours(max(1, (int) config('autoreview.retry_for_hours', 12)) + 1)));
                }
            });
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $count = 0;
        $query->get()->chunk(200)->each(function (Collection $chunk) use (&$count) {
            $count += $this->queue($chunk);
        });

        return $count;
    }

    public function trackMissing(): int
    {
        $count = 0;

        SafetyCase::query()
            ->where('automated', true)
            ->whereDoesntHave('automatedReview')
            ->orderBy('id')
            ->chunkById(500, function ($cases) use (&$count) {
                foreach ($cases as $case) {
                    $this->track($case);
                    $count++;
                }
            });

        return $count;
    }

    public function classify(int $caseId): ?AutomatedReview
    {
        $case = SafetyCase::query()->with('categories')->find($caseId);

        if ($case === null || ! $case->automated) {
            AutomatedReview::query()->where('case_id', $caseId)->delete();

            return null;
        }

        $review = $this->track($case);

        if ($review->state === AutomatedReview::STATE_MERGED) {
            return $review;
        }

        $review->increment('attempts');

        $evidence = $this->content->gather($review, $case);

        $review->forceFill([
            'evidence' => $evidence,
            'evidence_source' => $evidence['source'] ?? 'metadata',
        ])->save();

        try {
            $verdict = $this->classifier->classify($review, $case, $evidence);
        } catch (ClassificationFailed $e) {
            $review->forceFill(['error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            if (! $e->retry) {
                $this->markFailed($caseId, $e->getMessage());

                return $review->refresh();
            }

            throw $e;
        }

        $review->forceFill([
            'state' => AutomatedReview::STATE_DONE,
            'bucket' => $verdict['bucket'],
            'confidence' => $verdict['confidence'],
            'page_summary' => $verdict['page_summary'],
            'change_summary' => $verdict['change_summary'],
            'reason' => $verdict['reason'],
            'signals' => $verdict['signals'],
            'suggested_action' => $verdict['suggested_action'],
            'model' => $verdict['model'],
            'tokens' => $verdict['tokens'],
            'cost' => $verdict['cost'],
            'error' => null,
            'classified_at' => now(),
        ])->save();

        $announce = $review->effectiveBucket() === AutomatedReview::BUCKET_URGENT && $case->isOpen();

        $record = function () use ($review, $case) {
            Audit::log('autoreview.classified', $case, [
                'bucket' => $review->bucket,
                'confidence' => $review->confidence,
                'model' => $review->model,
                'source' => $review->evidence_source,
            ], actorLabel: 'automated triage');

            $this->applyPriority($review, $case);
        };

        $announce ? $record() : SlackNotifier::quietly($record);

        return $review->refresh();
    }

    public function markFailed(int $caseId, string $message): void
    {
        AutomatedReview::query()->where('case_id', $caseId)->update([
            'state' => AutomatedReview::STATE_FAILED,
            'error' => mb_substr($message, 0, 1000),
            'updated_at' => now(),
        ]);
    }

    public function override(SafetyCase $case, ?string $bucket, User $actor): AutomatedReview
    {
        if ($bucket !== null && ! in_array($bucket, AutomatedReview::BUCKETS, true)) {
            throw new InvalidArgumentException('There is no such bucket.');
        }

        $review = $this->track($case);
        $before = $review->effectiveBucket();

        $review->forceFill([
            'staff_bucket' => $bucket === $review->bucket ? null : $bucket,
            'overridden_by' => $bucket === $review->bucket ? null : $actor->id,
            'overridden_at' => $bucket === $review->bucket ? null : now(),
        ])->save();

        SlackNotifier::quietly(function () use ($review, $case, $before, $actor) {
            Audit::log('autoreview.overridden', $case, [
                'from' => $before,
                'to' => $review->effectiveBucket(),
                'model' => $review->bucket,
                'by' => $actor->username,
            ]);

            $this->applyPriority($review, $case);
        });

        return $review->refresh();
    }

    public function take(SafetyCase $case, User $actor): AutomatedReview
    {
        $review = $this->track($case);

        if (! $case->isOpen()) {
            throw new InvalidArgumentException("{$case->reference} is already closed.");
        }

        if ($case->assigned_to !== null && $case->assigned_to !== $actor->id) {
            $case->loadMissing('assignee');

            throw new InvalidArgumentException(sprintf(
                '%s has already been taken by %s.',
                $case->reference,
                $case->assignee?->username ?? 'someone else',
            ));
        }

        SlackNotifier::quietly(function () use ($case, $actor) {
            $this->cases->assign($case, $actor);

            if ($case->status === SafetyCase::STATUS_RECEIVED) {
                $this->cases->setStatus($case, SafetyCase::STATUS_IN_REVIEW, $actor);
            }
        });

        $review->forceFill([
            'confirmed_by' => $actor->id,
            'confirmed_at' => now(),
        ])->save();

        return $review->refresh();
    }

    /**
     * @param  list<int>  $caseIds
     * @return array{closed: list<int>, skipped: list<int>}
     */
    public function close(array $caseIds, User $actor, ?string $note = null, string $status = SafetyCase::STATUS_REJECTED): array
    {
        if (! in_array($status, self::CLOSE_STATUSES, true)) {
            throw new InvalidArgumentException('Automated reports can only be closed here.');
        }

        $note = trim((string) $note) !== ''
            ? trim((string) $note)
            : 'Checked with automated triage; no action needed.';

        $cases = SafetyCase::query()
            ->with('automatedReview')
            ->whereKey(array_values(array_unique($caseIds)))
            ->where('automated', true)
            ->whereIn('status', SafetyCase::OPEN_STATUSES)
            ->get();

        $closed = [];
        $buckets = [];

        SlackNotifier::quietly(function () use ($cases, $status, $actor, $note, &$closed, &$buckets) {
            foreach ($cases as $case) {
                try {
                    $this->cases->setStatus($case, $status, $actor, $note);
                    $closed[] = $case->id;

                    $bucket = $case->automatedReview?->effectiveBucket() ?? 'unclassified';
                    $buckets[$bucket] = ($buckets[$bucket] ?? 0) + 1;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });

        if ($closed !== []) {
            Audit::log('autoreview.batch-closed', null, [
                'count' => count($closed),
                'status' => $status,
                'buckets' => $buckets,
                'note' => $note,
                'references' => $cases->whereIn('id', $closed)->pluck('reference')->take(200)->values()->all(),
            ]);
        }

        return [
            'closed' => $closed,
            'skipped' => array_values(array_diff(array_map('intval', $caseIds), $closed)),
        ];
    }

    /**
     * @param  list<int>  $caseIds
     * @return array{into: ?SafetyCase, merged: int}
     */
    public function merge(array $caseIds, User $actor, ?string $note = null): array
    {
        $cases = SafetyCase::query()
            ->whereKey(array_values(array_unique($caseIds)))
            ->where('automated', true)
            ->where('status', '!=', SafetyCase::STATUS_DUPLICATE)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($cases->count() < 2) {
            throw new InvalidArgumentException('Pick at least two automated reports to merge.');
        }

        $into = $cases->first();
        $merged = 0;

        SlackNotifier::quietly(function () use ($cases, $into, $actor, $note, &$merged) {
            foreach ($cases->slice(1) as $case) {
                if (! $case->isOpen()) {
                    continue;
                }

                try {
                    $this->duplicates->merge($case, $into, $actor, $note ?? 'Merged from automated review.');
                    AutomatedReview::query()->where('case_id', $case->id)->update(['state' => AutomatedReview::STATE_MERGED]);
                    $merged++;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });

        if ($merged > 0) {
            Audit::log('autoreview.merged', $into, ['count' => $merged, 'by' => $actor->username]);
        }

        return ['into' => $into, 'merged' => $merged];
    }

    public function foldSameRevision(?User $actor = null): int
    {
        $this->trackMissing();

        $groups = AutomatedReview::query()
            ->whereNotNull('revision_id')
            ->whereNotNull('wiki')
            ->select(['wiki', 'revision_id'])
            ->groupBy('wiki', 'revision_id')
            ->havingRaw('count(*) > 1')
            ->get();

        $folded = 0;

        SlackNotifier::quietly(function () use ($groups, $actor, &$folded) {
            foreach ($groups as $group) {
                $reviews = AutomatedReview::query()
                    ->with('case')
                    ->where('wiki', $group->wiki)
                    ->where('revision_id', $group->revision_id)
                    ->orderBy('case_id')
                    ->get()
                    ->filter(fn (AutomatedReview $r) => $r->case !== null);

                $canonical = $reviews->first(fn (AutomatedReview $r) => ! $r->case->isDuplicate());

                if ($canonical === null) {
                    continue;
                }

                foreach ($reviews as $review) {
                    if ($review->id === $canonical->id || $review->case->isDuplicate() || ! $review->case->isOpen()) {
                        continue;
                    }

                    try {
                        $this->duplicates->merge($review->case, $canonical->case, $actor, 'The same revision was flagged more than once.');
                        $review->forceFill(['state' => AutomatedReview::STATE_MERGED])->save();
                        $folded++;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            }
        });

        return $folded;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $row = DB::table('cases')
            ->leftJoin('automated_reviews', 'automated_reviews.case_id', '=', 'cases.id')
            ->where('cases.automated', true)
            ->whereIn('cases.status', SafetyCase::OPEN_STATUSES)
            ->selectRaw('count(*) as open_total')
            ->selectRaw("sum(case when coalesce(automated_reviews.staff_bucket, automated_reviews.bucket) = 'urgent' then 1 else 0 end) as urgent")
            ->selectRaw("sum(case when coalesce(automated_reviews.staff_bucket, automated_reviews.bucket) = 'review' then 1 else 0 end) as review")
            ->selectRaw("sum(case when coalesce(automated_reviews.staff_bucket, automated_reviews.bucket) = 'unlikely' then 1 else 0 end) as unlikely")
            ->selectRaw("sum(case when coalesce(automated_reviews.staff_bucket, automated_reviews.bucket) is null and automated_reviews.state = 'failed' then 1 else 0 end) as failed")
            ->selectRaw("sum(case when coalesce(automated_reviews.staff_bucket, automated_reviews.bucket) is null and automated_reviews.state = 'queued' then 1 else 0 end) as queued")
            ->first();

        $counts = [
            'open' => (int) ($row->open_total ?? 0),
            'urgent' => (int) ($row->urgent ?? 0),
            'review' => (int) ($row->review ?? 0),
            'unlikely' => (int) ($row->unlikely ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'queued' => (int) ($row->queued ?? 0),
        ];

        $counts['waiting'] = max(0, $counts['open'] - $counts['urgent'] - $counts['review'] - $counts['unlikely'] - $counts['failed']);

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $rows = DB::table('automated_reviews')
            ->join('cases', 'cases.id', '=', 'automated_reviews.case_id')
            ->where('automated_reviews.state', AutomatedReview::STATE_DONE)
            ->whereNotNull('automated_reviews.bucket')
            ->groupBy('automated_reviews.bucket')
            ->select('automated_reviews.bucket')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when automated_reviews.staff_bucket is not null and automated_reviews.staff_bucket <> automated_reviews.bucket then 1 else 0 end) as moved')
            ->selectRaw("sum(case when automated_reviews.staff_bucket = 'urgent' then 1 else 0 end) as to_urgent")
            ->selectRaw("sum(case when automated_reviews.staff_bucket = 'review' then 1 else 0 end) as to_review")
            ->selectRaw("sum(case when automated_reviews.staff_bucket = 'unlikely' then 1 else 0 end) as to_unlikely")
            ->selectRaw("sum(case when cases.status in ('rejected', 'closed') then 1 else 0 end) as no_action")
            ->selectRaw("sum(case when cases.status in ('action-taken', 'investigating') then 1 else 0 end) as acted")
            ->selectRaw("sum(case when cases.status = 'duplicate' then 1 else 0 end) as merged")
            ->selectRaw("sum(case when cases.status in ('received', 'in-review') then 1 else 0 end) as still_open")
            ->selectRaw('sum(case when automated_reviews.confirmed_at is not null then 1 else 0 end) as taken')
            ->selectRaw("sum(case when automated_reviews.confirmed_at is not null or cases.status in ('action-taken', 'investigating') then 1 else 0 end) as engaged")
            ->get()
            ->keyBy('bucket');

        $totals = DB::table('automated_reviews')
            ->where('state', AutomatedReview::STATE_DONE)
            ->selectRaw('count(*) as classified, sum(tokens) as tokens, sum(cost) as cost, max(classified_at) as last')
            ->first();

        return [
            'buckets' => array_map(fn (string $bucket) => [
                'bucket' => $bucket,
                'label' => AutomatedReview::BUCKET_LABELS[$bucket],
                'total' => (int) ($rows[$bucket]->total ?? 0),
                'moved' => (int) ($rows[$bucket]->moved ?? 0),
                'moved_to' => [
                    'urgent' => (int) ($rows[$bucket]->to_urgent ?? 0),
                    'review' => (int) ($rows[$bucket]->to_review ?? 0),
                    'unlikely' => (int) ($rows[$bucket]->to_unlikely ?? 0),
                ],
                'no_action' => (int) ($rows[$bucket]->no_action ?? 0),
                'acted' => (int) ($rows[$bucket]->acted ?? 0),
                'merged' => (int) ($rows[$bucket]->merged ?? 0),
                'open' => (int) ($rows[$bucket]->still_open ?? 0),
                'taken' => (int) ($rows[$bucket]->taken ?? 0),
                'agreed' => $bucket === AutomatedReview::BUCKET_UNLIKELY
                    ? (int) ($rows[$bucket]->no_action ?? 0)
                    : (int) ($rows[$bucket]->engaged ?? 0),
            ], AutomatedReview::BUCKETS),
            'classified' => (int) ($totals->classified ?? 0),
            'tokens' => (int) ($totals->tokens ?? 0),
            'cost' => round((float) ($totals->cost ?? 0), 4),
            'last_classified' => $totals->last ?? null,
        ];
    }

    private function applyPriority(AutomatedReview $review, SafetyCase $case): void
    {
        $bucket = $review->effectiveBucket();

        if ($bucket === null || ! (bool) config('autoreview.set_priority', true) || ! $case->isOpen() || $case->isThreatToLife()) {
            return;
        }

        $expected = $review->priority_applied ?? SafetyCase::PRIORITY_HIGH;

        if ($case->priority !== $expected) {
            return;
        }

        $target = AutomatedReview::priorityFor($bucket);

        if ($target !== $case->priority) {
            $this->cases->setPriority($case, $target);
        }

        $review->forceFill(['priority_applied' => $target])->save();
    }

    private function foldIntoEarlier(AutomatedReview $review, SafetyCase $case): bool
    {
        if (! (bool) config('autoreview.fold_same_revision', true) || $review->revision_id === null || $review->wiki === null) {
            return false;
        }

        $earlier = AutomatedReview::query()
            ->with('case')
            ->where('wiki', $review->wiki)
            ->where('revision_id', $review->revision_id)
            ->where('case_id', '<', $case->id)
            ->whereHas('case', fn (Builder $q) => $q->where('status', '!=', SafetyCase::STATUS_DUPLICATE))
            ->orderBy('case_id')
            ->first();

        if ($earlier?->case === null) {
            return false;
        }

        try {
            SlackNotifier::quietly(fn () => $this->duplicates->merge(
                $case,
                $earlier->case,
                null,
                'The same revision was flagged again.',
            ));
        } catch (Throwable $e) {
            Log::warning('Could not fold a repeated automated flag', ['case' => $case->reference, 'error' => $e->getMessage()]);

            return false;
        }

        $review->forceFill(['state' => AutomatedReview::STATE_MERGED])->save();

        return true;
    }

    private function first(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function limit(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
