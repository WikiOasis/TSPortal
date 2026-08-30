<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SafetyCase;
use App\Models\User;
use App\Services\Safety\CaseService;
use App\Services\Safety\Triage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\SignsWikiRequests;
use Tests\TestCase;

class ThreatToLifeTest extends TestCase
{
    use RefreshDatabase;
    use SignsWikiRequests;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('categories.threat_to_life.categories', ['threat-to-life', 'threat-of-physical-harm']);
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

    /** @param list<array<string, mixed>> $categories */
    private function submit(array $categories, array $extra = []): SafetyCase
    {
        $this->wikiPost('/api/wiki/v1/submissions', $extra + [
            'type' => 'report',
            'wiki' => 'oasiswiki',
            'reporter' => ['central_id' => 7, 'username' => 'Halcyon Reed'],
            'answers' => ['report' => 'something'],
            'categories' => $categories,
        ])->assertStatus(201);

        return SafetyCase::query()->latest('id')->firstOrFail();
    }

    #[Test]
    public function a_threat_to_life_report_is_urgent_before_anybody_has_read_it(): void
    {
        $case = $this->submit([
            ['id' => 'threat-of-physical-harm', 'label' => 'Threat of physical harm', 'group' => 'harm'],
        ]);

        $this->assertSame(SafetyCase::PRIORITY_URGENT, $case->priority);
        $this->assertTrue($case->isThreatToLife());
    }

    #[Test]
    public function it_is_found_in_the_answers_when_the_wiki_declared_no_categories(): void
    {
        $case = $this->submit([], ['answers' => [
            'report' => 'threat-of-physical-harm',
            'threat-to-life' => 'yes',
            'pages' => ['Main Page'],
        ]]);

        $this->assertSame(0, $case->categories()->count(), 'the premise: no categories');

        $this->assertTrue($case->isThreatToLife());
        $this->assertSame(SafetyCase::PRIORITY_URGENT, $case->priority);
    }

    #[Test]
    public function a_field_named_for_it_counts_on_its_own(): void
    {
        $case = $this->submit([], ['answers' => ['threat-to-life' => true, 'what' => 'a description']]);

        $this->assertTrue($case->isThreatToLife());
    }

    #[Test]
    public function answering_no_to_that_field_is_not_a_threat_to_life(): void
    {
        foreach (['no', 'No', 'false', false, '', null, []] as $answer) {
            $case = $this->submit([], ['answers' => ['threat-to-life' => $answer, 'what' => 'something']]);

            $this->assertFalse(
                $case->isThreatToLife(),
                'answering '.json_encode($answer).' should not flag the case',
            );
        }
    }

    #[Test]
    public function a_free_text_box_mentioning_a_threat_does_not_count(): void
    {
        $case = $this->submit([], ['answers' => [
            'details' => 'they threatened to report me to my employer, which is a threat to life at this rate',
        ]]);

        $this->assertFalse($case->isThreatToLife());
        $this->assertSame(SafetyCase::PRIORITY_NORMAL, $case->priority);
    }

    #[Test]
    public function the_flag_is_stored_so_the_queue_can_filter_on_it(): void
    {
        $case = $this->submit([], ['answers' => ['threat-to-life' => 'yes']]);

        $this->assertDatabaseHas('cases', [
            'reference' => $case->reference,
            'threat_to_life' => true,
        ]);
    }

    #[Test]
    public function re_categorising_never_clears_the_flag(): void
    {
        $case = $this->submit([], ['answers' => ['threat-to-life' => 'yes']]);
        $this->assertTrue($case->isThreatToLife());

        $this->actingAs($this->staff())
            ->putJson('/api/portal/cases/'.$case->id.'/categories', [
                'categories' => [['id' => 'harassment', 'label' => 'Harassment']],
            ])->assertOk();

        $this->assertTrue($case->fresh()->isThreatToLife());
    }

    #[Test]
    public function an_ordinary_report_is_left_alone(): void
    {
        $case = $this->submit([['id' => 'a-licensing-issue', 'label' => 'Copyright and licensing']]);

        $this->assertSame(SafetyCase::PRIORITY_NORMAL, $case->priority);
        $this->assertFalse($case->isThreatToLife());
    }

    #[Test]
    public function it_is_found_when_it_is_not_the_first_answer(): void
    {
        $case = $this->submit([
            ['id' => 'harassment', 'label' => 'Harassment'],
            ['id' => 'threat-to-life', 'label' => 'Threat to life'],
        ]);

        $this->assertSame('harassment', $case->category);
        $this->assertSame(SafetyCase::PRIORITY_URGENT, $case->priority);
        $this->assertTrue($case->isThreatToLife());
    }

    #[Test]
    public function a_wikis_own_spelling_is_reconciled_before_it_is_checked(): void
    {
        config()->set('categories.aliases', ['someone-will-be-hurt' => 'threat-to-life']);

        $case = $this->submit([['id' => 'someone-will-be-hurt', 'label' => 'Someone will be hurt']]);

        $this->assertSame(SafetyCase::PRIORITY_URGENT, $case->priority);
        $this->assertSame('Someone will be hurt', $case->categories()->sole()->label);
    }

