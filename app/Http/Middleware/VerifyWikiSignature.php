<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\MediaWiki\Hmac;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyWikiSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $hmac = Hmac::fromConfig();

        if (! $hmac->configured()) {
            return response()->json([
                'error' => 'not-configured',
                'message' => 'This portal has no shared secret set, so it cannot accept requests from a wiki.',
            ], 503);
        }

        $timestamp = (string) $request->header(Hmac::HEADER_TIMESTAMP, '');
        $nonce = (string) $request->header(Hmac::HEADER_NONCE, '');
        $signature = (string) $request->header(Hmac::HEADER_SIGNATURE, '');

        $ok = $hmac->verify(
            $request->getMethod(),
            $request->getPathInfo(),
            $timestamp,
            $nonce,
            $request->getContent(),
            $signature,
        );

        if (! $ok) {
            return response()->json([
                'error' => 'bad-signature',
                'message' => 'The request was not signed with a secret this portal recognises, or it is too old.',
            ], 401);
        }

        $ttl = max((int) config('mediawiki.s2s.nonce_ttl', 600), (int) config('mediawiki.s2s.tolerance', 300));
        if (! Cache::add('mw-nonce:'.hash('sha256', $nonce), true, $ttl)) {
            return response()->json([
                'error' => 'replayed',
                'message' => 'This request has already been processed.',
            ], 409);
        }

        $request->attributes->set('wiki', $request->header(Hmac::HEADER_WIKI));

        return $next($request);
    }
}
