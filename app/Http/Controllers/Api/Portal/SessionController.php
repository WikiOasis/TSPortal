<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Services\Authentik\OidcService;
use App\Services\MediaWiki\WikiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user === null ? null : [
                'id' => $user->id,
                'username' => $user->username,
                'real_name' => $user->real_name,
                'flags' => $user->flags ?? [],
                'public_label' => $user->publicLabel(),
            ],
            'wiki' => [
                'name' => config('app.name'),
                'central_url' => config('mediawiki.central_url'),
                'oidc_configured' => (new OidcService)->configured(),

                'supported_actions' => WikiClient::tasks(),
                'push_enabled' => (bool) config('mediawiki.s2s.push_enabled'),

                'centralauth_lock' => (bool) config('mediawiki.centralauth_lock'),

                'pii_enabled' => (bool) config('mediawiki.pii.enabled'),
            ],
        ]);
    }
}
