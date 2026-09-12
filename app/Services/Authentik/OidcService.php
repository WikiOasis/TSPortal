<?php

declare(strict_types=1);

namespace App\Services\Authentik;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class OidcService
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct()
    {
        $this->config = config('authentik');
    }

    public function configured(): bool
    {
        return ! empty($this->config['base_url'])
            && ! empty($this->config['client_id'])
            && ! empty($this->config['client_secret']);
    }

    public function redirectUri(): string
    {
        return $this->config['redirect_uri'] ?: route('oidc.callback');
    }

    /** @return array{url: string, state: string, code_verifier: string} */
    public function authorizationUrl(): array
    {
        $this->assertConfigured();

        $state = Str::random(40);
        $codeVerifier = Str::random(96);

        $url = $this->endpoint($this->config['authorize_endpoint']).'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'scope' => $this->config['scopes'],
            'state' => $state,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ]);

        return ['url' => $url, 'state' => $state, 'code_verifier' => $codeVerifier];
    }

    /** @return array<string, mixed> */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $this->assertConfigured();

        $response = $this->http()->asForm()->post($this->endpoint($this->config['token_endpoint']), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code_verifier' => $codeVerifier,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Authentik refused the authorization code (HTTP %d): %s',
                $response->status(),
                (string) $response->json('error_description', $response->body()),
            ));
        }

        $token = $response->json();
        if (! is_array($token) || ! isset($token['access_token'])) {
            throw new RuntimeException('Authentik returned no access token.');
        }

        return $token;
    }

    /** @return array{sub: string, email: ?string, username: string, real_name: ?string, groups: list<string>} */
    public function userinfo(string $accessToken): array
    {
        $response = $this->http()
            ->withToken($accessToken)
            ->get($this->endpoint($this->config['userinfo_endpoint']));

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Could not read the Authentik userinfo (HTTP %d).',
                $response->status(),
            ));
        }

        $claims = $response->json();
        if (! is_array($claims) || ! isset($claims['sub'])) {
            throw new RuntimeException('The Authentik userinfo response was not usable.');
        }

        $email = Str::lower(trim((string) ($claims['email'] ?? '')));

        return [
            'sub' => (string) $claims['sub'],
            'email' => $email !== '' ? $email : null,
            'username' => (string) ($claims['preferred_username'] ?? $claims['nickname'] ?? $email),
            'real_name' => ($claims['name'] ?? null) ?: null,
            'groups' => array_values(array_map('strval', (array) ($claims['groups'] ?? []))),
        ];
    }

    private static function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    private function endpoint(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return rtrim((string) $this->config['base_url'], '/').'/'.ltrim($path, '/');
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) ($this->config['timeout'] ?? 15))->acceptJson();
    }

    private function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException(
                'Authentik is not configured. Set AUTHENTIK_BASE_URL, AUTHENTIK_CLIENT_ID and AUTHENTIK_CLIENT_SECRET.'
            );
        }
    }
}
