<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncToWiki;
use App\Services\MediaWiki\OutboundEventDrainer;
use Illuminate\Console\Command;

class DeliverOutboundEvents extends Command
{
    protected $signature = 'tsportal:sync
                            {--limit=200 : How many events to attempt in one pass}
                            {--retry-stuck : Also retry events that have exhausted their attempts}
                            {--queue : Hand the work to a queue worker instead of doing it here}';

    protected $description = 'Push queued Trust & Safety activity to the wiki';

    public function handle(OutboundEventDrainer $drainer): int
    {
        if ($this->option('retry-stuck')) {
            $this->info(sprintf('Revived %d stuck event(s).', $drainer->revive()));
        }

        if ($this->option('queue')) {
            SyncToWiki::dispatch((int) $this->option('limit'));

            $this->info('Queued a sync pass. Watch the worker for what it does.');

            return self::SUCCESS;
        }

        if (! $drainer->enabled()) {
            $this->warn('Pushing to the wiki is disabled (MW_S2S_PUSH_ENABLED) or MW_S2S_SECRET is unset. Nothing sent.');

            return self::SUCCESS;
        }

        $report = $drainer->drain((int) $this->option('limit'));

        foreach ($report->problems as $problem) {
            $this->warn($problem);
        }

        if ($report->attempted() === 0) {
            $this->info('Nothing to send.');

            return self::SUCCESS;
        }

        $this->info("Sent {$report->sent}, failed {$report->failed}.");

        return self::SUCCESS;
    }
}
