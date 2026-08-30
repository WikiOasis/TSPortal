<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Safety\DataRemovalService;
use Illuminate\Console\Command;

class AdvanceDataRemovals extends Command
{
    protected $signature = 'tsportal:advance-removals {--reference= : Only this one}';

    protected $description = 'Move erasures on: send approved ones, check renames, start scrubs, retry failures';

    public function handle(DataRemovalService $removals): int
    {
        $result = $removals->advanceDue(
            $this->option('reference'),
            function (string $level, string $text): void {
                match ($level) {
                    'error' => $this->error($text),
                    'comment' => $this->comment($text),
                    default => $this->line($text),
                };
            },
        );

        if ($result['checked'] > 0) {
            $this->info(sprintf('Checked %d, moved %d on.', $result['checked'], $result['moved']));
        }

        return self::SUCCESS;
    }
}
