<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OutboundEvent;
use App\Services\MediaWiki\DrainReport;
use App\Services\MediaWiki\OutboundEventDrainer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SyncToWiki implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const MAX_SLEEP = 60;

    public int $tries = 20;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $limit = 200) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('wikisync'))
                ->releaseAfter(10)
                ->expireAfter(600),
        ];
    }

    public function handle(OutboundEventDrainer $drainer): void
    {
        if (! $drainer->enabled()) {
            return;
        }

        $this->scheduleFollowUp($drainer->drain($this->limit));
    }

    private function scheduleFollowUp(DrainReport $report): void
    {
        $delay = $this->nextPassIn($report);

        if ($delay === null) {
            return;
        }

        self::dispatch($this->limit)->delay($delay);
    }

    private function nextPassIn(DrainReport $report): ?int
    {
        if ($report->failed > 0) {
            return self::MAX_SLEEP;
        }

        if ($report->halted || OutboundEvent::query()->due()->exists()) {
            return 0;
        }

        /** @var Carbon|string|null $earliest */
        $earliest = OutboundEvent::query()
            ->whereNull('delivered_at')
            ->whereNotNull('next_attempt_at')
            ->min('next_attempt_at');

        if ($earliest === null) {
            return null;
        }

        $seconds = (int) ceil(now()->diffInSeconds(Carbon::parse($earliest), absolute: false));

        return max(1, min(self::MAX_SLEEP, $seconds));
    }
}
