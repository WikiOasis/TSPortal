<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Investigation;
use App\Services\Safety\InvestigationPages;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchPageInfo implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    public int $uniqueFor = 120;

    public function __construct(public readonly int $investigationId) {}

    public function uniqueId(): string
    {
        return (string) $this->investigationId;
    }

    public function handle(InvestigationPages $pages): void
    {
        $investigation = Investigation::query()->find($this->investigationId);

        if ($investigation !== null) {
            $pages->fetchMissing($investigation);
        }
    }
}
