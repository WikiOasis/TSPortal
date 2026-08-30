<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OutboundEvent;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $flags): User
    {
        return User::create([
            'mw_central_id' => random_int(1000, 999999),
            'username' => 'Someone',
            'flags' => $flags,
        ]);
    }

    #[Test]
    public function the_session_endpoint_answers_signed_out_callers(): void
    {
        $this->getJson('/api/portal/session')
            ->assertOk()
            ->assertJsonPath('user', null);
    }

    #[Test]
    public function a_signed_out_caller_gets_nothing_else(): void
    {
        $this->getJson('/api/portal/cases')
            ->assertStatus(401)
            ->assertJsonPath('error', 'signed-out');
    }

    #[Test]
    public function signing_in_without_the_ts_flag_is_not_the_same_as_being_refused(): void
    {
        $this->actingAs($this->user([]))
            ->getJson('/api/portal/cases')
            ->assertStatus(403)
            ->assertJsonPath('error', 'no-access')
            ->assertJsonFragment(['message' => 'Your account is not yet marked as Trust & Safety. Someone holding the user-manager flag can grant it.']);
    }

    #[Test]
    public function staff_can_read_the_queue(): void
    {
        SafetyCase::create(['reference' => 'TS-2026-0001', 'type' => 'report', 'subject_line' => 'A report']);

        $this->actingAs($this->user(['ts']))
            ->getJson('/api/portal/cases')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'TS-2026-0001');
    }

    #[Test]
    public function granting_flags_needs_the_user_manager_flag(): void
    {
        $target = $this->user([]);

        $this->actingAs($this->user(['ts']))
            ->patchJson("/api/portal/staff/{$target->id}", ['granted_flags' => ['ts']])
            ->assertStatus(403)
            ->assertJsonPath('error', 'missing-flag');

        $this->actingAs($this->user(['ts', 'user-manager']))
            ->patchJson("/api/portal/staff/{$target->id}", ['granted_flags' => ['ts']])
            ->assertOk();

        $this->assertTrue($target->fresh()->hasFlag('ts'));
    }

    #[Test]
    public function nobody_changes_their_own_flags(): void
    {
        $me = $this->user(['ts', 'user-manager']);

        $this->actingAs($me)
            ->patchJson("/api/portal/staff/{$me->id}", ['granted_flags' => []])
            ->assertStatus(422)
            ->assertJsonPath('error', 'self');
    }

    #[Test]
    public function a_granted_flag_survives_the_next_sign_in(): void
    {
        $user = $this->user([]);
        $user->granted_flags = ['ts'];
        $user->syncGroupFlags([]);
        $user->save();

        $this->assertTrue($user->hasFlag('ts'));

        $user->syncGroupFlags(['safety']);
        $this->assertEqualsCanonicalizing(['ts', 'admin'], $user->flags);

        $user->syncGroupFlags([]);
        $this->assertSame(['ts'], $user->flags);
    }

    #[Test]
    public function only_an_admin_may_suspend_an_account(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $admin = $this->user(['ts', 'admin']);

        $file = app(InvestigationService::class)->open(['title' => 'Threats'], $admin);

        $this->actingAs($this->user(['ts']))
            ->postJson("/api/portal/subjects/{$subject->id}/sanctions", [
                'type' => 'lock',
                'reason' => 'Threats.',
                'investigation_reference' => $file->reference,
            ])
            ->assertStatus(403);

        $this->actingAs($admin)
            ->postJson("/api/portal/subjects/{$subject->id}/sanctions", [
                'type' => 'lock',
                'reason' => 'Threats.',
                'investigation_reference' => $file->reference,
            ])
            ->assertCreated();
    }

    #[Test]
    public function an_action_cannot_be_taken_without_a_file_behind_it(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);

        $this->actingAs($this->user(['ts', 'admin']))
            ->postJson("/api/portal/subjects/{$subject->id}/sanctions", [
                'type' => 'warning',
                'reason' => 'Edit warring.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('investigation_reference');
    }

    #[Test]
    public function an_action_cannot_be_taken_under_a_concluded_file(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $admin = $this->user(['ts', 'admin']);

        $file = app(InvestigationService::class)->open(['title' => 'Old business'], $admin);
        app(InvestigationService::class)->conclude($file, $admin, ['outcome' => 'no-action']);

        $this->actingAs($admin)
            ->postJson("/api/portal/subjects/{$subject->id}/sanctions", [
                'type' => 'warning',
                'reason' => 'Too late.',
                'investigation_reference' => $file->reference,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'investigation-closed');
    }

    #[Test]
    public function an_internal_note_is_never_queued_for_the_wiki(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $case = SafetyCase::create([
            'reference' => 'TS-2026-0001', 'type' => 'report',
            'subject_line' => 'A report', 'reporter_subject_id' => $subject->id,
        ]);

        $this->actingAs($this->user(['ts']))
            ->postJson("/api/portal/cases/{$case->id}/comments", [
                'body' => 'Cross-check the IP range before replying.',
                'visibility' => 'internal',
            ])
            ->assertCreated();

        $this->assertSame(0, OutboundEvent::query()->where('event', OutboundEvent::COMMENT_ADD)->count());
    }

    #[Test]
    public function a_public_reply_is_queued_for_the_wiki(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $case = SafetyCase::create([
            'reference' => 'TS-2026-0001', 'type' => 'report',
            'subject_line' => 'A report', 'reporter_subject_id' => $subject->id,
        ]);

        $this->actingAs($this->user(['ts']))
            ->postJson("/api/portal/cases/{$case->id}/comments", [
                'body' => 'We have this and are reading through it.',
                'visibility' => 'public',
            ])
            ->assertCreated();

        $event = OutboundEvent::query()->where('event', OutboundEvent::COMMENT_ADD)->firstOrFail();

        $this->assertSame('TS-2026-0001', $event->payload['reference']);
        $this->assertTrue($event->payload['notify']);
    }

    #[Test]
    public function an_anonymous_report_is_never_mirrored_at_all(): void
    {
        $case = SafetyCase::create([
            'reference' => 'TS-2026-0001', 'type' => 'report',
            'subject_line' => 'A report', 'anonymous' => true,
        ]);

        $this->actingAs($this->user(['ts']))
            ->postJson("/api/portal/cases/{$case->id}/comments", [
                'body' => 'Nobody will read this.',
                'visibility' => 'public',
            ])
            ->assertCreated();

        $this->assertSame(0, OutboundEvent::query()->count());
        $this->assertDatabaseCount('case_comments', 1);
    }

    #[Test]
    public function taking_a_case_assigns_it_and_moves_it_along(): void
    {
        $me = $this->user(['ts']);
        $case = SafetyCase::create([
            'reference' => 'TS-2026-0001', 'type' => 'report', 'subject_line' => 'A report',
        ]);

        $this->actingAs($me)
            ->postJson("/api/portal/cases/{$case->id}/claim")
            ->assertOk()
            ->assertJsonPath('data.status', SafetyCase::STATUS_IN_REVIEW)
            ->assertJsonPath('data.assignee.id', $me->id);
    }

    #[Test]
    public function a_status_change_is_recorded_and_queued(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $case = SafetyCase::create([
            'reference' => 'TS-2026-0001', 'type' => 'report',
            'subject_line' => 'A report', 'reporter_subject_id' => $subject->id,
        ]);

        $this->actingAs($this->user(['ts']))
            ->patchJson("/api/portal/cases/{$case->id}", ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->assertNotNull($case->fresh()->closed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'case.status']);
        $this->assertSame(1, OutboundEvent::query()->where('event', OutboundEvent::CASE_UPSERT)->count());
    }
}
