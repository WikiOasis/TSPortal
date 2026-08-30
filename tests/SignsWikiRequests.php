<?php

declare(strict_types=1);

namespace Tests;

use App\Services\MediaWiki\Hmac;
use Illuminate\Testing\TestResponse;

trait SignsWikiRequests
{
    /** @return array<string, string> */
    protected function signed(string $method, string $path, array|string|null $body = null): array
    {
        $raw = is_array($body) ? json_encode($body) : ($body ?? '');

        return Hmac::fromConfig()->headers($method, $path, $raw) + ['Accept' => 'application/json'];
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function wikiPost(string $path, array $body, array $headers = []): TestResponse
    {
        $raw = json_encode($body);

        return $this->call(
            'POST',
            $path,
            [],
            [],
            [],
            $this->transformHeadersToServerVars(
                $this->signed('POST', $path, $raw) + $headers + ['Content-Type' => 'application/json'],
            ),
            $raw,
        );
    }

    protected function wikiPostFrom(string $wiki, string $path, array $body): TestResponse
    {
        return $this->wikiPost($path, $body, [Hmac::HEADER_WIKI => $wiki]);
    }

    protected function wikiGet(string $path, ?array $headers = null): TestResponse
    {
        return $this->call(
            'GET',
            $path,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers ?? $this->signed('GET', $path)),
            '',
        );
    }
}
