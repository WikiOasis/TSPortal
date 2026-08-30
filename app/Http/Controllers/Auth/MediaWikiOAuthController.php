<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MediaWiki\OAuthService;
use App\Services\Safety\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class MediaWikiOAuthController extends Controller
{
    public function __construct(private readonly OAuthService $oauth) {}

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->oauth->configured()) {
            return redirect()->route('login')->withErrors([
                'oauth' => 'This portal has no OAuth client configured yet. Set MW_OAUTH_CLIENT_ID and MW_OAUTH_CLIENT_SECRET.',
            ]);
        }

        ['url' => $url, 'state' => $state] = $this->oauth->authorizationUrl();

        $request->session()->put('mw_oauth_state', $state);

        $next = (string) $request->query('next', '');
        if ($next !== '' && str_starts_with($next, '/') && ! str_starts_with($next, '//')) {
            $request->session()->put('mw_oauth_next', $next);
        }

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('login')->withErrors([
                'oauth' => 'The wiki did not authorise the sign-in: '.$request->string('error_description', $request->string('error')),
            ]);
        }

        $expected = $request->session()->pull('mw_oauth_state');
        if (! is_string($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('login')->withErrors([
                'oauth' => 'That sign-in could not be matched to one this portal started. Please try again.',
            ]);
        }

        try {
            $token = $this->oauth->exchangeCode((string) $request->query('code'));
            $profile = $this->oauth->profile((string) $token['access_token']);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->withErrors(['oauth' => $e->getMessage()]);
        }

        $user = $this->upsert($profile);

        if (! $user->active) {
            Audit::log('auth.blocked', $user, ['reason' => 'account deactivated in portal']);

            return redirect()->route('login')->withErrors([
                'oauth' => 'This account has been deactivated in the portal. Speak to a portal administrator.',
            ]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        Audit::log('auth.login', $user, ['groups' => $profile['groups']]);

        return redirect()->to($request->session()->pull('mw_oauth_next', '/'));
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

    /** @param  array{sub: int, username: string, realname: ?string, email: ?string, groups: list<string>, rights: list<string>}  $profile */
    private function upsert(array $profile): User
    {
        $user = User::query()->firstOrNew(['mw_central_id' => $profile['sub']]);

        $user->username = $profile['username'];
        $user->real_name = $profile['realname'];
        $user->email = $profile['email'];
        $user->last_login_at = now();
        $user->granted_flags ??= [];
        $user->syncGroupFlags($profile['groups']);
        $user->save();

        return $user;
    }
}
