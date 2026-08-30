<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\PortalObject;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\InvestigationService;
use App\Services\Safety\References;
use App\Services\Safety\SanctionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReferenceTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => 1],
            ['username' => 'Admin', 'flags' => ['ts', 'admin']],
        );
    }

    #[Test]
    public function every_kind_of_object_draws_from_the_same_counter(): void
    {
        $one = References::allocate('SafetyCase');
        $two = References::allocate('Investigation');
        $three = References::allocate('Sanction');
        $four = References::allocate('DataRemoval');

        $year = date('Y');

        $this->assertSame("TS-{$year}-0001", $one);
        $this->assertSame("TS-{$year}-0002", $two);
        $this->assertSame("TS-{$year}-0003", $three);
        $this->assertSame("TS-{$year}-0004", $four);
    }

    #[Test]
    public function a_number_is_never_handed_out_twice(): void
    {
        $references = [];
        for ($i = 0; $i < 50; $i++) {
            $references[] = References::allocate('SafetyCase');
        }

        $this->assertCount(50, array_unique($references));
        $this->assertSame(50, PortalObject::query()->count());
    }

    #[Test]
    public function a_reference_says_what_it_names_without_being_told(): void
    {
        $file = app(InvestigationService::class)->open(['title' => 'A file'], $this->staff());

        $found = References::resolve($file->reference);

        $this->assertNotNull($found);
        $this->assertSame('Investigation', $found['type']);
        $this->assertSame($file->id, $found['id']);
    }

    #[Test]
    public function the_prefixes_this_portal_used_to_allocate_still_resolve(): void
    {
        $appeal = SafetyCase::create([
            'reference' => 'AP-2026-0003',
            'type' => SafetyCase::TYPE_APPEAL,
            'subject_line' => 'An old appeal',
        ]);

        $found = References::resolve('AP-2026-0003');

        $this->assertNotNull($found);
        $this->assertSame('SafetyCase', $found['type']);
        $this->assertSame($appeal->id, $found['id']);
    }

    #[Test]
    public function adopting_an_existing_number_stops_the_counter_reusing_it(): void
    {
        $year = (int) date('Y');

        $case = SafetyCase::create([
            'reference' => "TS-{$year}-0481",
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'Something already numbered',
        ]);
        References::adopt($case);

        $this->assertSame("TS-{$year}-0482", References::allocate('Investigation'));
    }

    #[Test]
    public function an_unknown_number_resolves_to_nothing_rather_than_guessing(): void
    {
        $this->assertNull(References::resolve('TS-1999-9999'));

        $this->actingAs($this->staff())
            ->getJson('/api/portal/objects/TS-1999-9999')
            ->assertStatus(404);
    }

    #[Test]
    public function the_lookup_endpoint_says_where_to_go(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $file = app(InvestigationService::class)->open(['title' => 'A file'], $this->staff());

        $sanction = app(SanctionService::class)->issue(
            $subject,
            ['type' => Sanction::TYPE_WARNING, 'reason' => 'A warning.'],
            $this->staff(),
            $file,
        );

        $this->actingAs($this->staff())
            ->getJson("/api/portal/objects/{$sanction->reference}")
            ->assertOk()
            ->assertJsonPath('type', 'Sanction')
            ->assertJsonPath('type_label', 'Action')
            ->assertJsonPath('id', $sanction->id);
    }

    #[Test]
    public function partial_references_are_offered_newest_first(): void
    {
        $year = (int) date('Y');

        foreach (['0101', '0102', '0103'] as $n) {
            References::adopt(SafetyCase::create([
                'reference' => "TS-{$year}-{$n}",
                'type' => SafetyCase::TYPE_REPORT,
                'subject_line' => "Case {$n}",
            ]));
        }

        $this->actingAs($this->staff())
            ->getJson('/api/portal/objects/search?q=TS-'.$year.'-010')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.reference', "TS-{$year}-0103");
    }

    #[Test]
    public function an_investigation_and_a_case_cannot_share_a_number(): void
    {
        $file = app(InvestigationService::class)->open(['title' => 'A file'], $this->staff());
        $case = SafetyCase::create([
            'reference' => SafetyCase::nextReference('A report'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'A report',
        ]);

        $this->assertNotSame($file->reference, $case->reference);
        $this->assertSame(
            2,
            PortalObject::query()->whereIn('reference', [$file->reference, $case->reference])->count(),
        );
    }

    #[Test]
    public function investigations_are_never_offered_to_the_wiki(): void
    {
        $file = app(InvestigationService::class)->open(['title' => 'A file'], $this->staff());

        $this->assertNotNull(Investigation::find($file->id));

        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/wiki/')) {
                $this->assertStringNotContainsString('investigation', strtolower($route->uri()));
            }
        }
    }
}
