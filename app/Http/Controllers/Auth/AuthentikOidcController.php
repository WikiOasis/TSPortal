<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Authentik\OidcService;
use App\Services\Safety\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class AuthentikOidcController extends Controller
{
    public function __construct(private readonly OidcService $oidc) {}

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->oidc->configured()) {
            return redirect()->route('login')->withErrors([
                'oidc' => 'This portal has no identity provider configured yet. Set AUTHENTIK_CLIENT_ID and AUTHENTIK_CLIENT_SECRET.',
            ]);
        }

        ['url' => $url, 'state' => $state, 'code_verifier' => $codeVerifier] = $this->oidc->authorizationUrl();

        $request->session()->put('oidc_state', $state);
        $request->session()->put('oidc_code_verifier', $codeVerifier);

        $next = (string) $request->query('next', '');
        if ($next !== '' && str_starts_with($next, '/') && ! str_starts_with($next, '//')) {
            $request->session()->put('oidc_next', $next);
        }

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('oidc_state');
        $codeVerifier = (string) $request->session()->pull('oidc_code_verifier');

        if ($request->filled('error')) {
            return redirect()->route('login')->withErrors([
                'oidc' => 'Authentik did not authorise the sign-in: '.$request->string('error_description', $request->string('error')),
            ]);
        }

        if (! is_string($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('login')->withErrors([
                'oidc' => 'That sign-in could not be matched to one this portal started. Please try again.',
            ]);
        }

        try {
            $token = $this->oidc->exchangeCode((string) $request->query('code'), $codeVerifier);
            $claims = $this->oidc->userinfo((string) $token['access_token']);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->withErrors(['oidc' => $e->getMessage()]);
        }

        if ($claims['email'] === null) {
            Audit::log('auth.blocked', null, ['reason' => 'no email address in the userinfo claims', 'sub' => $claims['sub']]);

            return redirect()->route('login')->withErrors([
                'oidc' => 'That account has no email address, so it cannot be matched to a portal account.',
            ]);
        }

        $user = $this->upsert($claims);

        if (! $user->active) {
            Audit::log('auth.blocked', $user, ['reason' => 'account deactivated in portal']);

            return redirect()->route('login')->withErrors([
                'oidc' => 'This account has been deactivated in the portal. Speak to a portal administrator.',
            ]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        Audit::log('auth.login', $user, ['groups' => $claims['groups']]);

        return redirect()->to($request->session()->pull('oidc_next', '/'));
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($request->user() !== null) {
            Audit::log('auth.logout', $request->user());
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** @param  array{sub: string, email: string, username: string, real_name: ?string, groups: list<string>}  $claims */
    private function upsert(array $claims): User
    {
        $user = User::query()->firstOrNew(['email' => $claims['email']]);

        $user->username = $claims['username'];
        $user->real_name = $claims['real_name'];
        $user->last_login_at = now();
        $user->granted_flags ??= [];
        $user->syncGroupFlags($claims['groups']);
        $user->save();

        return $user;
    }
}
