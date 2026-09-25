<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AutomatedReview;
use App\Models\SafetyCase;
use App\Services\AutoReview\AutoReview;
use Illuminate\Console\Command;
use Throwable;

class AutoReviewCommand extends Command
{
    protected $signature = 'tsportal:autoreview
        {--failed : Also retry reports whose classification failed}
        {--again : Classify every open automated report again, even ones already sorted}
        {--stale : Re-queue reports that were queued long ago and never came back}
        {--fold : Merge automated reports that flag the same revision before classifying}
        {--limit= : Queue at most this many}
        {--case=* : Only these case ids}
        {--now : Classify in this process instead of queueing (slow; for small batches)}';

    protected $description = 'Sort open automated reports into review buckets with the OpenRouter model';

    public function handle(AutoReview $autoReview): int
    {
        if (! $autoReview->enabled()) {
            $this->error('Automated review is off. Set OPENROUTER_API_KEY (and AUTOREVIEW_ENABLED if you turned it off).');

            return self::FAILURE;
        }

        $tracked = $autoReview->trackMissing();
        if ($tracked > 0) {
            $this->line("Started tracking {$tracked} automated report(s).");
        }

        if ($this->option('fold')) {
            $folded = $autoReview->foldSameRevision();
            $this->line("Merged {$folded} report(s) that repeated an earlier flag on the same revision.");
        }

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $caseIds = array_values(array_filter(array_map('intval', (array) $this->option('case'))));

        if ($this->option('now')) {
            return $this->classifyHere($autoReview, $limit, $caseIds);
        }

        $queued = $autoReview->queueWaiting(
            failed: (bool) $this->option('failed'),
            again: (bool) $this->option('again'),
            limit: $limit,
            caseIds: $caseIds !== [] ? $caseIds : null,
            stale: (bool) $this->option('stale'),
        );

        $this->info($queued === 0
            ? 'Nothing was waiting to be classified.'
            : sprintf('Queued %d report(s) on "%s" using %s.', $queued, config('autoreview.queue'), $autoReview->model()));

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $caseIds
     */
    private function classifyHere(AutoReview $autoReview, ?int $limit, array $caseIds): int
    {
        $query = AutomatedReview::query()
            ->whereHas('case', fn ($q) => $q->where('automated', true)->whereIn('status', SafetyCase::OPEN_STATUSES))
            ->where('state', '!=', AutomatedReview::STATE_MERGED)
            ->orderBy('case_id');

        if ($caseIds !== []) {
            $query->whereIn('case_id', $caseIds);
        } elseif (! $this->option('again')) {
            $query->whereIn('state', $this->option('failed')
                ? [AutomatedReview::STATE_PENDING, AutomatedReview::STATE_QUEUED, AutomatedReview::STATE_FAILED]
                : [AutomatedReview::STATE_PENDING, AutomatedReview::STATE_QUEUED]);
        }

        $ids = $query->limit($limit ?? 50)->pluck('case_id');

        $bar = $this->output->createProgressBar($ids->count());

        foreach ($ids as $id) {
            try {
                $review = $autoReview->classify((int) $id);
                $bar->setMessage((string) $review?->bucket);
            } catch (Throwable $e) {
                $autoReview->markFailed((int) $id, $e->getMessage());
                $this->newLine();
                $this->warn("Case {$id}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return self::SUCCESS;
    }
}
