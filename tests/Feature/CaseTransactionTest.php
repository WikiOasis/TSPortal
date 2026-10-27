<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CaseComment;
use App\Models\OutboundEvent;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\CaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CaseTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function staff(int $id = 1, string $name = 'Admin'): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => $id],
            ['username' => $name, 'flags' => ['ts', 'admin'], 'active' => true],
        );
    }

    private function report(): SafetyCase
    {
        return SafetyCase::create([
            'reference' => SafetyCase::nextReference('Harassment'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'Harassment across several talk pages',
            'status' => SafetyCase::STATUS_RECEIVED,
            'reporter_subject_id' => Subject::forUsername('Halcyon Reed', 42)->id,
        ]);
    }

    #[Test]
    public function a_comment_and_several_actions_go_in_together(): void
    {
        $case = $this->report();
        $colleague = $this->staff(2, 'Colleague');

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/transactions", [
                'comment' => 'Taking a **closer** look.',
                'visibility' => 'internal',
                'actions' => [
                    ['type' => 'status', 'value' => SafetyCase::STATUS_IN_REVIEW],
                    ['type' => 'priority', 'value' => 'high'],
                    ['type' => 'assign', 'value' => $colleague->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', SafetyCase::STATUS_IN_REVIEW)
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.assignee.id', $colleague->id);

        $comment = $case->comments()->whereNull('kind')->sole();
        $this->assertSame('Taking a **closer** look.', $comment->body);
        $this->assertSame(CaseComment::VISIBILITY_INTERNAL, $comment->visibility);
    }

    #[Test]
    public function an_action_can_go_in_on_its_own(): void
    {
        $case = $this->report();
        $me = $this->staff();

        $this->actingAs($me)
            ->postJson("/api/portal/cases/{$case->id}/transactions", [
                'actions' => [['type' => 'claim']],
            ])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $me->id);

        $this->assertSame(0, $case->comments()->whereNull('kind')->count());
    }

    #[Test]
    public function an_empty_submission_is_refused(): void
    {
        $case = $this->report();

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/transactions", ['comment' => '   ', 'visibility' => 'internal'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('comment');
    }

    #[Test]
    public function one_bad_action_means_nothing_happens(): void
    {
        $case = $this->report();

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/transactions", [
                'comment' => 'Hello',
                'visibility' => 'public',
                'actions' => [
                    ['type' => 'priority', 'value' => 'high'],
                    ['type' => 'status', 'value' => 'made-up'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actions.1.value');

        $case->refresh();
        $this->assertSame('normal', $case->priority);
        $this->assertSame(0, $case->comments()->count());
    }

    #[Test]
    public function the_same_action_cannot_be_given_twice(): void
    {
        $case = $this->report();

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/transactions", [
                'actions' => [
                    ['type' => 'priority', 'value' => 'high'],
                    ['type' => 'priority', 'value' => 'low'],
                ],
            ])
            ->assertUnprocessable();
    }

    #[Test]
    public function a_reply_goes_on_its_own_without_actions(): void
    {
        $case = $this->report();
        $this->actingAs($this->staff());

        $first = $this->postJson("/api/portal/cases/{$case->id}/comments", ['body' => 'First', 'visibility' => 'public'])->json('id');

        $this->postJson("/api/portal/cases/{$case->id}/transactions", [
            'comment' => 'Second',
            'visibility' => 'public',
            'reply_to' => $first,
            'actions' => [['type' => 'priority', 'value' => 'high']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reply_to');

        $this->assertSame('normal', $case->refresh()->priority);
    }

    #[Test]
    public function a_reply_to_the_reporter_with_a_picture_is_refused(): void
    {
        $case = $this->report();

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/transactions", [
                'comment' => '![x](image:0b4d8a5e-8f1c-4a52-9a7c-6c2f4f0f0f0f)',
                'visibility' => 'public',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('comment');
    }

    #[Test]
    public function the_timeline_carries_what_a_reply_needs(): void
    {
        $case = $this->report();
        $this->actingAs($this->staff());

        $first = $this->postJson("/api/portal/cases/{$case->id}/comments", ['body' => 'First', 'visibility' => 'public'])->json('id');
        $this->postJson("/api/portal/cases/{$case->id}/transactions", [
            'comment' => 'Second', 'visibility' => 'public', 'reply_to' => $first,
        ])->assertOk();

        $comments = collect($this->getJson("/api/portal/cases/{$case->id}/timeline")->json('data'))
            ->where('kind', 'comment')
            ->values();

        $this->assertSame($first, $comments[0]['comment_id']);
        $this->assertSame($first, $comments[1]['reply_to']);
        $this->assertNotNull($comments[1]['actor_id']);
    }

    #[Test]
    public function a_reply_to_the_reporter_carries_what_it_quotes_to_the_wiki(): void
    {
        $case = $this->report();
        $case->forceFill(['wiki' => 'oasiswiki'])->save();
        $this->actingAs($this->staff());

        $theirs = app(CaseService::class)->comment(
            $case,
            'They did it **again** today.',
            CaseComment::VISIBILITY_PUBLIC,
            subject: $case->reporter,
        );

        $this->postJson("/api/portal/cases/{$case->id}/transactions", [
            'comment' => 'Thanks, we will look.',
            'visibility' => 'public',
            'reply_to' => $theirs->id,
        ])->assertOk();

        $event = OutboundEvent::query()
            ->where('event', OutboundEvent::COMMENT_ADD)
            ->get()
            ->first(fn (OutboundEvent $e) => ($e->payload['body'] ?? null) === 'Thanks, we will look.');

        $this->assertNotNull($event);
        $this->assertSame([
            'comment_id' => $theirs->id,
            'author' => 'you',
            'author_label' => 'Halcyon Reed',
            'body' => 'They did it **again** today.',
        ], $event->payload['reply_to']);
    }
}
