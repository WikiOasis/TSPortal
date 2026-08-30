<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\PortalObject;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Services\Safety\References;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_a_queue_worth_looking_at(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(6, SafetyCase::query()->count());
        $this->assertSame(2, Sanction::query()->count());
        $this->assertSame(3, Investigation::query()->count());

        $this->assertTrue(Subject::query()->where('username', 'Bright Kettle Media')->value('banned'));
        $this->assertTrue(SafetyCase::query()->where('type', SafetyCase::TYPE_APPEAL)->exists());

        $this->assertTrue(SafetyCase::query()->awaitingAppealDecision()->exists());
        $this->assertTrue(
            SafetyCase::query()->where('type', SafetyCase::TYPE_APPEAL)
                ->whereNotNull('appeal_outcome')->exists(),
        );
        $this->assertTrue(Sanction::query()->where('push_state', Sanction::PUSH_PARTIAL)->exists());

        $this->assertTrue(
            SafetyCase::query()
                ->where('reference', 'TS-2026-0481')
                ->firstOrFail()
                ->comments()
                ->where('visibility', 'internal')
                ->exists()
        );
    }

    #[Test]
    public function it_demonstrates_the_investigation_flow(): void
    {
        $this->seed(DemoSeeder::class);

        $report = SafetyCase::query()->where('reference', 'TS-2026-0481')->firstOrFail();
        $this->assertNotNull($report->investigation_id);
        $this->assertSame(SafetyCase::STATUS_INVESTIGATING, $report->status);

        $this->assertTrue(
            Investigation::query()->where('status', Investigation::STATUS_MONITORING)->doesntHave('cases')->exists()
        );

        $this->assertTrue(
            DataRemoval::query()->where('state', DataRemoval::STATE_REQUESTED)->exists()
        );

        $this->assertTrue(
            SafetyCase::query()->where('data_kind', SafetyCase::DATA_ERASURE)->exists()
        );
        $this->assertTrue(
            SafetyCase::query()
                ->where('type', SafetyCase::TYPE_DATA)
                ->whereNull('data_kind')
                ->exists()
        );
    }

    #[Test]
    public function every_seeded_reference_resolves(): void
    {
        $this->seed(DemoSeeder::class);

        $references = SafetyCase::query()->pluck('reference')
            ->merge(Sanction::query()->pluck('reference'))
            ->merge(Investigation::query()->pluck('reference'))
            ->merge(DataRemoval::query()->pluck('reference'));

        foreach ($references as $reference) {
            $this->assertNotNull(
                References::resolve($reference),
                "{$reference} resolves to nothing.",
            );
        }

        $this->assertSame($references->count(), PortalObject::query()->count());
    }

    #[Test]
    public function it_refuses_to_run_twice(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(6, SafetyCase::query()->count());
    }
}
