<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\AdvanceDataRemovals;
use App\Jobs\ExpireDueSanctions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScheduledJobsTest extends TestCase
{
    #[Test]
    public function the_scheduler_queues_its_jobs(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-01-01 00:10:00'));

        $this->artisan('schedule:run')->assertSuccessful();

        Queue::assertPushed(AdvanceDataRemovals::class);
        Queue::assertPushed(ExpireDueSanctions::class);
    }
}
