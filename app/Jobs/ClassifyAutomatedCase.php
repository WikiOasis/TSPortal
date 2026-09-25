<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AutoReview\AutoReview;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class ClassifyAutomatedCase implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $maxExceptions = 4;

    public int $timeout = 180;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $caseId) {}

    public function uniqueId(): string
    {
        return (string) $this->caseId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RateLimited('autoreview')];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(max(1, (int) config('autoreview.retry_for_hours', 12)));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(AutoReview $autoReview): void
    {
        $autoReview->classify($this->caseId);
    }

    public function failed(?Throwable $e): void
    {
        app(AutoReview::class)->markFailed($this->caseId, $e?->getMessage() ?? 'The classification job failed.');
    }
}
