<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Safety\SanctionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExpireDueSanctions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 540;

    public function handle(SanctionService $sanctions): void
    {
        $count = $sanctions->expireDue();

        if ($count > 0) {
            Log::info("Expired {$count} sanction(s).");
        }
    }
}
