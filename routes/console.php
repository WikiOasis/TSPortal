<?php

declare(strict_types=1);

use App\Jobs\AdvanceDataRemovals;
use App\Jobs\ExpireDueSanctions;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ExpireDueSanctions)->everyTenMinutes();

Schedule::job(new AdvanceDataRemovals)->everyMinute();
