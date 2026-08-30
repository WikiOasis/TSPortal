<?php

declare(strict_types=1);

namespace App\Services\MediaWiki;

use App\Models\OutboundEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class WikiClient
{
    public const TASKS = [
        'lock',
        'unlock',
        'warn',
        'note',
        'block',
        'unblock',
        'delete-wiki',
        'undelete-wiki',
        'rename',
        'renamestatus',
        'removepii',
    ];

    public function __construct(private readonly Hmac $hmac) {}

    /** @return list<string> */
    public static function unknownTasks(): array
    {
        return array_values(array_diff(
            (array) config('mediawiki.supported_actions', []),
            self::TASKS,
        ));
    }

    public static function tasks(): array
    {
        return array_values(array_intersect(
            (array) config('mediawiki.supported_actions', []),
            self::TASKS,
        ));
    }

    public static function make(): self
    {
        return new self(Hmac::fromConfig());
    }

    public function enabled(): bool
    {
        return (bool) config('mediawiki.s2s.push_enabled') && $this->hmac->configured();
    }

    /** @return array<string, mixed> */
    public function deliver(OutboundEvent $event): array
    {
        return $this->call('wikioasissafetysync', [
            'event' => $event->event,
            'payload' => json_encode($event->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @return array<string, mixed>
     */
    public function enforce(string $action, array $params): array
    {
        if (! in_array($action, self::tasks(), true)) {
            throw WikiProblem::refusedLocally(
                "The WikiOasisSafety extension cannot carry out '{$action}' yet; this should have been caught before pushing."
            );
        }

        return $this->call('wikioasissafetyenforce', ['task' => $action] + $params);
    }

    /** @return array{central_id: int|null, username: string, registered_at: ?string}|null */
    public function lookupUser(string $username): ?array
    {
        $result = $this->call('wikioasissafetylookup', ['username' => $username]);

        if (! ($result['exists'] ?? false)) {
            return null;
        }

        return [
            'central_id' => isset($result['central_id']) ? (int) $result['central_id'] : null,
            'username' => (string) ($result['username'] ?? $username),
            'registered_at' => $result['registered_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @return array<string, mixed>
     */
    private function call(string $apiAction, array $params): array
    {
        if (! $this->enabled()) {
            throw WikiProblem::unreachable('Pushing to MediaWiki is disabled or MW_S2S_SECRET is unset.');
        }

        $url = (string) config('mediawiki.api_url');
        $body = http_build_query(['action' => $apiAction, 'format' => 'json', 'formatversion' => 2] + $params);

        $headers = $this->hmac->headers('POST', $url, $body)
            + ['User-Agent' => (string) config('mediawiki.user_agent')]
            + ['Content-Type' => 'application/x-www-form-urlencoded'];

        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) config('mediawiki.timeout', 15))
                ->withBody($body, 'application/x-www-form-urlencoded')
                ->post($url);
        } catch (ConnectionException $e) {
            throw WikiProblem::unreachable('Could not reach the wiki: '.$e->getMessage(), $e);
        }

        if ($response->serverError()) {
            throw WikiProblem::unreachable("The wiki returned HTTP {$response->status()}.");
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw WikiProblem::unreachable(
                'The wiki did not return JSON. Check that the action API is reachable.'
            );
        }

        if (isset($json['error'])) {
            $code = (string) ($json['error']['code'] ?? 'unknown');
            $info = (string) ($json['error']['info'] ?? 'no detail given');

            Log::warning('WikiOasisSafety refused a push', ['action' => $apiAction, 'code' => $code, 'info' => $info]);

            throw WikiProblem::refused($code, $info);
        }

        return $json[$apiAction] ?? $json;
    }
}
