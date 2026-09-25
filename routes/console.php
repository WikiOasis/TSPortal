<?php

declare(strict_types=1);

use App\Jobs\AdvanceDataRemovals;
use App\Jobs\ExpireDueSanctions;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ExpireDueSanctions)->everyTenMinutes();

Schedule::job(new AdvanceDataRemovals)->everyMinute();

if (config('autoreview.enabled')) {
    Schedule::command('tsportal:autoreview --stale')
        ->everyFifteenMinutes()
        ->withoutOverlapping();
}

if (config('opensearch.enabled')) {
    Schedule::command('tsportal:search-index --since=20m')
        ->everyFiveMinutes()
        ->withoutOverlapping();
}
