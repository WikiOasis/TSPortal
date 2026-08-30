<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DataRemoval;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\DataRemovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\SignsWikiRequests;
use Tests\TestCase;

class DataRemovalTest extends TestCase
{
    use RefreshDatabase;
    use SignsWikiRequests;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mediawiki.pii.enabled', true);
        config()->set('mediawiki.s2s.push_enabled', true);

        config()->set('mediawiki.supported_actions', [
            'lock', 'unlock', 'warn', 'note', 'rename', 'renamestatus', 'removepii',
        ]);
    }

    private function admin(string $name = 'Admin', int $id = 1): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => $id],
            ['username' => $name, 'flags' => ['ts', 'admin']],
        );
    }

    private function requested(?User $by = null): DataRemoval
    {
        return app(DataRemovalService::class)->request(
            Subject::forUsername('Ordinary Sandpiper', 45),
            ['reason' => 'Asked for erasure under Article 17.', 'legal_basis' => 'gdpr-17'],
            $by ?? $this->caseworker(),
            null,
            null,
            recordOnly: true,
        );
    }

    private function caseworker(): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => 9],
            ['username' => 'Caseworker', 'flags' => ['ts']],
        );
    }

    #[Test]
    public function anybody_on_the_team_erases_in_one_action(): void
    {
        Http::fake([
            '*' => Http::response(['wikioasissafetyenforce' => ['ok' => true, 'queued' => true]]),
        ]);

        $subject = Subject::forUsername('Ordinary Sandpiper', 45);

        $this->actingAs($this->caseworker())
            ->postJson("/api/portal/subjects/{$subject->id}/removals", [
                'reason' => 'Asked for erasure under Article 17.',
                'legal_basis' => 'gdpr-17',
            ])
            ->assertCreated()
            ->assertJsonPath('started', true)
            ->assertJsonPath('state', DataRemoval::STATE_APPROVED);

        Http::assertNothingSent();

        app(DataRemovalService::class)->advance(DataRemoval::query()->firstOrFail());

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'task=rename'));
        $this->assertSame(DataRemoval::STATE_RENAMING, DataRemoval::query()->firstOrFail()->state);
    }

    #[Test]
    public function it_can_be_written_down_without_being_started(): void
    {
        Http::fake();

        $subject = Subject::forUsername('Ordinary Sandpiper', 45);

        $this->actingAs($this->caseworker())
            ->postJson("/api/portal/subjects/{$subject->id}/removals", [
                'reason' => 'They asked; waiting on the address being confirmed.',
                'hold' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('started', false)
            ->assertJsonPath('state', DataRemoval::STATE_REQUESTED);

        Http::assertNothingSent();
    }

    #[Test]
    public function whoever_wrote_it_down_can_also_start_it(): void
    {
        Http::fake([
            '*' => Http::response(['wikioasissafetyenforce' => ['ok' => true]]),
        ]);

        $author = $this->caseworker();
        $removal = $this->requested($author);

        $this->actingAs($author)
            ->postJson("/api/portal/removals/{$removal->id}/erase")
            ->assertOk()
            ->assertJsonPath('state', DataRemoval::STATE_APPROVED);
    }

    #[Test]
    public function the_record_of_who_did_it_survives(): void
    {
        Http::fake(['*' => Http::response(['wikioasissafetyenforce' => ['ok' => true]])]);

        $author = $this->caseworker();
        $removal = $this->requested($author);

        app(DataRemovalService::class)->erase($removal, $author);

        $removal->refresh();

        $this->assertSame($author->id, $removal->requested_by);
        $this->assertSame($author->id, $removal->approved_by);
        $this->assertNotNull($removal->approved_at);
        $this->assertSame('Ordinary Sandpiper', $removal->previous_username);
    }

    #[Test]
    public function the_new_name_is_random_and_unguessable(): void
    {
        Http::fake();

        $first = DataRemoval::usernameFor();
        $second = DataRemoval::usernameFor();

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^WikiOasisGDPR_[0-9a-f]{48}$/', $first);
    }

    #[Test]
    public function the_scrub_only_follows_a_rename_that_finished(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['wikioasissafetyenforce' => ['ok' => true, 'queued' => true]])
                ->push(['wikioasissafetyenforce' => ['ok' => true, 'complete' => false]])
                ->push(['wikioasissafetyenforce' => ['ok' => true, 'complete' => true]])
                ->push(['wikioasissafetyenforce' => ['ok' => true, 'wikis' => ['oasisexamplewiki']]]),
        ]);

        $removal = $this->requested();
        $service = app(DataRemovalService::class);
        $service->erase($removal, $this->caseworker());

        $service->advance($removal->refresh());
        $this->assertSame(DataRemoval::STATE_RENAMING, $removal->refresh()->state);

        $service->advance($removal->refresh());
        $this->assertSame(DataRemoval::STATE_RENAMING, $removal->refresh()->state);

        $service->advance($removal->refresh());
        $this->assertSame(DataRemoval::STATE_SCRUBBING, $removal->refresh()->state);

        $subject = Subject::query()->whereKey($removal->subject_id)->sole();

        $this->assertSame($removal->target_username, $subject->wiki_username);
        $this->assertSame('Ordinary Sandpiper', $subject->username);
        $this->assertNotNull($subject->erased_at);

        $this->assertSame($removal->target_username, $subject->wikiName());
    }

    #[Test]
    public function an_erasure_leaves_the_portals_own_record_alone(): void
    {
        $subject = Subject::forUsername('Ordinary Sandpiper', 4242);
        $subject->forceFill(['email' => 'sandpiper@example.org', 'email_verified' => true])->save();

        $case = SafetyCase::create([
            'reference' => SafetyCase::nextReference('Report'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'Report concerning something',
            'summary' => 'What they told us.',
            'reporter_subject_id' => $subject->id,
            'wiki' => 'oasiswiki',
        ]);

        $subject->forceFill([
            'wiki_username' => 'WikiOasisGDPR_'.str_repeat('a', 48),
            'erased_at' => now(),
        ])->save();

        $subject->refresh();

        $this->assertSame('sandpiper@example.org', $subject->email);
        $this->assertTrue($subject->email_verified);
        $this->assertSame('Ordinary Sandpiper', $subject->username);
        $this->assertDatabaseHas('cases', ['reference' => $case->reference]);

        $found = Subject::forUsername($subject->wiki_username, 4242);

        $this->assertSame($subject->id, $found->id);
        $this->assertSame('Ordinary Sandpiper', $found->fresh()->username);
    }

    #[Test]
    public function retention_can_be_asked_what_is_now_due(): void
    {
        $recent = Subject::forUsername('Recently Erased', 5001);
        $recent->forceFill(['erased_at' => now()->subMonths(6)])->save();

        $old = Subject::forUsername('Long Erased', 5002);
        $old->forceFill(['erased_at' => now()->subYears(4)])->save();

        $due = Subject::query()->erasedBefore(now()->subYears(3))->pluck('username')->all();

        $this->assertSame(['Long Erased'], $due);

        $this->artisan('tsportal:retention')->assertExitCode(1);
        $this->artisan('tsportal:retention', ['--years' => 3])->assertExitCode(0);
    }

    #[Test]
    public function it_is_only_done_when_every_wiki_has_reported_back(): void
    {
        $removal = $this->requested();
        $removal->forceFill([
            'state' => DataRemoval::STATE_SCRUBBING,
            'approved_by' => $this->caseworker()->id,
            'approved_at' => now(),
            'result' => ['pending' => ['onewiki', 'twowiki'], 'finished' => []],
        ])->save();

        $service = app(DataRemovalService::class);

        $service->progress($removal, 'onewiki', true);
        $this->assertSame(DataRemoval::STATE_SCRUBBING, $removal->refresh()->state);

        $service->progress($removal, 'twowiki', true);
        $this->assertSame(DataRemoval::STATE_DONE, $removal->refresh()->state);
        $this->assertNotNull($removal->completed_at);
    }

    #[Test]
    public function a_wiki_reporting_twice_does_not_un_finish_anything(): void
    {
        $removal = $this->requested();
        $removal->forceFill([
            'state' => DataRemoval::STATE_SCRUBBING,
            'approved_by' => $this->caseworker()->id,
            'result' => ['pending' => ['onewiki'], 'finished' => []],
        ])->save();

        $service = app(DataRemovalService::class);
        $service->progress($removal, 'onewiki', true);
        $service->progress($removal->refresh(), 'onewiki', true);

        $this->assertSame(DataRemoval::STATE_DONE, $removal->refresh()->state);
    }

    #[Test]
    public function one_wiki_failing_fails_the_whole_erasure_visibly(): void
    {
        $removal = $this->requested();
        $removal->forceFill([
            'state' => DataRemoval::STATE_SCRUBBING,
            'approved_by' => $this->caseworker()->id,
            'result' => ['pending' => ['onewiki', 'twowiki']],
        ])->save();

        $service = app(DataRemovalService::class);
        $service->progress($removal, 'onewiki', true);
        $service->progress($removal->refresh(), 'twowiki', false, 'Database is read-only.');

        $removal->refresh();
        $this->assertSame(DataRemoval::STATE_FAILED, $removal->state);
        $this->assertStringContainsString('twowiki', (string) $removal->error);
    }

    #[Test]
    public function the_wiki_is_not_told_that_an_unstarted_rename_is_authorised(): void
    {
        $removal = $this->requested();

        $this->wikiGet("/api/wiki/v1/removals/{$removal->reference}?username=Ordinary%20Sandpiper")
            ->assertOk()
            ->assertJsonPath('match', false);
    }

    #[Test]
    public function an_approved_rename_is_confirmed_with_the_name_to_use(): void
    {
        Http::fake(['*' => Http::response(['wikioasissafetyenforce' => ['ok' => true]])]);

        $removal = $this->requested();
        app(DataRemovalService::class)->erase($removal, $this->caseworker());

        $this->wikiGet("/api/wiki/v1/removals/{$removal->reference}?username=ordinary_sandpiper")
            ->assertOk()
            ->assertJsonPath('match', true)
            ->assertJsonPath('newname', $removal->refresh()->target_username);
    }

    #[Test]
    public function an_unknown_reference_gets_the_same_answer_as_an_unstarted_one(): void
    {
        $this->wikiGet('/api/wiki/v1/removals/TS-1999-9999?username=Nobody')
            ->assertOk()
            ->assertExactJson(['match' => false]);
    }

    #[Test]
    public function only_one_erasure_can_be_in_flight_for_an_account(): void
    {
        Http::fake();

        $subject = Subject::forUsername('Ordinary Sandpiper', 45);
        $this->requested();

        $this->actingAs($this->admin())
            ->postJson("/api/portal/subjects/{$subject->id}/removals", [
                'reason' => 'Asked again.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'already-requested');
    }

    #[Test]
    public function finishing_answers_the_data_request_behind_it(): void
    {
        Http::fake(['*' => Http::response(['wikioasissafetyenforce' => ['ok' => true]])]);

        $subject = Subject::forUsername('Ordinary Sandpiper', 45);

        $case = SafetyCase::create([
            'reference' => SafetyCase::nextReference('Data request'),
            'type' => SafetyCase::TYPE_DATA,
            'subject_line' => 'Please delete my account',
            'status' => SafetyCase::STATUS_IN_REVIEW,
            'reporter_subject_id' => $subject->id,
        ]);

        $removal = app(DataRemovalService::class)->request(
            $subject,
            ['reason' => 'Article 17.'],
            $this->admin(),
            $case,
        );

        $removal->forceFill([
            'state' => DataRemoval::STATE_SCRUBBING,
            'approved_by' => $this->caseworker()->id,
            'result' => ['pending' => ['onewiki']],
        ])->save();

        app(DataRemovalService::class)->progress($removal, 'onewiki', true);

        $this->assertSame(SafetyCase::STATUS_ACTION_TAKEN, $case->refresh()->status);
    }

    #[Test]
    public function nothing_is_sent_when_erasure_is_switched_off(): void
    {
        config()->set('mediawiki.pii.enabled', false);
        Http::fake();

        $removal = $this->requested();
        $service = app(DataRemovalService::class);

        $service->erase($removal, $this->caseworker());
        $service->advance($removal->refresh());

        $removal->refresh();

        Http::assertNothingSent();
        $this->assertSame(DataRemoval::STATE_APPROVED, $removal->state);
        $this->assertStringContainsString('switched off', (string) $removal->error);
    }

    #[Test]
    public function a_wiki_that_is_still_working_is_waited_for_rather_than_failed(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['code' => 'internal', 'info' => 'The wiki is restarting.']])
                ->push(['wikioasissafetyenforce' => ['ok' => true, 'queued' => true]]),
        ]);

        $removal = $this->requested();
        $service = app(DataRemovalService::class);

        $service->erase($removal, $this->caseworker());
        $service->advance($removal->refresh());

        $removal->refresh();

        $this->assertSame(DataRemoval::STATE_APPROVED, $removal->state);
        $this->assertNull($removal->error);
        $this->assertStringContainsString('restarting', (string) $removal->last_problem);
        $this->assertTrue($removal->isWaiting());
    }

    #[Test]
    public function a_waiting_erasure_is_left_alone_until_its_backoff_expires(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['code' => 'internal', 'info' => 'Still going.']]),
        ]);

        $removal = $this->requested();
        $service = app(DataRemovalService::class);
        $service->erase($removal, $this->caseworker());
        $service->advance($removal->refresh());

        $asked = Http::recorded()->count();

        for ($i = 0; $i < 5; $i++) {
            $service->advance($removal->refresh());
        }

        $this->assertSame($asked, Http::recorded()->count());
        $this->assertSame(0, DataRemoval::query()->due()->count());
    }

    #[Test]
    public function it_goes_through_once_the_wiki_comes_back(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['code' => 'internal', 'info' => 'The wiki is restarting.']])
                ->push(['wikioasissafetyenforce' => ['ok' => true, 'queued' => true]]),
        ]);

        $removal = $this->requested();
        $service = app(DataRemovalService::class);
        $service->erase($removal, $this->caseworker());
        $service->advance($removal->refresh());

        $this->travel(2)->minutes();

        $service->advance($removal->refresh());

        $removal->refresh();
        $this->assertSame(DataRemoval::STATE_RENAMING, $removal->state);
        $this->assertNull($removal->last_problem);
    }

    #[Test]
    public function a_rename_still_running_does_not_fail_the_scrub(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['wikioasissafetyenforce' => ['ok' => true, 'queued' => true]])
                ->push(['wikioasissafetyenforce' => ['ok' => true, 'complete' => true]])
                ->push(['error' => ['code' => 'tasknotready', 'info' => 'The rename is still running.']]),
        ]);

        $removal = $this->requested();
        $service = app(DataRemovalService::class);
        $service->erase($removal, $this->caseworker());

        $service->advance($removal->refresh());
        $service->advance($removal->refresh());

        $removal->refresh();

        $this->assertSame(DataRemoval::STATE_RENAMED, $removal->state);
        $this->assertNull($removal->error);
        $this->assertStringContainsString('still running', (string) $removal->last_problem);
    }

    #[Test]
    public function a_considered_refusal_fails_straight_away(): void
    {
        Http::fake([
            '*' => Http::response(['error' => [
                'code' => 'taskfailed',
                'info' => 'CentralAuth is not installed, so an account cannot be renamed here.',
            ]]),
        ]);

        $removal = $this->requested();
        $service = app(DataRemovalService::class);
        $service->erase($removal, $this->caseworker());
        $service->advance($removal->refresh());

        $removal->refresh();

        $this->assertSame(DataRemoval::STATE_FAILED, $removal->state);
        $this->assertStringContainsString('CentralAuth is not installed', (string) $removal->error);
        $this->assertSame(1, Http::recorded()->count());
    }

    #[Test]
    public function patience_runs_out_eventually(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['code' => 'internal', 'info' => 'Still going.']]),
        ]);

        $removal = $this->requested();
        $service = app(DataRemovalService::class);
        $service->erase($removal, $this->caseworker());

        for ($i = 0; $i < 20; $i++) {
            $service->advance($removal->refresh());
            $this->travel(11)->minutes();
        }

        $removal->refresh();

        $this->assertSame(DataRemoval::STATE_FAILED, $removal->state);
        $this->assertStringContainsString('gave up after waiting', (string) $removal->error);
    }

    #[Test]
    public function trying_again_clears_the_waiting_and_the_attempt_count(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['code' => 'taskfailed', 'info' => 'Not installed.']]),
        ]);

        $removal = $this->requested();
        $service = app(DataRemovalService::class);
        $service->erase($removal, $this->caseworker());
        $service->advance($removal->refresh());

        $this->assertSame(DataRemoval::STATE_FAILED, $removal->refresh()->state);

        $before = Http::recorded()->count();

        $this->actingAs($this->caseworker())
            ->postJson("/api/portal/removals/{$removal->id}/retry")
            ->assertOk();

        $this->assertGreaterThan($before, Http::recorded()->count());
        $this->assertSame(0, $removal->refresh()->attemptsMade());
    }

    #[Test]
    public function the_list_renders(): void
    {
        Http::fake();

        $this->requested();

        $this->actingAs($this->caseworker())
            ->getJson('/api/portal/removals')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.account', 'Ordinary Sandpiper')
            ->assertJsonPath('data.0.state', DataRemoval::STATE_REQUESTED)
            ->assertJsonPath('data.0.may_erase', true)
            ->assertJsonStructure([
                'data' => [[
                    'id', 'reference', 'account', 'becomes', 'state', 'state_label',
                    'legal_basis', 'reason', 'requested_by', 'wikis' => ['pending', 'finished', 'failures'],
                    'may_erase',
                ]],
                'meta' => ['total', 'per_page', 'current_page', 'last_page'],
            ]);
    }

    #[Test]
    public function one_erasure_renders(): void
    {
        Http::fake();

        $removal = $this->requested();

        $this->actingAs($this->caseworker())
            ->getJson("/api/portal/removals/{$removal->id}")
            ->assertOk()
            ->assertJsonPath('reference', $removal->reference)
            ->assertJsonPath('becomes', $removal->target_username)
            ->assertJsonPath('may_erase', true);
    }

    #[Test]
    public function a_finished_erasure_offers_nothing_to_start(): void
    {
        Http::fake();

        $removal = $this->requested();
        $removal->forceFill(['state' => DataRemoval::STATE_DONE, 'completed_at' => now()])->save();

        $this->actingAs($this->caseworker())
            ->getJson("/api/portal/removals/{$removal->id}")
            ->assertOk()
            ->assertJsonPath('may_erase', false);
    }

    #[Test]
    public function the_list_is_staff_only(): void
    {
        $this->getJson('/api/portal/removals')->assertStatus(401);

        $outsider = User::create(['mw_central_id' => 99, 'username' => 'Newcomer', 'flags' => []]);
        $this->actingAs($outsider)->getJson('/api/portal/removals')->assertStatus(403);
    }

    #[Test]
    public function a_request_that_gave_up_is_not_driven_again_by_the_scheduler(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['code' => 'taskfailed', 'info' => 'Not installed.']]),
        ]);

        $removal = $this->requested();
        $service = app(DataRemovalService::class);
        $service->erase($removal, $this->caseworker());
        $service->advance($removal->refresh());

        $this->assertSame(DataRemoval::STATE_FAILED, $removal->refresh()->state);

        $callsSoFar = Http::recorded()->count();
        $alertsSoFar = AuditLog::query()->where('action', 'data-removal.failed')->count();

        for ($i = 0; $i < 5; $i++) {
            $this->artisan('tsportal:advance-removals');
            $this->travel(1)->minutes();
        }

        $this->assertSame(
            $callsSoFar,
            Http::recorded()->count(),
            'A request that gave up was sent to the wiki again by the scheduler.'
        );

        $this->assertSame(
            $alertsSoFar,
            AuditLog::query()->where('action', 'data-removal.failed')->count(),
            'A request that gave up announced itself again, which is what filled Slack.'
        );

        $this->assertSame(DataRemoval::STATE_FAILED, $removal->refresh()->state);
    }

    #[Test]
    public function trying_again_still_reaches_a_failed_request(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['code' => 'taskfailed', 'info' => 'Not installed.']]),
        ]);

        $removal = $this->requested();
        $service = app(DataRemovalService::class);
        $service->erase($removal, $this->caseworker());
        $service->advance($removal->refresh());

        $this->assertSame(DataRemoval::STATE_FAILED, $removal->refresh()->state);

        $quiet = Http::recorded()->count();

        $this->actingAs($this->caseworker())
            ->postJson("/api/portal/removals/{$removal->id}/retry")
            ->assertOk();

        $this->assertGreaterThan(
            $quiet,
            Http::recorded()->count(),
            'Try again no longer reaches a failed request.'
        );
    }
}
