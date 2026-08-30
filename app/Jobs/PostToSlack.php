<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PostToSlack implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    /**
     * @param  string  $webhook
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $webhook,
        private readonly array $payload,
    ) {}

    public function handle(): void
    {
        if ($this->webhook === '') {
            return;
        }

        $response = Http::asJson()
            ->timeout(max(1, (int) config('slack.timeout', 5)))
            ->post($this->webhook, $this->payload);

        if ($response->successful()) {
            return;
        }

        Log::warning('Slack rejected an activity message.', [
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 200),
        ]);

        if ($response->serverError()) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
