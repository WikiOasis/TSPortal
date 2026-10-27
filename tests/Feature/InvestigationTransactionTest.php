<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Investigation;
use App\Models\InvestigationNote;
use App\Models\User;
use App\Services\Safety\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvestigationTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function staff(int $id = 1, string $name = 'Admin'): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => $id],
            ['username' => $name, 'flags' => ['ts', 'admin'], 'active' => true],
        );
    }

    private function file(): Investigation
    {
        return app(InvestigationService::class)->open(['title' => 'A file', 'assign_to_me' => false], $this->staff());
    }

    #[Test]
    public function a_note_and_several_changes_go_in_together(): void
    {
        $file = $this->file();
        $colleague = $this->staff(2, 'Colleague');

        $this->actingAs($this->staff())
            ->postJson("/api/portal/investigations/{$file->id}/transactions", [
                'note' => 'The two accounts share a **device**.',
                'kind' => InvestigationNote::KIND_FINDING,
                'actions' => [
                    ['type' => 'status', 'value' => Investigation::STATUS_MONITORING],
                    ['type' => 'priority', 'value' => 'high'],
                    ['type' => 'assign', 'value' => $colleague->id],
                    ['type' => 'review', 'value' => '2026-12-01'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Investigation::STATUS_MONITORING)
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.assignee.id', $colleague->id);

        $file->refresh();
        $this->assertSame('2026-12-01', $file->review_at?->toDateString());

        $entries = collect($this->getJson("/api/portal/investigations/{$file->id}/timeline")->json('data'));
        $this->assertSame('note', $entries->last()['kind'], 'the note reads after the changes made with it');

        $note = $file->notes()->sole();
        $this->assertSame(InvestigationNote::KIND_FINDING, $note->kind);
        $this->assertSame('The two accounts share a **device**.', $note->body);
    }

    #[Test]
    public function changes_can_go_in_without_a_note(): void
    {
        $file = $this->file();
        $me = $this->staff();

        $this->actingAs($me)
            ->postJson("/api/portal/investigations/{$file->id}/transactions", [
                'actions' => [['type' => 'claim']],
            ])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $me->id);

        $this->assertSame(0, $file->notes()->count());
    }

    #[Test]
    public function priority_and_review_changes_are_on_the_record(): void
    {
        $file = $this->file();

        $this->actingAs($this->staff())
            ->postJson("/api/portal/investigations/{$file->id}/transactions", [
                'actions' => [
                    ['type' => 'priority', 'value' => 'urgent'],
                    ['type' => 'review', 'value' => '2026-11-15'],
                ],
            ])
            ->assertOk();

        $titles = collect($this->getJson("/api/portal/investigations/{$file->id}/timeline")->json('data'))
            ->pluck('title');

        $this->assertContains('Priority set to urgent', $titles);
        $this->assertContains('To be looked at again on 2026-11-15', $titles);

        $this->assertTrue(AuditLog::query()->where('action', 'investigation.review')->exists());
    }

    #[Test]
    public function a_reply_quotes_a_note_on_the_same_file_and_goes_on_its_own(): void
    {
        $file = $this->file();
        $other = $this->file();
        $this->actingAs($this->staff());

        $first = app(InvestigationService::class)->note($file, 'First thought', InvestigationNote::KIND_NOTE, $this->staff());
        $elsewhere = app(InvestigationService::class)->note($other, 'Somewhere else', InvestigationNote::KIND_NOTE, $this->staff());

        $this->postJson("/api/portal/investigations/{$file->id}/transactions", [
            'note' => 'Agreed', 'reply_to' => $elsewhere->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('reply_to');

        $this->postJson("/api/portal/investigations/{$file->id}/transactions", [
            'note' => 'Agreed', 'reply_to' => $first->id, 'actions' => [['type' => 'priority', 'value' => 'high']],
        ])->assertUnprocessable()->assertJsonValidationErrors('reply_to');

        $this->postJson("/api/portal/investigations/{$file->id}/transactions", [
            'note' => 'Agreed', 'reply_to' => $first->id,
        ])->assertOk();

        $notes = collect($this->getJson("/api/portal/investigations/{$file->id}/timeline")->json('data'))
            ->where('kind', 'note')
            ->values();

        $this->assertSame($first->id, $notes[0]['note_id']);
        $this->assertSame($first->id, $notes[1]['reply_to']);
        $this->assertSame('normal', $file->refresh()->priority);
    }

    #[Test]
    public function a_concluded_file_must_be_reopened_before_its_state_changes(): void
    {
        $file = $this->file();
        app(InvestigationService::class)->close($file, $this->staff());

        $this->actingAs($this->staff())
            ->postJson("/api/portal/investigations/{$file->id}/transactions", [
                'actions' => [['type' => 'status', 'value' => Investigation::STATUS_OPEN]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actions.0.value');

        $this->assertSame(Investigation::STATUS_CLOSED, $file->refresh()->status);
    }
}
