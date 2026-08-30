<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MediaWiki\Hmac;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HmacTest extends TestCase
{
    private function hmac(int $tolerance = 300): Hmac
    {
        return new Hmac('a-shared-secret', $tolerance);
    }

    #[Test]
    public function it_verifies_a_signature_it_produced(): void
    {
        $hmac = $this->hmac();
        $ts = (string) time();
        $signature = $hmac->sign('POST', '/api/wiki/v1/submissions', $ts, 'nonce', '{"a":1}');

        $this->assertTrue(
            $hmac->verify('POST', '/api/wiki/v1/submissions', $ts, 'nonce', '{"a":1}', $signature)
        );
    }

    #[Test]
    public function a_changed_body_invalidates_the_signature(): void
    {
        $hmac = $this->hmac();
        $ts = (string) time();
        $signature = $hmac->sign('POST', '/api/wiki/v1/submissions', $ts, 'nonce', '{"a":1}');

        $this->assertFalse(
            $hmac->verify('POST', '/api/wiki/v1/submissions', $ts, 'nonce', '{"a":2}', $signature)
        );
    }

    #[Test]
    public function an_old_request_is_refused_however_well_signed(): void
    {
        $hmac = $this->hmac(tolerance: 60);
        $ts = (string) (time() - 600);
        $signature = $hmac->sign('GET', '/api/wiki/v1/health', $ts, 'nonce', '');

        $this->assertFalse($hmac->verify('GET', '/api/wiki/v1/health', $ts, 'nonce', '', $signature));
    }

    #[Test]
    public function a_trailing_slash_does_not_change_the_signature(): void
    {
        $hmac = $this->hmac();
        $ts = (string) time();

        $this->assertSame(
            $hmac->sign('GET', '/api/wiki/v1/health', $ts, 'n', ''),
            $hmac->sign('GET', '/api/wiki/v1/health/', $ts, 'n', ''),
        );
    }

    #[Test]
    public function a_query_string_is_not_part_of_the_signature(): void
    {
        $hmac = $this->hmac();
        $ts = (string) time();

        $this->assertSame(
            $hmac->sign('GET', '/api/wiki/v1/health', $ts, 'n', ''),
            $hmac->sign('GET', '/api/wiki/v1/health?format=json', $ts, 'n', ''),
        );
    }

    #[Test]
    public function an_unconfigured_secret_verifies_nothing(): void
    {
        $hmac = new Hmac('', 300);

        $this->assertFalse($hmac->configured());
        $this->assertFalse($hmac->verify('GET', '/x', (string) time(), 'n', '', 'anything'));
    }
}