    #[Test]
    public function correcting_a_categorisation_escalates_it(): void
    {
        $case = $this->submit([['id' => 'something-else', 'label' => 'Something else']]);
        $this->assertSame(SafetyCase::PRIORITY_NORMAL, $case->priority);

        $this->actingAs($this->staff())
            ->putJson('/api/portal/cases/'.$case->id.'/categories', [
                'categories' => [['id' => 'threat-to-life', 'label' => 'Threat to life']],
            ])->assertOk();

        $this->assertSame(SafetyCase::PRIORITY_URGENT, $case->fresh()->priority);

        $escalation = AuditLog::query()->where('action', 'case.escalated')->sole();
        $this->assertSame('threat-to-life', $escalation->meta['reason']);
        $this->assertSame(SafetyCase::PRIORITY_NORMAL, $escalation->meta['from']);
    }

    #[Test]
    public function it_never_quietly_lowers_a_priority_somebody_set(): void
    {
        $case = $this->submit([['id' => 'threat-to-life', 'label' => 'Threat to life']]);
        $staff = $this->staff();

        $this->actingAs($staff)
            ->patchJson('/api/portal/cases/'.$case->id, ['priority' => SafetyCase::PRIORITY_LOW])
            ->assertOk();

        $this->actingAs($staff)
            ->putJson('/api/portal/cases/'.$case->id.'/categories', [
                'categories' => [
                    ['id' => 'threat-to-life', 'label' => 'Threat to life'],
                    ['id' => 'harassment', 'label' => 'Harassment'],
                ],
            ])->assertOk();

        $this->assertSame(SafetyCase::PRIORITY_LOW, $case->fresh()->priority);
        $this->assertSame(0, AuditLog::query()->where('action', 'case.escalated')->count());
    }

    #[Test]
    public function taking_a_case_off_urgent_records_what_it_was_about(): void
    {
        $case = $this->submit([['id' => 'threat-to-life', 'label' => 'Threat to life']]);

        $this->actingAs($this->staff())
            ->patchJson('/api/portal/cases/'.$case->id, ['priority' => SafetyCase::PRIORITY_NORMAL])
            ->assertOk();

        $line = AuditLog::query()->where('action', 'case.priority')->sole();
        $this->assertTrue($line->meta['threat_to_life']);
    }

    #[Test]
    public function an_urgent_case_is_at_the_top_of_the_queue_whatever_the_sort(): void
    {
        $old = $this->submit([['id' => 'harassment', 'label' => 'Harassment']]);
        $urgent = $this->submit([['id' => 'threat-to-life', 'label' => 'Threat to life']]);
        $newest = $this->submit([['id' => 'a-licensing-issue', 'label' => 'Copyright']]);

        $staff = $this->staff();

        foreach (['oldest', 'newest', 'updated', 'reference', 'status'] as $sort) {
            $first = $this->actingAs($staff)
                ->getJson('/api/portal/cases?sort='.$sort)
                ->assertOk()
                ->json('data.0.reference');

            $this->assertSame($urgent->reference, $first, "sort={$sort} buried the urgent case");
        }

        $this->assertNotSame($urgent->reference, $old->reference);
        $this->assertNotSame($urgent->reference, $newest->reference);
    }

    #[Test]
    public function a_closed_urgent_case_does_not_hold_the_top_of_the_queue_forever(): void
    {
        $urgent = $this->submit([['id' => 'threat-to-life', 'label' => 'Threat to life']]);
        $open = $this->submit([['id' => 'harassment', 'label' => 'Harassment']]);

        $urgent->forceFill(['status' => SafetyCase::STATUS_CLOSED])->save();

        $first = $this->actingAs($this->staff())
            ->getJson('/api/portal/cases')
            ->assertOk()
            ->json('data.0.reference');

        $this->assertSame($open->reference, $first);
    }

    #[Test]
    public function they_can_be_asked_for_on_their_own(): void
    {
        $this->submit([['id' => 'harassment', 'label' => 'Harassment']]);
        $urgent = $this->submit([['id' => 'threat-to-life', 'label' => 'Threat to life']]);

        $staff = $this->staff();

        $response = $this->actingAs($staff)
            ->getJson('/api/portal/cases?threat=1')
            ->assertOk();

        $this->actingAs($staff)->getJson('/api/portal/cases?threat=true')->assertStatus(422);

        $this->assertSame([$urgent->reference], $response->json('data.*.reference'));
    }

    #[Test]
    public function the_case_tells_the_interface_which_it_is(): void
    {
        $case = $this->submit([['id' => 'threat-to-life', 'label' => 'Threat to life']]);

        $this->actingAs($this->staff())
            ->getJson('/api/portal/cases/'.$case->id)
            ->assertOk()
            ->assertJsonPath('data.threat_to_life', true);
    }

    #[Test]
    public function a_priority_the_queue_cannot_sort_is_refused(): void
    {
        $case = $this->submit([]);

        $this->actingAs($this->staff())
            ->patchJson('/api/portal/cases/'.$case->id, ['priority' => 'extremely'])
            ->assertStatus(422);

        $this->expectException(\InvalidArgumentException::class);
        app(CaseService::class)->setPriority($case, 'extremely');
    }

    #[Test]
    public function an_empty_list_means_nothing_is_urgent_rather_than_everything(): void
    {
        config()->set('categories.threat_to_life.categories', []);

        $case = $this->submit([['id' => 'threat-to-life', 'label' => 'Threat to life']]);

        $this->assertSame(SafetyCase::PRIORITY_NORMAL, $case->priority);
        $this->assertFalse($case->isThreatToLife());
        $this->assertFalse(Triage::isThreatToLife(['threat-to-life']));
    }

    #[Test]
    public function a_priority_misconfigured_to_nonsense_falls_back_to_urgent(): void
    {
        config()->set('categories.threat_to_life.priority', 'extremely');

        $this->assertSame(SafetyCase::PRIORITY_URGENT, Triage::escalatedPriority());
    }
}
