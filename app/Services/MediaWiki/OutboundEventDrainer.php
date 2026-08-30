<?php

declare(strict_types=1);

namespace App\Services\MediaWiki;

use App\Models\CaseComment;
use App\Models\OutboundEvent;
use App\Models\SafetyCase;
use Throwable;

final class OutboundEventDrainer
{
    /**
     * @return int
     */
    public function revive(): int
    {
        return OutboundEvent::query()
            ->whereNull('delivered_at')
            ->whereNull('next_attempt_at')
            ->where('attempts', '>', 0)
            ->update(['attempts' => 0, 'next_attempt_at' => null]);
    }

    public function enabled(): bool
    {
        return WikiClient::make()->enabled();
    }

    public function drain(int $limit = 200): DrainReport
    {
        $client = WikiClient::make();

        if (! $client->enabled()) {
            return DrainReport::disabled();
        }

        $events = OutboundEvent::query()->due()->limit(max(1, $limit))->get();

        if ($events->isEmpty()) {
            return new DrainReport;
        }

        $sent = 0;
        $failed = 0;
        $halted = false;
        $problems = [];

        foreach ($events as $event) {
            try {
                $client->deliver($event);
                $event->markDelivered();
                $this->markSource($event);
                $sent++;
            } catch (Throwable $e) {
                $event->backOff($e->getMessage());
                $this->recordSourceError($event, $e->getMessage());
                $failed++;

                $problems[] = sprintf(
                    'Event #%d (%s) failed on attempt %d: %s',
                    $event->id,
                    $event->event,
                    $event->attempts,
                    $e->getMessage(),
                );

                if ($failed >= 3) {
                    $halted = true;
                    $problems[] = 'Three failures in a row — leaving the rest for the next pass.';
                    break;
                }
            }
        }

        $halted = $halted || $events->count() >= $limit;

        return new DrainReport($sent, $failed, $halted, $problems);
    }

    private function markSource(OutboundEvent $event): void
    {
        $reference = $event->payload['reference'] ?? null;

        match ($event->event) {
            OutboundEvent::CASE_UPSERT => SafetyCase::query()
                ->where('reference', $reference)
                ->update(['synced_at' => now(), 'sync_error' => null]),
            OutboundEvent::COMMENT_ADD => CaseComment::query()
                ->whereKey($event->payload['comment_id'] ?? 0)
                ->update(['synced_at' => now(), 'sync_error' => null]),
            default => null,
        };
    }

    private function recordSourceError(OutboundEvent $event, string $error): void
    {
        $reference = $event->payload['reference'] ?? null;

        match ($event->event) {
            OutboundEvent::CASE_UPSERT => SafetyCase::query()
                ->where('reference', $reference)
                ->update(['sync_error' => mb_substr($error, 0, 1000)]),
            OutboundEvent::COMMENT_ADD => CaseComment::query()
                ->whereKey($event->payload['comment_id'] ?? 0)
                ->update(['sync_error' => mb_substr($error, 0, 1000)]),
            default => null,
        };
    }
}
