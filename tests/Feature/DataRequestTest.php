<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CaseComment;
use App\Models\DataRemoval;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\CaseService;
use App\Services\Safety\DataRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mediawiki.pii.enabled', true);
        config()->set('mediawiki.s2s.push_enabled', false);
    }

    private function staff(): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => 1],
            ['username' => 'Admin', 'flags' => ['ts', 'admin']],
        );
    }

    private function requester(): Subject
    {
        return Subject::forUsername('Ordinary Sandpiper', 45);
    }

    private function dataRequest(?string $kind = null, ?Subject $from = null): SafetyCase
    {
        $from ??= $this->requester();

        return SafetyCase::create([
            'reference' => SafetyCase::nextReference('A data request'),
            'type' => SafetyCase::TYPE_DATA,
            'subject_line' => 'A data request',
            'reporter_subject_id' => $from->id,
            'data_kind' => $kind,
        ]);
    }

    #[Test]
    public function the_kind_is_read_from_the_field_the_wiki_declares(): void
    {
        $case = app(CaseService::class)->createFromSubmission([
            'type' => SafetyCase::TYPE_DATA,
            'reporter' => ['username' => 'Ordinary Sandpiper', 'central_id' => 45],
            'roles' => ['request_kind' => 'what-i-want'],
            'answers' => ['what-i-want' => 'erase'],
        ]);

        $this->assertSame(SafetyCase::DATA_ERASURE, $case->data_kind);
    }

    #[Test]
    public function an_unrecognised_answer_leaves_it_for_a_person(): void
    {
        $case = app(CaseService::class)->createFromSubmission([
            'type' => SafetyCase::TYPE_DATA,
            'reporter' => ['username' => 'Ordinary Sandpiper', 'central_id' => 45],
            'roles' => ['request_kind' => 'what-i-want'],
            'answers' => ['what-i-want' => 'something the portal has never seen'],
        ]);

        $this->assertNull($case->data_kind);
    }

    #[Test]
    public function a_flow_that_only_asks_one_thing_declares_the_answer(): void
    {
        $case = app(CaseService::class)->createFromSubmission([
            'type' => SafetyCase::TYPE_DATA,
            'reporter' => ['username' => 'Ordinary Sandpiper', 'central_id' => 45],
            'roles' => ['request_kind' => ['value' => 'erase']],
            'answers' => ['erase-confirm' => true],
        ]);

        $this->assertSame(SafetyCase::DATA_ERASURE, $case->data_kind);
    }

    #[Test]
    public function a_wiki_still_offering_a_copy_produces_a_request_with_no_kind(): void
    {
        foreach (['access', 'sar', 'copy', 'subject-access', 'export'] as $answer) {
            $asked = app(CaseService::class)->createFromSubmission([
                'type' => SafetyCase::TYPE_DATA,
                'reporter' => ['username' => 'Ordinary Sandpiper', 'central_id' => 45],
                'roles' => ['request_kind' => 'request'],
                'answers' => ['request' => $answer],
            ]);

            $this->assertNull($asked->data_kind, "'{$answer}' must not classify as anything");

            $pinned = app(CaseService::class)->createFromSubmission([
                'type' => SafetyCase::TYPE_DATA,
                'reporter' => ['username' => 'Ordinary Sandpiper', 'central_id' => 45],
                'roles' => ['request_kind' => ['value' => $answer]],
                'answers' => [],
            ]);

            $this->assertNull($pinned->data_kind, "a pinned '{$answer}' must not classify either");
        }
    }

    #[Test]
    public function a_request_cannot_be_recorded_as_asking_for_a_copy(): void
    {
        $case = $this->dataRequest();

        $this->actingAs($this->staff())
            ->patchJson("/api/portal/cases/{$case->id}/data-request", ['kind' => 'access'])
            ->assertStatus(422);

        $this->assertNull($case->fresh()->data_kind);
    }

    #[Test]
    public function nothing_hands_over_what_the_portal_holds(): void
    {
        $case = $this->dataRequest(SafetyCase::DATA_ERASURE);

        $this->actingAs($this->staff())
            ->getJson("/api/portal/cases/{$case->id}/data-request/package")
            ->assertNotFound();

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/data-request/disclosed", ['how' => 'Emailed.'])
            ->assertNotFound();
    }

    #[Test]
    public function a_request_of_no_known_kind_cannot_be_approved(): void
    {
        $case = $this->dataRequest();

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/data-request/approve", ['note' => 'Fine.'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'refused');

        $this->assertNull($case->fresh()->data_decision);
    }

    #[Test]
    public function approving_an_erasure_request_starts_the_erasure(): void
    {
        Http::fake();

        $case = $this->dataRequest(SafetyCase::DATA_ERASURE);

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/data-request/approve", [
                'note' => 'We will remove what we hold and write to you when it is done.',
            ])
            ->assertCreated()
            ->assertJsonPath('erasure.state', DataRemoval::STATE_APPROVED);

        $removal = DataRemoval::query()->firstOrFail();

        $this->assertSame($this->requester()->id, $removal->subject_id);
        $this->assertSame($case->id, $removal->case_id);
        $this->assertSame('gdpr-17', $removal->legal_basis);
        $this->assertStringContainsString($case->reference, $removal->reason);
    }

    #[Test]
    public function approving_anything_else_starts_no_erasure(): void
    {
        Http::fake();

        $case = $this->dataRequest(SafetyCase::DATA_RECTIFICATION);

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/data-request/approve", [
                'note' => 'We will correct that and write to you when it is done.',
            ])
            ->assertCreated()
            ->assertJsonPath('erasure', null)
            ->assertJsonPath('still_owed', true);

        $this->assertSame(0, DataRemoval::query()->count());
    }

    #[Test]
    public function an_approved_request_stays_owed_until_it_is_actually_done(): void
    {
        $case = $this->dataRequest(SafetyCase::DATA_RECTIFICATION);
        $staff = $this->staff();

        app(DataRequestService::class)->approve($case, $staff, 'We will correct that.');
        $this->assertTrue($case->fresh()->dataRequestOutstanding());

        app(CaseService::class)->setStatus(
            $case->fresh(),
            SafetyCase::STATUS_ACTION_TAKEN,
            $staff,
            'Corrected.',
        );

        $this->assertFalse($case->fresh()->dataRequestOutstanding());
    }

    #[Test]
    public function what_they_are_told_is_on_their_own_record(): void
    {
        $case = $this->dataRequest(SafetyCase::DATA_RECTIFICATION);

        app(DataRequestService::class)->approve(
            $case,
            $this->staff(),
            'We will send this within a month.',
        );

        $this->assertTrue(
            $case->comments()
                ->where('visibility', CaseComment::VISIBILITY_PUBLIC)
                ->where('body', 'We will send this within a month.')
                ->exists()
        );
    }

    #[Test]
    public function declining_needs_a_reason_and_closes_it_as_no_action(): void
    {
        $case = $this->dataRequest();

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/data-request/decline", ['reason' => ''])
            ->assertStatus(422);

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/data-request/decline", [
                'reason' => 'We do not answer requests for a copy of what we hold through '
                    .'this form. Write to safety@wikioasis.org and a person will answer it.',
            ])
            ->assertOk();

        $case->refresh();

        $this->assertSame(SafetyCase::DECISION_DECLINED, $case->data_decision);
        $this->assertSame(SafetyCase::STATUS_REJECTED, $case->status);
        $this->assertFalse($case->dataRequestOutstanding());

        $this->assertTrue(
            $case->comments()
                ->where('visibility', CaseComment::VISIBILITY_PUBLIC)
                ->where('body', 'like', '%safety@wikioasis.org%')
                ->exists()
        );
    }

    #[Test]
    public function it_cannot_be_decided_twice(): void
    {
        $case = $this->dataRequest(SafetyCase::DATA_RECTIFICATION);

        app(DataRequestService::class)->decline($case, $this->staff(), 'No.');

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/data-request/approve", ['note' => 'Actually yes.'])
            ->assertStatus(422);
    }

    #[Test]
    public function only_a_data_request_has_any_of_this(): void
    {
        $report = SafetyCase::create([
            'reference' => SafetyCase::nextReference('A report'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'A report',
        ]);

        $this->actingAs($this->staff())
            ->patchJson("/api/portal/cases/{$report->id}/data-request", ['kind' => 'erasure'])
            ->assertStatus(422);

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$report->id}/data-request/approve", ['note' => 'Fine.'])
            ->assertStatus(422);
    }
}
