<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CaseComment;
use App\Models\Investigation;
use App\Models\OutboundEvent;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\CaseService;
use App\Services\Safety\InvestigationService;
use App\Services\Safety\SanctionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvestigationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $flags = ['ts', 'admin']): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => 1],
            ['username' => 'Admin', 'flags' => $flags],
        );
    }

    private function report(?Subject $reporter = null): SafetyCase
    {
        $reporter ??= Subject::forUsername('Halcyon Reed', 42);

        return SafetyCase::create([
            'reference' => SafetyCase::nextReference('Harassment'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'Harassment across several talk pages',
            'status' => SafetyCase::STATUS_RECEIVED,
            'reporter_subject_id' => $reporter->id,
        ]);
    }

    #[Test]
    public function opening_a_file_from_a_report_moves_it_to_being_looked_into(): void
    {
        $case = $this->report();

        $file = app(InvestigationService::class)->open(
            ['title' => 'Sustained harassment'],
            $this->staff(),
            $case,
        );

        $case->refresh();

        $this->assertSame($file->id, $case->investigation_id);
        $this->assertSame(SafetyCase::STATUS_INVESTIGATING, $case->status);

        $this->assertTrue($case->isOpen());
    }

    #[Test]
    public function the_reporter_is_told_the_status_but_not_the_file(): void
    {
        $case = $this->report();

        app(InvestigationService::class)->open(['title' => 'Something sensitive'], $this->staff(), $case);

        $queued = OutboundEvent::query()->where('event', OutboundEvent::CASE_UPSERT)->get();

        $this->assertTrue($queued->isNotEmpty());

        foreach ($queued as $event) {
            $payload = json_encode($event->payload);
            $this->assertStringNotContainsString('Something sensitive', $payload);
        }
    }

    #[Test]
    public function a_file_can_be_opened_on_an_account_the_portal_has_never_heard_of(): void
    {
        $this->assertSame(0, Subject::query()->count());

        $file = app(InvestigationService::class)->open([
            'title' => 'Cross-wiki pattern',
            'subjects' => [
                ['username' => 'pumice_fan_2026', 'role' => 'subject'],
                ['username' => '203.0.113.7', 'role' => 'related'],
            ],
        ], $this->staff());

        $this->assertSame(2, $file->subjects()->count());

        $ip = Subject::query()->where('username', '203.0.113.7')->firstOrFail();
        $this->assertNull($ip->mw_central_id);

        $this->assertTrue(Subject::query()->where('username', 'Pumice fan 2026')->exists());
    }

    #[Test]
    public function concluding_a_file_answers_every_report_on_it(): void
    {
        $one = $this->report();
        $two = $this->report(Subject::forUsername('Second Reporter', 43));

        $service = app(InvestigationService::class);
        $file = $service->open(['title' => 'One pattern'], $this->staff(), $one);
        $service->attachCase($file, $two, $this->staff());

        $service->conclude($file, $this->staff(), [
            'outcome' => Investigation::OUTCOME_WARNED,
            'findings' => 'Internal reasoning that never leaves the portal.',
        ]);

        $this->assertSame(SafetyCase::STATUS_ACTION_TAKEN, $one->refresh()->status);
        $this->assertSame(SafetyCase::STATUS_ACTION_TAKEN, $two->refresh()->status);
        $this->assertSame(Investigation::STATUS_CONCLUDED, $file->refresh()->status);
    }

    #[Test]
    public function a_file_that_found_nothing_closes_its_reports_as_no_action(): void
    {
        $case = $this->report();
        $service = app(InvestigationService::class);
        $file = $service->open(['title' => 'Nothing in it'], $this->staff(), $case);

        $service->conclude($file, $this->staff(), ['outcome' => Investigation::OUTCOME_UNFOUNDED]);

        $this->assertSame(SafetyCase::STATUS_REJECTED, $case->refresh()->status);
    }

    #[Test]
    public function findings_are_never_sent_to_the_reporter(): void
    {
        $case = $this->report();
        $service = app(InvestigationService::class);
        $file = $service->open(['title' => 'A file'], $this->staff(), $case);

        $service->conclude($file, $this->staff(), [
            'outcome' => Investigation::OUTCOME_WARNED,
            'findings' => 'The other account is a sock of a locked one; do not tell the reporter.',
            'disclosable' => true,
            'message' => 'We looked into this and have acted. Thank you for reporting it.',
        ]);

        $bodies = $case->comments()->pluck('body')->implode("\n");

        $this->assertStringContainsString('We looked into this and have acted', $bodies);
        $this->assertStringNotContainsString('sock of a locked one', $bodies);
    }

    #[Test]
    public function nothing_is_said_to_the_reporter_unless_the_outcome_is_disclosable(): void
    {
        $case = $this->report();
        $service = app(InvestigationService::class);
        $file = $service->open(['title' => 'A file'], $this->staff(), $case);

        $service->conclude($file, $this->staff(), [
            'outcome' => Investigation::OUTCOME_WARNED,
            'message' => 'This should not be sent.',
        ]);

        $this->assertFalse(
            $case->comments()
                ->where('visibility', CaseComment::VISIBILITY_PUBLIC)
                ->where('body', 'This should not be sent.')
                ->exists()
        );
    }

    #[Test]
    public function an_action_puts_its_account_on_the_file(): void
    {
        $subject = Subject::forUsername('Quiet Marlin', 44);
        $file = app(InvestigationService::class)->open(['title' => 'A file'], $this->staff());

        app(SanctionService::class)->issue(
            $subject,
            ['type' => Sanction::TYPE_WARNING, 'reason' => 'A warning.'],
            $this->staff(),
            $file,
        );

        $this->assertTrue($file->subjects()->whereKey($subject->id)->exists());
    }

    #[Test]
    public function an_appeal_lands_on_the_file_that_produced_the_action(): void
    {
        $subject = Subject::forUsername('Bright Kettle Media', 43);
        $file = app(InvestigationService::class)->open(['title' => 'Link spam'], $this->staff());

        $sanction = app(SanctionService::class)->issue(
            $subject,
            ['type' => Sanction::TYPE_LOCK, 'reason' => 'Spam.'],
            $this->staff(),
            $file,
        );

        $appeal = app(CaseService::class)->createFromSubmission([
            'type' => SafetyCase::TYPE_APPEAL,
            'reporter' => ['username' => 'Bright Kettle Media', 'central_id' => 43],
            'sanction_reference' => $sanction->reference,
            'summary' => 'It was my own site.',
        ]);

        $this->assertSame($file->id, $appeal->investigation_id);
    }

    #[Test]
    public function taking_a_report_off_a_file_does_not_pretend_it_is_unread(): void
    {
        $case = $this->report();
        $service = app(InvestigationService::class);
        $file = $service->open(['title' => 'Wrong file'], $this->staff(), $case);

        $service->detachCase($file, $case->refresh(), $this->staff());

        $case->refresh();
        $this->assertNull($case->investigation_id);
        $this->assertSame(SafetyCase::STATUS_IN_REVIEW, $case->status);
    }

    #[Test]
    public function a_file_kept_on_monitoring_comes_back(): void
    {
        $service = app(InvestigationService::class);
        $file = $service->open(['title' => 'Watching'], $this->staff());
        $service->setStatus($file, Investigation::STATUS_MONITORING, $this->staff());

        $file->forceFill(['review_at' => now()->subDay()])->save();

        $this->assertTrue(Investigation::query()->dueForReview()->whereKey($file->id)->exists());

        $this->actingAs($this->staff())
            ->getJson('/api/portal/dashboard')
            ->assertOk()
            ->assertJsonPath('due_a_look.overdue_files', 1)
            ->assertJsonPath('due_a_look.total', 1);
    }

    #[Test]
    public function the_file_lists_only_for_staff(): void
    {
        app(InvestigationService::class)->open(['title' => 'A file'], $this->staff());

        $this->getJson('/api/portal/investigations')->assertStatus(401);

        $outsider = User::create(['mw_central_id' => 99, 'username' => 'Newcomer', 'flags' => []]);
        $this->actingAs($outsider)->getJson('/api/portal/investigations')->assertStatus(403);

        $this->actingAs($this->staff())->getJson('/api/portal/investigations')->assertOk();
    }
}
