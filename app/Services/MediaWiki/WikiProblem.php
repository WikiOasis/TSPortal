<?php

declare(strict_types=1);

namespace App\Services\MediaWiki;

use RuntimeException;

class WikiProblem extends RuntimeException
{
    private const TRANSIENT_CODES = [
        'readonly',
        'maxlag',
        'ratelimited',
        'tasknotready',
    ];

    private const FINAL_CODES = [
        'nosecret',
        'badsignature',
        'unsupportedtask',
        'taskfailed',
        'permissiondenied',
        'badtoken',
    ];

    public function __construct(
        string $message,
        private readonly ?string $wikiCode = null,
        private readonly bool $retryable = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unreachable(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, null, true, $previous);
    }

    public static function refused(string $code, string $info): self
    {
        $retryable = match (true) {
            in_array($code, self::FINAL_CODES, true) => false,
            in_array($code, self::TRANSIENT_CODES, true) => true,
            str_starts_with($code, 'internal_api_error') => true,
            default => true,
        };

        return new self(
            sprintf('The wiki refused the request (%s): %s', $code, $info),
            $code,
            $retryable,
        );
    }

    public static function refusedLocally(string $message): self
    {
        return new self($message, null, false);
    }

    public function code(): ?string
    {
        return $this->wikiCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
