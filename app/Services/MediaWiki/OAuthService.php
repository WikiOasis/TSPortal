<?php

declare(strict_types=1);

namespace App\Services\MediaWiki;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class OAuthService
{
    /** @var array{client_id: ?string, client_secret: ?string, scopes: string, ...} */
    private array $config;

    public function __construct()
    {
        $this->config = config('mediawiki.oauth');
    }

    public function configured(): bool
    {
        return ! empty($this->config['client_id']) && ! empty($this->config['client_secret']);
    }

    public function redirectUri(): string
    {
        return $this->config['redirect_uri'] ?: route('oauth.callback');
    }

    /** @return array{url: string, state: string} */
    public function authorizationUrl(): array
    {
        $this->assertConfigured();

        $state = Str::random(40);

        $url = $this->endpoint($this->config['authorize_endpoint']).'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'scope' => $this->config['scopes'],
            'state' => $state,
        ]);

        return ['url' => $url, 'state' => $state];
    }

    /** @return array<string, mixed> */
    public function exchangeCode(string $code): array
    {
        $this->assertConfigured();

        $response = $this->http()->asForm()->post($this->endpoint($this->config['token_endpoint']), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'MediaWiki refused the authorization code (HTTP %d): %s',
                $response->status(),
                (string) $response->json('message', $response->body()),
            ));
        }

        $token = $response->json();
        if (! is_array($token) || ! isset($token['access_token'])) {
            throw new RuntimeException('MediaWiki returned no access token.');
        }

        return $token;
    }

    /** @return array{sub: int, username: string, realname: ?string, email: ?string, groups: list<string>, rights: list<string>} */
    public function profile(string $accessToken): array
    {
        $response = $this->http()
            ->withToken($accessToken)
            ->get($this->endpoint($this->config['profile_endpoint']));

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Could not read the MediaWiki profile (HTTP %d).',
                $response->status(),
            ));
        }

        $profile = $response->json();
        if (! is_array($profile) || ! isset($profile['username'])) {
            throw new RuntimeException('The MediaWiki profile response was not usable.');
        }

        $sub = isset($profile['sub']) && is_numeric($profile['sub']) ? (int) $profile['sub'] : 0;

        if ($sub <= 0) {
            throw new RuntimeException(
                'The MediaWiki profile response carried no account id, so this sign-in cannot be matched to an account.'
            );
        }

        return [
            'sub' => $sub,
            'username' => (string) $profile['username'],
            'realname' => ($profile['realname'] ?? null) ?: null,
            'email' => ($profile['email'] ?? null) ?: null,
            'groups' => array_values(array_map('strval', (array) ($profile['groups'] ?? []))),
            'rights' => array_values(array_map('strval', (array) ($profile['rights'] ?? []))),
        ];
    }

    private function endpoint(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return rtrim((string) config('mediawiki.rest_url'), '/').'/'.ltrim($path, '/');
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => (string) config('mediawiki.user_agent')])
            ->timeout((int) config('mediawiki.timeout', 15))
            ->acceptJson();
    }

    private function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException(
                'MediaWiki OAuth is not configured. Set MW_OAUTH_CLIENT_ID and MW_OAUTH_CLIENT_SECRET.'
            );
        }
    }
}
