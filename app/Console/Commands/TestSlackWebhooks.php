<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PostToSlack;
use App\Services\Slack\SlackNotifier;
use Illuminate\Console\Command;
use Throwable;

class TestSlackWebhooks extends Command
{
    protected $signature = 'tsportal:slack-test
                            {--urgent : Include the mention, so the ping itself can be tested}';

    protected $description = 'Send a test message to each configured Slack webhook';

    public function handle(): int
    {
        if (! (bool) config('slack.enabled', false)) {
            $this->warn('Slack activity is off (SLACK_ACTIVITY_ENABLED). Nothing would be sent.');
            $this->line('Sending the test anyway, so the webhooks themselves can be checked.');
        }

        $hooks = array_filter(
            (array) config('slack.webhooks', []),
            fn ($url) => is_string($url) && $url !== '',
        );

        if ($hooks === []) {
            $this->error('No webhooks are configured. Set SLACK_WEBHOOK_URL at least.');

            return self::FAILURE;
        }

        $urgent = (bool) $this->option('urgent');
        $mention = trim((string) config('slack.mention', ''));

        if ($urgent && $mention === '') {
            $this->warn('No mention is configured (SLACK_THREAT_MENTION), so the urgent test will not ping.');
        }

        $this->newLine();
        $this->line('Routing, most specific first:');
        foreach ((array) config('slack.routes', []) as $action => $hook) {
            $resolved = SlackNotifier::webhookFor((string) $action.'.example');
            $this->line(sprintf(
                '  %-16s → %-8s %s',
                $action,
                $hook,
                $resolved === '' ? '(nothing configured — would not send)' : 'ok',
            ));
        }
        $this->newLine();

        $failed = 0;

        foreach ($hooks as $name => $url) {
            $this->line("Sending to '{$name}'…");

            try {
                (new PostToSlack($url, $this->message((string) $name, $urgent, $mention)))->handle();
                $this->info("  sent to '{$name}'.");
            } catch (Throwable $e) {
                $this->error("  '{$name}' failed: ".$e->getMessage());
                $failed++;
            }
        }

        $this->newLine();

        if ($failed > 0) {
            $this->error("{$failed} webhook(s) failed. Check the log for what Slack said.");

            return self::FAILURE;
        }

        $this->info('All configured webhooks accepted a message. Check the channels — an accepted');
        $this->info('message that lands in the wrong channel is still the wrong channel.');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function message(string $name, bool $urgent, string $mention): array
    {
        $prefix = $urgent && $mention !== '' ? $mention."\n" : '';

        return [
            'text' => sprintf('TSPortal test message for the "%s" webhook.', $name),
            'blocks' => [[
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $prefix.sprintf(
                        '%sTSPortal test — this is the *%s* webhook.%s',
                        $urgent ? ':rotating_light: ' : ':wrench: ',
                        $name,
                        $urgent
                            ? "\nA real message like this one means a report says somebody may be about to die."
                            : '',
                    ),
                ],
            ], [
                'type' => 'context',
                'elements' => [[
                    'type' => 'mrkdwn',
                    'text' => 'Sent by `php artisan tsportal:slack-test`. No case data is in this message.',
                ]],
            ]],
        ];
    }
}
