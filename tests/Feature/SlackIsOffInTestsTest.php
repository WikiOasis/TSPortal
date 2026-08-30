<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SafetyCase;
use App\Models\Subject;
use App\Services\Safety\CaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SlackIsOffInTestsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_suite_runs_with_slack_switched_off_and_no_webhooks(): void
    {
        $this->assertFalse(
            (bool) config('slack.enabled'),
            'Slack activity is enabled under the test environment. Every audit line the suite '
            .'writes will be posted to whatever webhooks the running developer has in .env. '
            .'Set SLACK_ACTIVITY_ENABLED=false in phpunit.xml.'
        );

        foreach ((array) config('slack.webhooks', []) as $name => $url) {
            $this->assertSame(
                '',
                (string) $url,
                "The '{$name}' Slack webhook has a real URL under the test environment. Blank it "
                .'in phpunit.xml: a test that switches slack.enabled back on for its own purposes '
                .'must have nowhere to post if it forgets to substitute its own.'
            );
        }
    }

    #[Test]
    public function filing_a_case_reaches_no_webhook(): void
    {
        Http::fake();

        app(CaseService::class)->createFromSubmission([
            'type' => SafetyCase::TYPE_REPORT,
            'reporter' => ['username' => Subject::forUsername('Halcyon Reed', 42)->username],
            'summary' => 'Somebody is following me home.',
        ]);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'hooks.slack.com'));
    }
}
