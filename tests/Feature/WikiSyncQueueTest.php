<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncToWiki;
use App\Models\OutboundEvent;
use App\Models\Subject;
use App\Models\User;
use App\Services\MediaWiki\OutboundEventDrainer;
use App\Services\Safety\InvestigationService;
use App\Services\Safety\SanctionService;
use App\Services\Safety\WikiSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WikiSyncQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mediawiki.s2s.push_enabled', true);
    }

    private function subject(): Subject
    {
        return Subject::forUsername('Halcyon Reed', 42);
    }

    private function event(): OutboundEvent
    {
        return OutboundEvent::create([
            'event' => OutboundEvent::NOTIFY,
            'payload' => ['kind' => 'standing', 'central_id' => 42, 'notify' => false],
        ]);
    }

    #[Test]
    public function writing_an_outbound_event_queues_a_sync(): void
    {
        Queue::fake();

        app(WikiSync::class)->pushStanding($this->subject());

        Queue::assertPushed(SyncToWiki::class);
    }

    #[Test]
    public function taking_an_action_queues_a_sync(): void
    {
        Queue::fake();

        $user = User::query()->create(['mw_central_id' => 1, 'username' => 'Admin', 'flags' => ['ts', 'admin']]);

        app(SanctionService::class)->issue($this->subject(), [
            'type' => 'lock',
            'reason' => 'Threats.',
        ], $user, app(InvestigationService::class)->open(
            ['title' => 'Test file', 'premise' => 'Opened by the suite.'],
            $user,
        ));

        Queue::assertPushed(SyncToWiki::class);
    }

    #[Test]
    public function the_job_delivers_what_is_due(): void
    {
        Http::fake([
            '*' => Http::response(['wikioasissafetysync' => ['result' => 'ok']]),
        ]);

        $event = $this->event();

        Queue::fake();
        (new SyncToWiki)->handle(app(OutboundEventDrainer::class));

        $this->assertNotNull($event->refresh()->delivered_at);

        Queue::assertNotPushed(SyncToWiki::class);
    }

    #[Test]
    public function a_failure_books_the_next_pass_itself(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $event = $this->event();

        Queue::fake();
        (new SyncToWiki)->handle(app(OutboundEventDrainer::class));

        $event->refresh();
        $this->assertNull($event->delivered_at);
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->next_attempt_at);

        Queue::assertPushed(SyncToWiki::class);
    }

    #[Test]
    public function an_event_that_has_given_up_does_not_book_anything(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $event = $this->event();
        $event->forceFill(['attempts' => 11, 'next_attempt_at' => now()->subMinute()])->save();

        Queue::fake();
        (new SyncToWiki)->handle(app(OutboundEventDrainer::class));

        $event->refresh();
        $this->assertSame(12, $event->attempts);
        $this->assertNull($event->next_attempt_at);

        Queue::fake();
        (new SyncToWiki)->handle(app(OutboundEventDrainer::class));
        Queue::assertNotPushed(SyncToWiki::class);
    }

    #[Test]
    public function nothing_is_sent_or_booked_while_pushing_is_off(): void
    {
        config()->set('mediawiki.s2s.push_enabled', false);
        Http::fake();

        $event = $this->event();

        Queue::fake();
        (new SyncToWiki)->handle(app(OutboundEventDrainer::class));

        $this->assertNull($event->refresh()->delivered_at);
        Queue::assertNotPushed(SyncToWiki::class);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_command_still_drains_by_hand(): void
    {
        Http::fake([
            '*' => Http::response(['wikioasissafetysync' => ['result' => 'ok']]),
        ]);

        $event = $this->event();

        $this->artisan('tsportal:sync')
            ->expectsOutputToContain('Sent 1, failed 0.')
            ->assertSuccessful();

        $this->assertNotNull($event->refresh()->delivered_at);
    }

    #[Test]
    public function retry_stuck_puts_a_given_up_event_back_in_the_queue(): void
    {
        Http::fake([
            '*' => Http::response(['wikioasissafetysync' => ['result' => 'ok']]),
        ]);

        $event = $this->event();
        $event->forceFill(['attempts' => 12, 'next_attempt_at' => null])->save();

        $this->artisan('tsportal:sync', ['--retry-stuck' => true])->assertSuccessful();

        $this->assertNotNull($event->refresh()->delivered_at);
    }
}
