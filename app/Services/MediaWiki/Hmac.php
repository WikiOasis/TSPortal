<?php

declare(strict_types=1);

namespace App\Services\MediaWiki;

use Illuminate\Support\Str;

final class Hmac
{
    public const HEADER_TIMESTAMP = 'X-TSPortal-Timestamp';

    public const HEADER_NONCE = 'X-TSPortal-Nonce';

    public const HEADER_SIGNATURE = 'X-TSPortal-Signature';

    public const HEADER_WIKI = 'X-TSPortal-Wiki';

    public function __construct(
        private readonly string $secret,
        private readonly int $tolerance = 300,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('mediawiki.s2s.secret'),
            (int) config('mediawiki.s2s.tolerance', 300),
        );
    }

    public function configured(): bool
    {
        return $this->secret !== '';
    }

    public function canonical(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        $path = '/'.trim(parse_url($path, PHP_URL_PATH) ?: $path, '/');

        return implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public function sign(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', $this->canonical($method, $path, $timestamp, $nonce, $body), $this->secret);
    }

    /** @return array<string, string> */
    public function headers(string $method, string $path, string $body): array
    {
        $timestamp = (string) time();
        $nonce = Str::random(24);

        return [
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATURE => $this->sign($method, $path, $timestamp, $nonce, $body),
        ];
    }

    public function verify(string $method, string $path, string $timestamp, string $nonce, string $body, string $signature): bool
    {
        if (! $this->configured() || $timestamp === '' || $nonce === '' || $signature === '') {
            return false;
        }

        if (! $this->fresh($timestamp)) {
            return false;
        }

        return hash_equals($this->sign($method, $path, $timestamp, $nonce, $body), $signature);
    }

    public function fresh(string $timestamp): bool
    {
        return abs(time() - (int) $timestamp) <= $this->tolerance;
    }
}
