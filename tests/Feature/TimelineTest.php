<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CaseComment;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\AppealService;
use App\Services\Safety\CaseService;
use App\Services\Safety\InvestigationService;
use App\Services\Safety\SanctionService;
use App\Services\Safety\Timeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\SignsWikiRequests;
use Tests\TestCase;

class TimelineTest extends TestCase
{
    use RefreshDatabase;
    use SignsWikiRequests;

    private function staff(): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => 1],
            ['username' => 'Admin', 'flags' => ['ts', 'admin']],
        );
    }

    #[Test]
    public function a_case_timeline_carries_the_actions_taken_on_it(): void
    {
        $reporter = Subject::forUsername('Halcyon Reed', 42);
        $reported = Subject::forUsername('Quiet Marlin', 44);

        $case = SafetyCase::create([
            'reference' => SafetyCase::nextReference('Harassment'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'Harassment',
            'reporter_subject_id' => $reporter->id,
        ]);

        $file = app(InvestigationService::class)->open(['title' => 'A file'], $this->staff(), $case);

        app(SanctionService::class)->issue(
            $reported,
            ['type' => Sanction::TYPE_WARNING, 'reason' => 'Please stop.'],
            $this->staff(),
            $file,
            $case,
        );

        $entries = app(Timeline::class)->forCase($case->refresh());
        $kinds = array_column($entries, 'kind');

        $this->assertContains('filed', $kinds);
        $this->assertContains('action', $kinds);
        $this->assertContains('investigation', $kinds);
    }

    #[Test]
    public function it_reads_forwards(): void
    {
        $case = SafetyCase::create([
            'reference' => SafetyCase::nextReference('A report'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'A report',
            'created_at' => now()->subDays(5),
        ]);

        $cases = app(CaseService::class);
        $cases->comment($case, 'First.', CaseComment::VISIBILITY_INTERNAL, $this->staff());
        $cases->setStatus($case, SafetyCase::STATUS_IN_REVIEW);

        $entries = app(Timeline::class)->forCase($case->refresh());
        $stamps = array_filter(array_column($entries, 'at'));

        $sorted = $stamps;
        sort($sorted);

        $this->assertSame($sorted, array_values($stamps));
        $this->assertSame('filed', $entries[0]['kind']);
    }

    #[Test]
    public function status_changes_are_in_words_rather_than_field_names(): void
    {
        $case = SafetyCase::create([
            'reference' => SafetyCase::nextReference('A report'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'A report',
        ]);

        app(CaseService::class)->setStatus($case, SafetyCase::STATUS_IN_REVIEW);

        $titles = array_column(app(Timeline::class)->forCase($case->refresh()), 'title');

        $this->assertContains('Status changed from received to in review', $titles);
    }

    #[Test]
    public function an_appeal_decision_reads_as_words_not_a_raw_action_name(): void
    {
        Http::fake(['*' => Http::response(['result' => 'ok'])]);

        $subject = Subject::forUsername('Halcyon Reed', 42);
        $file = app(InvestigationService::class)->open(['title' => 'A file'], $this->staff());
        $action = app(SanctionService::class)->issue(
            $subject,
            ['type' => Sanction::TYPE_WARNING, 'reason' => 'Please stop.'],
            $this->staff(),
            $file,
        );

        $this->wikiPost('/api/wiki/v1/appeals', [
            'username' => 'Halcyon Reed',
            'body' => 'It was not me.',
            'sanction_reference' => $action->reference,
        ])->assertSuccessful();

        $case = SafetyCase::query()->where('type', SafetyCase::TYPE_APPEAL)->firstOrFail();

        app(AppealService::class)->decide($case, SafetyCase::APPEAL_GRANTED, $this->staff(), 'Lifted.');

        $titles = array_column(app(Timeline::class)->forCase($case->refresh()), 'title');

        $this->assertContains('Appeal granted', $titles);
        $this->assertNotContains('appeal.decided', $titles);
    }

    #[Test]
    public function an_internal_note_is_marked_as_one_on_the_timeline(): void
    {
        $case = SafetyCase::create([
            'reference' => SafetyCase::nextReference('A report'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'A report',
        ]);

        app(CaseService::class)->comment($case, 'Do not repeat this.', CaseComment::VISIBILITY_INTERNAL, $this->staff());

        $note = collect(app(Timeline::class)->forCase($case->refresh()))
            ->firstWhere('body', 'Do not repeat this.');

        $this->assertNotNull($note);
        $this->assertSame('internal', $note['visibility']);
        $this->assertSame('Internal note', $note['title']);
    }

    #[Test]
    public function nothing_appears_twice(): void
    {
        $case = SafetyCase::create([
            'reference' => SafetyCase::nextReference('A report'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'A report',
        ]);

        app(CaseService::class)->comment($case, 'Only once.', CaseComment::VISIBILITY_INTERNAL, $this->staff());

        $bodies = array_column(app(Timeline::class)->forCase($case->refresh()), 'body');

        $this->assertCount(1, array_filter($bodies, fn ($b) => $b === 'Only once.'));
    }

    #[Test]
    public function an_investigation_timeline_carries_its_reports_notes_and_actions(): void
    {
        $case = SafetyCase::create([
            'reference' => SafetyCase::nextReference('A report'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'A report',
        ]);

        $service = app(InvestigationService::class);
        $file = $service->open(['title' => 'A file', 'premise' => 'Why this was opened.'], $this->staff(), $case);
        $service->note($file, 'What was found.', 'finding', $this->staff());

        app(SanctionService::class)->issue(
            Subject::forUsername('Quiet Marlin', 44),
            ['type' => Sanction::TYPE_WARNING, 'reason' => 'Please stop.'],
            $this->staff(),
            $file,
        );

        $service->conclude($file->refresh(), $this->staff(), ['outcome' => 'warned']);

        $kinds = array_column(app(Timeline::class)->forInvestigation($file->refresh()), 'kind');

        $this->assertContains('opened', $kinds);
        $this->assertContains('case', $kinds);
        $this->assertContains('note', $kinds);
        $this->assertContains('action', $kinds);
        $this->assertContains('concluded', $kinds);
    }

    #[Test]
    public function the_timeline_endpoints_are_staff_only(): void
    {
        $case = SafetyCase::create([
            'reference' => SafetyCase::nextReference('A report'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'A report',
        ]);

        $this->getJson("/api/portal/cases/{$case->id}/timeline")->assertStatus(401);

        $this->actingAs($this->staff())
            ->getJson("/api/portal/cases/{$case->id}/timeline")
            ->assertOk()
            ->assertJsonStructure(['data' => [['at', 'kind', 'title']]]);
    }
}
