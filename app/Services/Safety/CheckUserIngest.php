<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\CheckUserCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

final class CheckUserIngest
{
    /**
     * @param  array{wiki?: ?string, targets?: ?string, checks?: array<int, mixed>}  $batch
     * @param  string|null  $wiki
     * @return array{stored: int, updated: int, skipped: int, through: ?int}
     */
    public function store(array $batch, ?string $wiki = null): array
    {
        $wiki = $this->wikiFor($batch, $wiki);

        $stored = 0;
        $updated = 0;
        $skipped = 0;
        $through = null;

        foreach ((array) ($batch['checks'] ?? []) as $check) {
            if (! is_array($check)) {
                $skipped++;

                continue;
            }

            $row = $this->row($check, $wiki);

            if ($row === null) {
                $skipped++;

                continue;
            }

            $existing = CheckUserCheck::query()
                ->where('wiki', $row['wiki'])
                ->where('log_id', $row['log_id'])
                ->first();

            if ($existing !== null) {
                $existing->fill($row)->save();
                $updated++;
            } else {
                CheckUserCheck::create($row);
                $stored++;
            }

            $through = max((int) $through, $row['log_id']);
        }

        if ($stored > 0 || $updated > 0) {
            Audit::log('checkuser.received', null, [
                'wiki' => $wiki,
                'stored' => $stored,
                'updated' => $updated,
                'skipped' => $skipped,
                'through' => $through,
                'targets' => $batch['targets'] ?? null,
            ], actorLabel: 'wiki');
        }

        return ['stored' => $stored, 'updated' => $updated, 'skipped' => $skipped, 'through' => $through];
    }

    /**
     * @param  array<string, mixed>  $check
     * @return array<string, mixed>|null
     */
    private function row(array $check, string $wiki): ?array
    {
        $logId = (int) ($check['log_id'] ?? 0);
        if ($logId <= 0) {
            return null;
        }

        $checkedAt = $this->timestamp($check['checked_at'] ?? null);
        if ($checkedAt === null) {
            return null;
        }

        $reason = trim((string) ($check['reason'] ?? ''));
        $kind = (string) Arr::get($check, 'target.kind', CheckUserCheck::TARGET_UNKNOWN);

        return [
            'wiki' => (string) ($check['wiki'] ?? $wiki),
            'log_id' => $logId,
            'checked_at' => $checkedAt,

            'checker_username' => (string) Arr::get($check, 'checker.username', ''),
            'checker_central_id' => Arr::get($check, 'checker.central_id') !== null
                ? (int) Arr::get($check, 'checker.central_id')
                : null,

            'type' => mb_substr((string) ($check['type'] ?? 'unknown'), 0, 32),

            'target_kind' => in_array($kind, CheckUserCheck::TARGET_KINDS, true)
                ? $kind
                : CheckUserCheck::TARGET_UNKNOWN,
            'target_name' => $this->nullIfBlank(Arr::get($check, 'target.name')),
            'target_fingerprint' => $this->nullIfBlank(Arr::get($check, 'target.fingerprint')),

            'reason' => $reason !== '' ? $reason : null,

            'reason_given' => $reason !== '',

            'received_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $batch
     */
    private function wikiFor(array $batch, ?string $wiki): string
    {
        foreach ([$batch['wiki'] ?? null, $wiki] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return mb_substr(trim($candidate), 0, 64);
            }
        }

        return 'unknown';
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullIfBlank(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 255) : null;
    }
}
