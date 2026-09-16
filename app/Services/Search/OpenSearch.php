<?php

declare(strict_types=1);

namespace App\Services\Search;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OpenSearch
{
    public function configured(): bool
    {
        return (bool) config('opensearch.enabled') && $this->url() !== '';
    }

    public function url(): string
    {
        return (string) config('opensearch.url');
    }

    public function indexName(string $kind): string
    {
        return config('opensearch.prefix').'-'.$kind;
    }

    public function reachable(): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            return $this->request('GET', '/')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function info(): array
    {
        return $this->json($this->request('GET', '/'));
    }

    public function indexExists(string $index): bool
    {
        return $this->request('HEAD', '/'.$index)->status() === 200;
    }

    public function createIndex(string $index, array $body): void
    {
        $this->assert($this->request('PUT', '/'.$index, $body));
    }

    public function deleteIndex(string $index): void
    {
        $response = $this->request('DELETE', '/'.$index);

        if ($response->status() === 404) {
            return;
        }

        $this->assert($response);
    }

    public function refresh(string $indices): void
    {
        $this->request('POST', '/'.$indices.'/_refresh');
    }

    public function documentCount(string $index): int
    {
        $response = $this->request('GET', '/'.$index.'/_count');

        return $response->successful() ? (int) $this->json($response)['count'] : 0;
    }

    /**
     * @param  list<array<string, mixed>>  $lines  Alternating action and document lines.
     * @return list<string> Failures, as "id: reason".
     */
    public function bulk(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $body = '';
        foreach ($lines as $line) {
            $body .= json_encode($line, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
        }

        $response = $this->send(
            $this->client()->withBody($body, 'application/x-ndjson'),
            'POST',
            '/_bulk'.($this->refreshing() ? '?refresh=true' : ''),
        );

        $this->assert($response);

        $payload = $this->json($response);

        if (($payload['errors'] ?? false) !== true) {
            return [];
        }

        $failures = [];
        foreach ($payload['items'] ?? [] as $item) {
            $result = reset($item);
            if (isset($result['error'])) {
                $failures[] = ($result['_id'] ?? '?').': '.($result['error']['reason'] ?? 'rejected');
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function search(string $indices, array $body): array
    {
        $response = $this->request('GET', '/'.$indices.'/_search?ignore_unavailable=true', $body);

        $this->assert($response);

        return $this->json($response);
    }

    public function deleteByQuery(string $indices, array $query): int
    {
        $response = $this->request(
            'POST',
            '/'.$indices.'/_delete_by_query?ignore_unavailable=true&conflicts=proceed'
                .($this->refreshing() ? '&refresh=true' : ''),
            ['query' => $query],
        );

        $this->assert($response);

        return (int) ($this->json($response)['deleted'] ?? 0);
    }

    private function refreshing(): bool
    {
        return (bool) config('opensearch.refresh');
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $client = $this->client();

        if ($body !== null) {
            $client = $client->withBody(
                (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'application/json',
            );
        }

        return $this->send($client, $method, $path);
    }

    private function send(PendingRequest $client, string $method, string $path): Response
    {
        if (! $this->configured()) {
            throw new SearchUnavailable('OpenSearch is not configured.');
        }

        try {
            return $client->send($method, $this->url().$path);
        } catch (ConnectionException $e) {
            throw new SearchUnavailable('OpenSearch did not answer: '.$e->getMessage(), previous: $e);
        }
    }

    private function client(): PendingRequest
    {
        $client = Http::timeout((int) config('opensearch.timeout'))
            ->withHeaders(['Accept' => 'application/json'])
            ->withOptions(['verify' => (bool) config('opensearch.verify')]);

        $username = (string) config('opensearch.username');

        return $username === ''
            ? $client
            : $client->withBasicAuth($username, (string) config('opensearch.password'));
    }

    private function assert(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $payload = $this->json($response);

        throw new SearchUnavailable(sprintf(
            'OpenSearch returned HTTP %d: %s',
            $response->status(),
            $payload['error']['reason'] ?? $response->body(),
        ));
    }

    private function json(Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }
}
