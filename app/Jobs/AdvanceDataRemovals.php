<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Safety\DataRemovalService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AdvanceDataRemovals implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $uniqueFor = 300;

    public function handle(DataRemovalService $removals): void
    {
        $removals->advanceDue(note: static function (string $level, string $text): void {
            match ($level) {
                'error' => Log::warning("Erasure stage failed: {$text}"),
                'comment' => Log::info($text),
                default => null,
            };
        });
    }
}
