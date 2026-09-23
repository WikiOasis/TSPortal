<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SafetyCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\SignsWikiRequests;
use Tests\TestCase;

class AutomatedCaseQueueTest extends TestCase
{
    use RefreshDatabase;
    use SignsWikiRequests;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('categories.threat_to_life.categories', ['threat-to-life']);
        config()->set('categories.threat_to_life.priority', SafetyCase::PRIORITY_URGENT);
    }

    private function staff(): User
    {
        return User::create([
            'username' => 'Reviewer',
            'mw_central_id' => 900,
            'flags' => [User::FLAG_TS],
            'active' => true,
        ]);
    }

    private function submit(bool $automated, array $categories = []): SafetyCase
    {
        $this->wikiPost('/api/wiki/v1/submissions', [
            'type' => 'report',
            'wiki' => 'oasiswiki',
            'anonymous' => $automated,
            'automated' => $automated,
            'reporter' => $automated ? null : ['central_id' => 7, 'username' => 'Halcyon Reed'],
            'answers' => ['report' => 'something'],
            'categories' => $categories,
        ])->assertStatus(201);

        return SafetyCase::query()->latest('id')->firstOrFail();
    }

    #[Test]
    public function reports_from_people_are_listed_before_automated_ones_whatever_the_sort(): void
    {
        $automatedFirst = $this->submit(true);
        $automatedSecond = $this->submit(true);
        $person = $this->submit(false);

        $staff = $this->staff();

        foreach (['oldest', 'newest', 'updated', 'reference', 'status', 'priority'] as $sort) {
            $order = $this->actingAs($staff)
                ->getJson('/api/portal/cases?sort='.$sort)
                ->assertOk()
                ->json('data.*.reference');

            $this->assertSame($person->reference, $order[0], "sort={$sort} put an automated case first");
            $this->assertEqualsCanonicalizing(
                [$automatedFirst->reference, $automatedSecond->reference],
                array_slice($order, 1),
            );
        }
    }

    #[Test]
    public function a_threat_to_life_from_a_person_stays_above_everything(): void
    {
        $this->submit(false);
        $this->submit(true);
        $urgent = $this->submit(false, [['id' => 'threat-to-life', 'label' => 'Threat to life']]);

        $first = $this->actingAs($this->staff())
            ->getJson('/api/portal/cases?sort=newest')
            ->assertOk()
            ->json('data.0.reference');

        $this->assertSame($urgent->reference, $first);
    }

    #[Test]
    public function a_closed_report_from_a_person_does_not_jump_the_open_automated_ones(): void
    {
        $closed = $this->submit(false);
        $closed->forceFill(['status' => SafetyCase::STATUS_CLOSED, 'closed_at' => now()])->save();
        $automated = $this->submit(true);

        $order = $this->actingAs($this->staff())
            ->getJson('/api/portal/cases?status=')
            ->assertOk()
            ->json('data.*.reference');

        $this->assertSame([$automated->reference, $closed->reference], $order);
    }

    #[Test]
    public function the_queue_filters_by_source(): void
    {
        $automated = $this->submit(true);
        $person = $this->submit(false);

        $staff = $this->staff();

        $this->assertSame(
            [$automated->reference],
            $this->actingAs($staff)->getJson('/api/portal/cases?source=automated')->assertOk()->json('data.*.reference'),
        );
        $this->assertSame(
            [$person->reference],
            $this->actingAs($staff)->getJson('/api/portal/cases?source=people')->assertOk()->json('data.*.reference'),
        );
        $this->assertTrue(
            $this->actingAs($staff)->getJson('/api/portal/cases?source=automated')->json('data.0.automated'),
        );

        $this->actingAs($staff)->getJson('/api/portal/cases?source=robots')->assertStatus(422);
    }
}
