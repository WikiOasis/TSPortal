<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\SanctionIssuedMail;
use App\Models\Investigation;
use App\Models\OutboundEvent;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use App\Services\MediaWiki\WikiClient;
use App\Services\Safety\InvestigationService;
use App\Services\Safety\SanctionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\SignsWikiRequests;
use Tests\TestCase;

class SanctionTest extends TestCase
{
    use RefreshDatabase;
    use SignsWikiRequests;

    private function staff(array $flags = ['ts', 'admin']): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => 1],
            ['username' => 'Admin', 'flags' => $flags],
        );
    }

    private function file(?SafetyCase $from = null): Investigation
    {
        return app(InvestigationService::class)->open(
            ['title' => 'Test file', 'premise' => 'Opened by the suite.'],
            $this->staff(),
            $from,
        );
    }

    #[Test]
    public function a_lock_suspends_the_account(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);

        app(SanctionService::class)->issue($subject, [
            'type' => Sanction::TYPE_LOCK,
            'reason' => 'Threats after a final warning.',
        ], $this->staff(), $this->file());

        $subject->refresh();

        $this->assertTrue($subject->banned);
        $this->assertSame(Subject::STANDING_SUSPENDED, $subject->standing);
    }

    #[Test]
    public function a_warning_restricts_without_suspending(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);

        app(SanctionService::class)->issue($subject, [
            'type' => Sanction::TYPE_WARNING,
            'reason' => 'Edit summaries directed at another contributor.',
        ], $this->staff(), $this->file());

        $subject->refresh();

        $this->assertFalse($subject->banned);
        $this->assertSame(Subject::STANDING_RESTRICTED, $subject->standing);
    }

    #[Test]
    public function an_action_the_extension_cannot_do_is_recorded_and_flagged(): void
    {
        config()->set('mediawiki.s2s.push_enabled', true);
        config()->set('mediawiki.supported_actions', ['lock', 'unlock']);

        $sanction = app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            [
                'type' => Sanction::TYPE_BLOCK,
                'reason' => 'Continued personal comments after being asked to stop.',
                'wikis' => ['oasisexamplewiki'],
            ],
            $this->staff(),
            $this->file(),
        );

        $this->assertSame(Sanction::PUSH_MANUAL, $sanction->push_state);
        $this->assertStringContainsString('by hand', $sanction->push_error);
        $this->assertTrue($sanction->active);
    }

    #[Test]
    public function issuing_queues_the_wiki_a_mirror_update(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);

        app(SanctionService::class)->issue($subject, [
            'type' => Sanction::TYPE_LOCK,
            'reason' => 'Threats.',
            'internal_reason' => 'See the off-wiki log; do not repeat this to them.',
        ], $this->staff(), $this->file());

        $event = OutboundEvent::query()->where('event', OutboundEvent::SANCTION_UPSERT)->firstOrFail();

        $this->assertSame('Threats.', $event->payload['reason']);
        $this->assertTrue($event->payload['banned']);

        $this->assertStringNotContainsString('off-wiki log', json_encode($event->payload));
    }

    #[Test]
    public function lifting_withdraws_without_deleting(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $service = app(SanctionService::class);

        $sanction = $service->issue($subject, [
            'type' => Sanction::TYPE_LOCK, 'reason' => 'Threats.',
        ], $this->staff(), $this->file());

        $service->lift($sanction, $this->staff(), 'Appeal accepted; the evidence was mistaken.');

        $sanction->refresh();
        $subject->refresh();

        $this->assertFalse($sanction->active);
        $this->assertNotNull($sanction->lifted_at);
        $this->assertSame('Appeal accepted; the evidence was mistaken.', $sanction->lift_reason);

        $this->assertDatabaseHas('sanctions', ['id' => $sanction->id]);
        $this->assertFalse($subject->banned);
        $this->assertSame(Subject::STANDING_GOOD, $subject->standing);
    }

    #[Test]
    public function an_expired_ban_stops_applying_on_its_own(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);

        $sanction = app(SanctionService::class)->issue($subject, [
            'type' => Sanction::TYPE_LOCK, 'reason' => 'Threats.',
        ], $this->staff(), $this->file());

        $sanction->forceFill(['expires_at' => now()->subHour()])->save();

        $this->assertSame(1, app(SanctionService::class)->expireDue());

        $subject->refresh();

        $this->assertFalse($subject->banned);
        $this->assertSame(Subject::STANDING_GOOD, $subject->standing);
        $this->assertFalse($sanction->fresh()->active);
    }

    #[Test]
    public function an_action_with_no_end_date_has_not_expired(): void
    {
        $sanction = app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            ['type' => Sanction::TYPE_WARNING, 'reason' => 'A warning.'],
            $this->staff(),
            $this->file(),
        );

        $this->assertFalse($sanction->hasExpired());
        $this->assertTrue($sanction->isInForce());
        $this->assertSame(0, app(SanctionService::class)->expireDue());
    }

    #[Test]
    public function an_action_taken_on_a_case_tells_the_reporter(): void
    {
        $reporter = Subject::forUsername('Halcyon Reed', 42);
        $reported = Subject::forUsername('Bright Kettle Media', 43);

        $case = SafetyCase::create([
            'reference' => 'TS-2026-0001', 'type' => 'report',
            'subject_line' => 'Spam links', 'reporter_subject_id' => $reporter->id,
        ]);

        app(SanctionService::class)->issue($reported, [
            'type' => Sanction::TYPE_LOCK, 'reason' => 'Spam.',
        ], $this->staff(), $this->file($case), $case);

        $public = $case->comments()->where('visibility', 'public')->orderBy('id')->pluck('body');

        $this->assertTrue(
            $public->contains(fn (string $body) => str_contains($body, 'Action taken')),
            'The action itself is not on the case.',
        );
        $this->assertTrue(
            $public->contains(fn (string $body) => str_contains($body, 'Trust & Safety')),
            'The status change is not on the reader’s history.',
        );
    }

    #[Test]
    public function the_account_is_written_to_when_the_portal_has_an_address(): void
    {
        Mail::fake();

        $subject = Subject::forUsername('Halcyon Reed', 42);
        $subject->forceFill(['email' => 'halcyon@example.org'])->save();

        app(SanctionService::class)->issue($subject, [
            'type' => Sanction::TYPE_LOCK, 'reason' => 'Threats.',
        ], $this->staff(), $this->file());

        Mail::assertQueued(SanctionIssuedMail::class);
    }

    #[Test]
    public function nothing_is_emailed_when_no_address_is_known(): void
    {
        Mail::fake();

        app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            ['type' => Sanction::TYPE_LOCK, 'reason' => 'Threats.'],
            $this->staff(),
            $this->file(),
        );

        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_block_has_to_name_at_least_one_wiki(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            ['type' => Sanction::TYPE_BLOCK, 'reason' => 'Edit warring.'],
            $this->staff(),
            $this->file(),
        );
    }

    #[Test]
    public function a_block_says_where_it_applies_from_its_wiki_list(): void
    {
        $sanction = app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            [
                'type' => Sanction::TYPE_BLOCK,
                'reason' => 'Edit warring.',
                'wikis' => ['onewiki', 'twowiki'],
            ],
            $this->staff(),
            $this->file(),
        );

        $this->assertSame(['onewiki', 'twowiki'], $sanction->wikis);
        $this->assertSame('onewiki, twowiki', $sanction->whereItApplies());
    }

    #[Test]
    public function deleting_a_wiki_needs_no_account_and_is_never_mirrored(): void
    {
        $sanction = app(SanctionService::class)->issue(
            null,
            [
                'type' => Sanction::TYPE_WIKI_DELETION,
                'reason' => 'Hosting material we have been ordered to remove.',
                'wikis' => ['badwiki'],
            ],
            $this->staff(),
            $this->file(),
        );

        $this->assertNull($sanction->subject_id);
        $this->assertSame(['badwiki'], $sanction->wikis);
        $this->assertSame(0, OutboundEvent::query()->where('event', OutboundEvent::SANCTION_UPSERT)->count());
    }

    #[Test]
    public function an_action_about_an_account_still_needs_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SanctionService::class)->issue(
            null,
            ['type' => Sanction::TYPE_WARNING, 'reason' => 'A warning.'],
            $this->staff(),
            $this->file(),
        );
    }

    #[Test]
    public function deleting_a_wiki_needs_the_admin_flag(): void
    {
        $file = $this->file();

        $reader = User::create(['mw_central_id' => 7, 'username' => 'Reader', 'flags' => ['ts']]);

        $this->actingAs($reader)
            ->postJson('/api/portal/actions', [
                'type' => Sanction::TYPE_WIKI_DELETION,
                'reason' => 'No.',
                'wikis' => ['badwiki'],
                'investigation_reference' => $file->reference,
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function the_two_retired_types_can_no_longer_be_issued(): void
    {
        foreach (['iban', 'partial-block'] as $retired) {
            $this->actingAs($this->staff())
                ->postJson('/api/portal/subjects/'.Subject::forUsername('Halcyon Reed', 42)->id.'/sanctions', [
                    'type' => $retired,
                    'reason' => 'No.',
                    'investigation_reference' => $this->file()->reference,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('type');
        }

        $this->assertSame('Interaction ban', Sanction::LABELS['iban']);
    }

    #[Test]
    public function a_logged_action_says_what_was_done_and_is_not_work_waiting(): void
    {
        $sanction = app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            [
                'type' => Sanction::TYPE_OTHER,
                'label' => 'Removal of content',
                'reason' => 'Three pages of copied material taken down.',
            ],
            $this->staff(),
            $this->file(),
        );

        $this->assertSame('Removal of content', $sanction->label);

        $this->assertSame(Sanction::PUSH_RECORDED, $sanction->push_state);
        $this->assertNull($sanction->push_error);
    }

    #[Test]
    public function a_logged_action_has_to_say_what_it_was(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            ['type' => Sanction::TYPE_OTHER, 'reason' => 'Something happened.'],
            $this->staff(),
            $this->file(),
        );
    }

    #[Test]
    public function a_note_is_recorded_rather_than_pushed(): void
    {
        config()->set('mediawiki.s2s.push_enabled', true);

        $sanction = app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            ['type' => Sanction::TYPE_NOTE, 'reason' => 'Context for next time.'],
            $this->staff(),
            $this->file(),
        );

        $this->assertSame(Sanction::PUSH_RECORDED, $sanction->push_state);
    }

    #[Test]
    public function deleting_a_wiki_is_undone_by_undeleting_it(): void
    {
        config()->set('mediawiki.s2s.push_enabled', true);
        config()->set('mediawiki.supported_actions', ['delete-wiki', 'undelete-wiki']);

        Http::fake([
            '*' => Http::response(['wikioasissafetyenforce' => ['ok' => true]]),
        ]);

        $sanction = app(SanctionService::class)->issue(
            null,
            [
                'type' => Sanction::TYPE_WIKI_DELETION,
                'reason' => 'Ordered to remove it.',
                'wikis' => ['badwiki'],
            ],
            $this->staff(),
            $this->file(),
        );

        app(SanctionService::class)->lift($sanction, $this->staff(), 'The order was withdrawn.');

        Http::assertSent(
            fn ($request) => str_contains($request->body(), 'task=undelete-wiki')
        );
    }

    #[Test]
    public function a_block_reaches_the_wiki(): void
    {
        config()->set('mediawiki.s2s.push_enabled', true);
        config()->set('mediawiki.supported_actions', WikiClient::TASKS);

        Http::fake([
            '*' => Http::response(['wikioasissafetyenforce' => [
                'ok' => true,
                'wikis' => ['onewiki', 'twowiki'],
            ]]),
        ]);

        $sanction = app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            [
                'type' => Sanction::TYPE_BLOCK,
                'reason' => 'Edit warring after a warning.',
                'wikis' => ['onewiki', 'twowiki'],
            ],
            $this->staff(),
            $this->file(),
        );

        Http::assertSent(fn ($request) => str_contains($request->body(), 'task=block')
            && str_contains(urldecode($request->body()), 'wikis=onewiki|twowiki'));

        $this->assertSame(Sanction::PUSH_QUEUED, $sanction->fresh()->push_state);
    }

    #[Test]
    public function a_configured_action_that_is_not_a_task_is_reported_not_ignored(): void
    {
        config()->set('mediawiki.supported_actions', ['lock', 'warning', 'wiki-deletion']);

        $this->assertSame(['warning', 'wiki-deletion'], WikiClient::unknownTasks());
        $this->assertSame(['lock'], WikiClient::tasks());

        $this->actingAs($this->staff())
            ->getJson('/api/portal/dashboard')
            ->assertOk()
            ->assertJsonPath('wiki.unknown_actions', ['warning', 'wiki-deletion']);
    }

    #[Test]
    public function a_block_is_only_done_when_every_wiki_has_reported(): void
    {
        config()->set('mediawiki.s2s.push_enabled', true);
        config()->set('mediawiki.supported_actions', WikiClient::TASKS);

        Http::fake([
            '*' => Http::response(['wikioasissafetyenforce' => [
                'ok' => true,
                'wikis' => ['onewiki', 'twowiki'],
            ]]),
        ]);

        $sanction = app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            ['type' => Sanction::TYPE_BLOCK, 'reason' => 'Edit warring.', 'wikis' => ['onewiki', 'twowiki']],
            $this->staff(),
            $this->file(),
        );

        $service = app(SanctionService::class);

        $service->progress($sanction, 'onewiki', true);
        $this->assertSame(Sanction::PUSH_QUEUED, $sanction->fresh()->push_state);

        $service->progress($sanction->fresh(), 'twowiki', true);
        $this->assertSame(Sanction::PUSH_PUSHED, $sanction->fresh()->push_state);
    }

    #[Test]
    public function a_block_that_fails_on_one_wiki_is_partial_and_says_where(): void
    {
        config()->set('mediawiki.s2s.push_enabled', true);
        config()->set('mediawiki.supported_actions', WikiClient::TASKS);

        Http::fake([
            '*' => Http::response(['wikioasissafetyenforce' => [
                'ok' => true,
                'wikis' => ['onewiki', 'twowiki'],
            ]]),
        ]);

        $sanction = app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            ['type' => Sanction::TYPE_BLOCK, 'reason' => 'Edit warring.', 'wikis' => ['onewiki', 'twowiki']],
            $this->staff(),
            $this->file(),
        );

        $service = app(SanctionService::class);
        $service->progress($sanction, 'onewiki', true);
        $service->progress($sanction->fresh(), 'twowiki', false, 'Database is read-only.');

        $sanction->refresh();

        $this->assertSame(Sanction::PUSH_PARTIAL, $sanction->push_state);
        $this->assertStringContainsString('twowiki', (string) $sanction->push_error);
        $this->assertStringContainsString('onewiki', (string) $sanction->push_error);
    }

    #[Test]
    public function the_wiki_reports_a_block_through_the_signed_api(): void
    {
        config()->set('mediawiki.s2s.push_enabled', true);
        config()->set('mediawiki.supported_actions', WikiClient::TASKS);

        Http::fake([
            '*' => Http::response(['wikioasissafetyenforce' => ['ok' => true, 'wikis' => ['onewiki']]]),
        ]);

        $sanction = app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            ['type' => Sanction::TYPE_BLOCK, 'reason' => 'Edit warring.', 'wikis' => ['onewiki']],
            $this->staff(),
            $this->file(),
        );

        $this->wikiPost("/api/wiki/v1/actions/{$sanction->reference}/progress", [
            'wiki' => 'onewiki',
            'ok' => true,
        ])->assertOk()->assertJsonPath('state', Sanction::PUSH_PUSHED);
    }

    #[Test]
    public function a_repeat_report_after_it_has_settled_is_accepted_and_ignored(): void
    {
        config()->set('mediawiki.s2s.push_enabled', true);
        config()->set('mediawiki.supported_actions', WikiClient::TASKS);

        Http::fake([
            '*' => Http::response(['wikioasissafetyenforce' => ['ok' => true, 'wikis' => ['onewiki']]]),
        ]);

        $sanction = app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            ['type' => Sanction::TYPE_BLOCK, 'reason' => 'Edit warring.', 'wikis' => ['onewiki']],
            $this->staff(),
            $this->file(),
        );

        app(SanctionService::class)->progress($sanction, 'onewiki', true);

        $this->wikiPost("/api/wiki/v1/actions/{$sanction->reference}/progress", [
            'wiki' => 'onewiki',
            'ok' => true,
        ])->assertOk()->assertJsonPath('ignored', true);

        $this->assertSame(Sanction::PUSH_PUSHED, $sanction->fresh()->push_state);
    }

    #[Test]
    public function a_block_the_wiki_could_not_queue_anywhere_is_complete_not_pending(): void
    {
        config()->set('mediawiki.s2s.push_enabled', true);
        config()->set('mediawiki.supported_actions', WikiClient::TASKS);

        Http::fake([
            '*' => Http::response(['wikioasissafetyenforce' => ['ok' => true, 'wikis' => []]]),
        ]);

        $sanction = app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            ['type' => Sanction::TYPE_BLOCK, 'reason' => 'Edit warring.', 'wikis' => ['onewiki']],
            $this->staff(),
            $this->file(),
        );

        $this->assertSame(Sanction::PUSH_PUSHED, $sanction->fresh()->push_state);
    }

    #[Test]
    public function a_failed_push_can_be_marked_as_done_by_hand(): void
    {
        $sanction = app(SanctionService::class)->issue(
            null,
            ['type' => Sanction::TYPE_WIKI_DELETION, 'reason' => 'Ordered to remove it.', 'wikis' => ['badwiki']],
            $staff = $this->staff(),
            $this->file(),
        );

        $this->assertSame(Sanction::PUSH_MANUAL, $sanction->fresh()->push_state);

        $this->actingAs($staff)
            ->postJson("/api/portal/sanctions/{$sanction->id}/acknowledge", [
                'note' => 'Deleted it from the farm control panel directly.',
            ])
            ->assertOk()
            ->assertJsonPath('push_state', Sanction::PUSH_ACKNOWLEDGED);

        $sanction->refresh();
        $this->assertSame(Sanction::PUSH_ACKNOWLEDGED, $sanction->push_state);
        $this->assertSame($staff->id, $sanction->acknowledged_by);
        $this->assertNotNull($sanction->acknowledged_at);
        $this->assertSame('Deleted it from the farm control panel directly.', $sanction->acknowledgement_note);
    }

    #[Test]
    public function an_action_already_on_the_wiki_cannot_be_marked_as_done_by_hand(): void
    {
        config()->set('mediawiki.s2s.push_enabled', true);

        $sanction = app(SanctionService::class)->issue(
            Subject::forUsername('Halcyon Reed', 42),
            ['type' => Sanction::TYPE_NOTE, 'reason' => 'Context for next time.'],
            $staff = $this->staff(),
            $this->file(),
        );

        $this->assertSame(Sanction::PUSH_RECORDED, $sanction->fresh()->push_state);

        $this->actingAs($staff)
            ->postJson("/api/portal/sanctions/{$sanction->id}/acknowledge")
            ->assertStatus(422)
            ->assertJsonPath('error', 'not-acknowledgeable');

        $this->assertSame(Sanction::PUSH_RECORDED, $sanction->fresh()->push_state);
    }

    #[Test]
    public function an_event_that_gave_up_is_not_retried_by_the_drainer(): void
    {
        $event = OutboundEvent::create([
            'event' => OutboundEvent::CASE_UPSERT,
            'wiki' => 'oasis.example',
            'payload' => ['reference' => 'TS-2026-0001'],
        ]);

        $this->assertTrue(OutboundEvent::query()->due()->whereKey($event->id)->exists());

        for ($i = 0; $i < 12; $i++) {
            $event->backOff('The wiki is not answering.');
        }

        $this->assertTrue($event->refresh()->isStuck(), 'twelve attempts is the giving-up point');
        $this->assertNull($event->next_attempt_at);

        $this->assertFalse(
            OutboundEvent::query()->due()->whereKey($event->id)->exists(),
            'An event that gave up is still being offered to the drainer, so it will be '
            .'retried every minute for ever.'
        );

        $event->forceFill(['attempts' => 0])->save();

        $this->assertTrue(
            OutboundEvent::query()->due()->whereKey($event->id)->exists(),
            'Trying again by hand no longer reaches a stuck event.'
        );
    }
}
