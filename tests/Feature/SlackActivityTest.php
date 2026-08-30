<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SafetyCase;
use App\Models\User;
use App\Services\Safety\Audit;
use App\Services\Safety\CaseService;
use App\Services\Slack\SlackNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\SignsWikiRequests;
use Tests\TestCase;

class SlackActivityTest extends TestCase
{
    use RefreshDatabase;
    use SignsWikiRequests;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        config()->set('slack.enabled', true);
        config()->set('slack.queue', false);
        config()->set('slack.webhooks', [
            'default' => 'https://hooks.slack.test/default',
            'urgent' => 'https://hooks.slack.test/urgent',
            'cases' => 'https://hooks.slack.test/cases',
            'admin' => 'https://hooks.slack.test/admin',
        ]);
        config()->set('slack.mention', '<!subteam^SONCALL>');
        config()->set('slack.portal_url', 'https://ts.example.org');
        config()->set('categories.threat_to_life.categories', ['threat-to-life']);
    }

    /** @param list<array<string, mixed>> $categories */
    private function submit(array $categories = [], array $extra = []): SafetyCase
    {
        $this->wikiPost('/api/wiki/v1/submissions', [
            'type' => 'report',
            'wiki' => 'oasiswiki',
            'reporter' => ['central_id' => 7, 'username' => 'Halcyon Reed'],
            'answers' => ['report' => 'they keep following me home from work'],
            'summary' => 'They keep following me home from work.',
            'categories' => $categories,
        ] + $extra)->assertStatus(201);

        return SafetyCase::query()->latest('id')->firstOrFail();
    }

    private function sent(): array
    {
        $messages = [];

        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            $messages[] = ['url' => $request->url()] + $request->data();
        }

        return $messages;
    }

    private function texts(): string
    {
        return implode("\n", array_map(
            fn (array $m) => json_encode($m),
            $this->sent(),
        ));
    }

    #[Test]
    public function nothing_is_sent_when_it_is_switched_off(): void
    {
        config()->set('slack.enabled', false);

        $this->submit();

        Http::assertNothingSent();
    }

    #[Test]
    public function nothing_is_sent_when_no_webhook_is_configured(): void
    {
        config()->set('slack.webhooks', ['default' => '']);

        $this->submit();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_new_report_is_announced_to_the_case_channel(): void
    {
        $case = $this->submit([['id' => 'harassment', 'label' => 'Harassment']]);

        $messages = $this->sent();
        $this->assertNotEmpty($messages);

        $created = collect($messages)->first(fn (array $m) => str_contains($m['text'] ?? '', 'New report'));
        $this->assertNotNull($created, 'the report was not announced');

        $this->assertSame('https://hooks.slack.test/cases', $created['url']);
        $this->assertStringContainsString($case->reference, $created['text']);
    }

    #[Test]
    public function a_threat_to_life_report_goes_to_its_own_channel_with_the_mention(): void
    {
        $this->submit([['id' => 'threat-to-life', 'label' => 'Threat to life']]);

        $urgent = collect($this->sent())
            ->first(fn (array $m) => $m['url'] === 'https://hooks.slack.test/urgent');

        $this->assertNotNull($urgent, 'nothing reached the urgent webhook');

        $this->assertStringContainsString('<!subteam^SONCALL>', $urgent['text']);
        $this->assertStringContainsString('THREAT TO LIFE', $urgent['text']);
    }

    #[Test]
    public function an_ordinary_report_carries_no_mention(): void
    {
        $this->submit([['id' => 'harassment', 'label' => 'Harassment']]);

        $this->assertStringNotContainsString('<!subteam^SONCALL>', $this->texts());
    }

    #[Test]
    public function no_case_content_reaches_slack(): void
    {
        $this->submit([['id' => 'harassment', 'label' => 'Harassment']]);

        $all = $this->texts();

        $this->assertStringNotContainsString('following me home', $all);
        $this->assertStringNotContainsString('Halcyon Reed', $all);
    }

    #[Test]
    public function the_message_links_into_the_portal(): void
    {
        $case = $this->submit();

        $created = collect($this->sent())->first(fn (array $m) => str_contains($m['text'] ?? '', 'New report'));

        $button = collect($created['blocks'])
            ->first(fn (array $b) => ($b['type'] ?? '') === 'actions');

        $this->assertNotNull($button);
        $this->assertSame(
            'https://ts.example.org/item/'.$case->reference,
            $button['elements'][0]['url'],
        );
    }

    #[Test]
    public function noisy_events_are_left_out(): void
    {
        config()->set('slack.ignore', ['auth.login', 'case']);

        $this->submit();

        $this->assertStringNotContainsString('New report', $this->texts());
    }

    #[Test]
    public function an_action_nobody_has_routed_still_turns_up(): void
    {
        Audit::log('somethingnew.happened', null, ['reference' => 'TS-2026-0001']);

        $messages = $this->sent();

        $this->assertNotEmpty($messages);
        $this->assertSame('https://hooks.slack.test/default', $messages[0]['url']);
        $this->assertStringContainsString('Somethingnew happened', $messages[0]['text']);
    }

    #[Test]
    public function a_family_routes_to_its_channel(): void
    {
        $this->assertSame('https://hooks.slack.test/cases', SlackNotifier::webhookFor('comment.added'));
        $this->assertSame('https://hooks.slack.test/admin', SlackNotifier::webhookFor('transparency.published'));
        $this->assertSame('https://hooks.slack.test/default', SlackNotifier::webhookFor('sanction.issued'));
    }

    #[Test]
    public function an_urgent_event_overrides_its_familys_channel(): void
    {
        $this->assertSame(
            'https://hooks.slack.test/urgent',
            SlackNotifier::webhookFor('case.status', urgent: true),
        );
    }

    #[Test]
    public function a_status_change_on_a_threat_to_life_case_still_pings(): void
    {
        $case = $this->submit([['id' => 'threat-to-life', 'label' => 'Threat to life']]);

        Http::fake();

        app(CaseService::class)
            ->setStatus($case, SafetyCase::STATUS_IN_REVIEW);

        $urgent = collect($this->sent())
            ->first(fn (array $m) => $m['url'] === 'https://hooks.slack.test/urgent');

        $this->assertNotNull($urgent, 'a threat-to-life case went quiet after it was filed');
    }

    #[Test]
    public function slack_being_broken_does_not_break_the_report(): void
    {
        Http::fake(fn () => Http::response('no_service', 404));

        $case = $this->submit();

        $this->assertNotNull($case->reference);
        $this->assertDatabaseHas('cases', ['reference' => $case->reference]);
    }

    #[Test]
    public function the_webhook_url_is_never_logged(): void
    {
        $source = (string) file_get_contents(base_path('app/Jobs/PostToSlack.php'));
        $logCall = substr($source, (int) strpos($source, 'Log::warning'));
        $logCall = substr($logCall, 0, (int) strpos($logCall, ']);'));

        $this->assertStringNotContainsString('webhook', $logCall);
    }
}
