<?php

declare(strict_types=1);

namespace App\Services\Slack;

use App\Jobs\PostToSlack;
use App\Models\AuditLog;
use App\Models\SafetyCase;
use App\Services\Safety\Triage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

final class SlackNotifier
{
    public static function announce(AuditLog $log, ?Model $target = null): void
    {
        try {
            if (! self::wanted($log->action)) {
                return;
            }

            $urgent = self::isUrgent($log, $target);
            $webhook = self::webhookFor($log->action, $urgent);

            if ($webhook === '') {
                return;
            }

            $payload = self::payload($log, $target, $urgent);

            if ((bool) config('slack.queue', true)) {
                PostToSlack::dispatch($webhook, $payload);

                return;
            }

            (new PostToSlack($webhook, $payload))->handle();
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function wanted(string $action): bool
    {
        if (! (bool) config('slack.enabled', false)) {
            return false;
        }

        foreach ((array) config('slack.ignore', []) as $ignored) {
            if (is_string($ignored) && $ignored !== '' && self::matches($action, $ignored)) {
                return false;
            }
        }

        return true;
    }

    private static function matches(string $action, string $key): bool
    {
        return $action === $key || str_starts_with($action, $key.'.');
    }

    public static function webhookFor(string $action, bool $urgent = false): string
    {
        $hooks = (array) config('slack.webhooks', []);
        $default = (string) ($hooks['default'] ?? '');

        if ($urgent) {
            $named = (string) ($hooks['urgent'] ?? '');
            if ($named !== '') {
                return $named;
            }
        }

        $best = null;
        $bestLength = -1;

        foreach ((array) config('slack.routes', []) as $key => $hook) {
            if (is_string($key) && self::matches($action, $key) && strlen($key) > $bestLength) {
                $best = is_string($hook) ? $hook : null;
                $bestLength = strlen($key);
            }
        }

        $resolved = $best !== null ? (string) ($hooks[$best] ?? '') : '';

        return $resolved !== '' ? $resolved : $default;
    }

    private static function isUrgent(AuditLog $log, ?Model $target): bool
    {
        $meta = (array) ($log->meta ?? []);

        if (array_key_exists('threat_to_life', $meta)) {
            return (bool) $meta['threat_to_life'];
        }

        if (isset($meta['categories']) && is_array($meta['categories'])) {
            return Triage::isThreatToLife($meta['categories']);
        }

        return $target instanceof SafetyCase && $target->isThreatToLife();
    }

    /** @return array<string, mixed> */
    public static function payload(AuditLog $log, ?Model $target, bool $urgent): array
    {
        $reference = self::referenceOf($log, $target);
        $sentence = self::sentence($log, $target);
        $mention = ($urgent && $log->action === 'case.created') ? trim((string) config('slack.mention', '')) : '';

        $headline = $urgent
            ? sprintf(':rotating_light: *Threat to life* — %s', $sentence)
            : $sentence;

        $fallback = trim(($mention !== '' ? $mention.' ' : '')
            .($urgent ? 'THREAT TO LIFE — ' : '')
            .$sentence);

        $blocks = [[
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => trim(($mention !== '' ? $mention."\n" : '').$headline),
            ],
        ]];

        $context = self::context($log, $target);

        if ($context !== []) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => implode('  ·  ', $context)]],
            ];
        }

        $url = self::linkFor($reference);

        if ($url !== null) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => [[
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Open in the portal'],
                    'url' => $url,
                    'style' => $urgent ? 'danger' : 'primary',
                ]],
            ];
        }

        return ['text' => $fallback, 'blocks' => $blocks];
    }

    private static function sentence(AuditLog $log, ?Model $target): string
    {
        $reference = self::referenceOf($log, $target);
        $ref = $reference !== null ? '*'.$reference.'*' : 'a case';
        $meta = (array) ($log->meta ?? []);
        $who = self::actor($log);

        $kind = $target instanceof SafetyCase
            ? match ($target->type) {
                SafetyCase::TYPE_APPEAL => 'appeal',
                SafetyCase::TYPE_DATA => 'data request',
                SafetyCase::TYPE_CONTACT => 'message',
                default => 'report',
            }
        : 'case';

        return match ($log->action) {
            'case.created' => sprintf('New %s %s', $kind, $ref),
            'case.status' => sprintf(
                '%s is now %s%s',
                $ref,
                str_replace('-', ' ', (string) ($meta['to'] ?? 'updated')),
                $who !== null ? ' — '.$who : '',
            ),
            'case.assigned' => sprintf('%s assigned%s', $ref, isset($meta['assignee'])
                ? ' to '.$meta['assignee']
                : ' to nobody'),
            'case.categorised' => sprintf('%s re-filed under %s', $ref, self::list($meta['to'] ?? [])),
            'case.escalated' => sprintf(
                '%s escalated to %s — filed under a category that means risk to life',
                $ref,
                $meta['to'] ?? 'urgent',
            ),
            'case.priority' => sprintf('%s set to %s priority%s', $ref, $meta['to'] ?? '?', $who !== null ? ' by '.$who : ''),
            'comment.added' => ($meta['author'] ?? '') === 'subject'
                ? sprintf('Reply from the reporter on %s', $ref)
                : sprintf('Comment on %s', $ref),
            'attachment.received' => ($meta['state'] ?? '') === 'stored'
                ? sprintf('File attached to %s', $ref)
                : sprintf('A file for %s was not stored: %s', $ref, $meta['refused'] ?? 'refused'),

            'sanction.issued' => sprintf('Action issued: %s', $ref),
            'sanction.lifted' => sprintf('Action %s lifted%s', $ref, $who !== null ? ' by '.$who : ''),
            'sanction.expired' => sprintf('Action %s has expired', $ref),
            'sanction.acknowledged' => sprintf('Action %s was acknowledged', $ref),
            'sanction.wiki-reported' => sprintf('The wiki reported back on %s', $ref),

            'data-request.approved' => sprintf('Data request %s approved', $ref),
            'data-request.declined' => sprintf('Data request %s declined', $ref),
            'data-request.kind' => sprintf('Data request %s confirmed as %s', $ref, $meta['kind'] ?? '?'),
            'data-removal.requested' => sprintf('Erasure requested for %s', $ref),
            'data-removal.started' => sprintf('Erasure started for %s', $ref),
            'data-removal.renamed' => sprintf('The account on %s has been renamed', $ref),
            'data-removal.sent' => sprintf('Erasure sent to the wikis for %s', $ref),
            'data-removal.completed' => sprintf('Erasure completed for %s', $ref),
            'data-removal.failed' => sprintf(':warning: Erasure failed for %s', $ref),
            'data-removal.refused' => sprintf(':warning: A wiki refused the erasure for %s', $ref),

            'investigation.opened' => sprintf('Investigation %s opened', $ref),
            'investigation.concluded' => sprintf('Investigation %s concluded', $ref),
            'investigation.closed' => sprintf('Investigation %s closed', $ref),

            'transparency.published' => sprintf('Transparency report %s published', $ref),
            'transparency.generated' => sprintf('Transparency report %s generated', $ref),

            'checkuser.received' => sprintf(
                '%s CheckUser %s recorded',
                $meta['stored'] ?? $meta['count'] ?? 'Some',
                ($meta['stored'] ?? 0) === 1 ? 'check' : 'checks',
            ),
            'staff.updated' => sprintf('Staff account changed: %s', $meta['username'] ?? 'someone'),
            'auth.blocked' => sprintf(':warning: Sign-in refused for %s', $meta['username'] ?? 'an account'),

            default => sprintf(
                '%s%s',
                Str::ucfirst(str_replace(['.', '-'], ' ', $log->action)),
                $reference !== null ? ' — '.$ref : '',
            ),
        };
    }

    /** @return list<string> */
    private static function context(AuditLog $log, ?Model $target): array
    {
        $meta = (array) ($log->meta ?? []);
        $parts = [];

        if ($target instanceof SafetyCase) {
            $categories = $target->relationLoaded('categories')
                ? $target->categories->pluck('label')->filter()->all()
                : [];

            if ($categories === [] && isset($meta['categories']) && is_array($meta['categories'])) {
                $categories = $meta['categories'];
            }

            if ($categories !== []) {
                $parts[] = self::list($categories);
            }

            if ($target->wiki !== null && $target->wiki !== '') {
                $parts[] = '`'.$target->wiki.'`';
            }

            if ($target->priority !== SafetyCase::PRIORITY_NORMAL) {
                $parts[] = $target->priority.' priority';
            }
        } elseif (isset($meta['wiki']) && is_string($meta['wiki'])) {
            $parts[] = '`'.$meta['wiki'].'`';
        }

        $actor = self::actor($log);
        if ($actor !== null && $parts !== []) {
            $parts[] = $actor;
        }

        return $parts;
    }

    private static function actor(AuditLog $log): ?string
    {
        if ($log->user_id !== null) {
            $log->loadMissing('user');
            $name = $log->user?->username;

            return is_string($name) && $name !== '' ? $name : null;
        }

        return match ($log->actor_label) {
            'wiki' => 'from the wiki',
            null, '' => null,
            default => (string) $log->actor_label,
        };
    }

    private static function referenceOf(AuditLog $log, ?Model $target): ?string
    {
        $reference = $target?->getAttribute('reference');

        if (is_string($reference) && $reference !== '') {
            return $reference;
        }

        $meta = (array) ($log->meta ?? []);

        return isset($meta['reference']) && is_string($meta['reference']) && $meta['reference'] !== ''
            ? $meta['reference']
            : null;
    }

    private static function linkFor(?string $reference): ?string
    {
        $base = rtrim((string) config('slack.portal_url', ''), '/');

        if ($base === '') {
            return null;
        }

        if ($reference !== null && $reference !== '') {
            return $base.'/item/'.rawurlencode($reference);
        }

        return $base;
    }

    /** @param mixed $values */
    private static function list($values): string
    {
        $items = array_values(array_filter(
            array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', (array) $values),
            fn (string $v) => $v !== '',
        ));

        if ($items === []) {
            return 'nothing';
        }

        return count($items) > 4
            ? implode(', ', array_slice($items, 0, 4)).sprintf(' and %d more', count($items) - 4)
            : implode(', ', $items);
    }
}
