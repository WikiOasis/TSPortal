<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthentikLoginTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://id.example.test/application/o/token/';

    private const USERINFO_URL = 'https://id.example.test/application/o/userinfo/';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'authentik.base_url' => 'https://id.example.test',
            'authentik.client_id' => 'tsportal',
            'authentik.client_secret' => 'a-client-secret',
            'authentik.redirect_uri' => 'https://portal.example.test/auth/oidc/callback',
            'authentik.group_flags' => ['safety' => ['ts', 'admin']],
            'authentik.bootstrap_admins' => [],
        ]);
    }

    /** @param  array<string, mixed>  $claims */
    private function fakeProvider(array $claims): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'an-access-token', 'token_type' => 'Bearer']),
            self::USERINFO_URL => Http::response($claims),
        ]);
    }

    /** @param  array<string, string>  $query */
    private function returnFromProvider(array $query = []): TestResponse
    {
        return $this
            ->withSession(['oidc_state' => 'the-state', 'oidc_code_verifier' => 'the-code-verifier'])
            ->get('/auth/oidc/callback?'.http_build_query(array_merge(
                ['code' => 'the-code', 'state' => 'the-state'],
                $query,
            )));
    }

    #[Test]
    public function the_authorization_url_carries_state_and_an_s256_challenge(): void
    {
        $response = $this->get('/auth/oidc/redirect?next=/cases');

        $response->assertRedirectContains('https://id.example.test/application/o/authorize/');

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('tsportal', $query['client_id']);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertSame('https://portal.example.test/auth/oidc/callback', $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(session('oidc_state'), $query['state']);
        $this->assertSame('/cases', session('oidc_next'));

        $challenge = rtrim(strtr(base64_encode(hash('sha256', (string) session('oidc_code_verifier'), true)), '+/', '-_'), '=');
        $this->assertSame($challenge, $query['code_challenge']);
    }

    #[Test]
    public function an_unconfigured_portal_sends_nobody_to_the_provider(): void
    {
        config(['authentik.client_secret' => null]);

        $this->get('/auth/oidc/redirect')->assertRedirect(route('login'));
    }

    #[Test]
    public function signing_in_creates_the_account_and_takes_its_flags_from_the_group_claim(): void
    {
        $this->fakeProvider([
            'sub' => 'ak-7f3c',
            'email' => '  Halcyon.Reed@Example.Test ',
            'email_verified' => true,
            'preferred_username' => 'hreed',
            'name' => 'Halcyon Reed',
            'groups' => ['safety', 'everyone'],
        ]);

        $this->returnFromProvider()->assertRedirect('/');

        $user = User::query()->sole();

        $this->assertSame('halcyon.reed@example.test', $user->email);
        $this->assertSame('hreed', $user->username);
        $this->assertSame('Halcyon Reed', $user->real_name);
        $this->assertNull($user->mw_central_id);
        $this->assertSame(['safety', 'everyone'], $user->idp_groups);
        $this->assertEqualsCanonicalizing(['ts', 'admin'], $user->flags);
        $this->assertNotNull($user->last_login_at);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login']);
    }

    #[Test]
    public function the_code_exchange_carries_the_pkce_verifier(): void
    {
        $this->fakeProvider([
            'sub' => 'ak-7f3c',
            'email' => 'halcyon.reed@example.test',
            'email_verified' => true,
            'preferred_username' => 'hreed',
            'groups' => [],
        ]);

        $this->returnFromProvider()->assertRedirect('/');

        Http::assertSent(fn ($request) => $request->url() === self::TOKEN_URL
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'the-code'
            && $request['code_verifier'] === 'the-code-verifier');

        Http::assertSent(fn ($request) => $request->url() === self::USERINFO_URL
            && $request->hasHeader('Authorization', 'Bearer an-access-token'));
    }

    #[Test]
    public function a_second_sign_in_is_matched_on_email_and_keeps_granted_flags(): void
    {
        $existing = User::create([
            'mw_central_id' => 4242,
            'username' => 'Halcyon',
            'email' => 'halcyon.reed@example.test',
            'granted_flags' => ['user-manager'],
            'flags' => ['user-manager'],
        ]);

        $this->fakeProvider([
            'sub' => 'ak-7f3c',
            'email' => 'Halcyon.Reed@example.test',
            'email_verified' => true,
            'preferred_username' => 'hreed',
            'groups' => ['safety'],
        ]);

        $this->returnFromProvider()->assertRedirect('/');

        $this->assertSame(1, User::query()->count());

        $user = $existing->fresh();

        $this->assertSame('hreed', $user->username);
        $this->assertSame(4242, $user->mw_central_id);
        $this->assertEqualsCanonicalizing(['ts', 'admin', 'user-manager'], $user->flags);
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_callback_that_does_not_match_the_state_this_portal_stored_is_refused(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->returnFromProvider(['state' => 'a-forged-state'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function a_sign_in_without_an_email_claim_is_refused(): void
    {
        $this->fakeProvider([
            'sub' => 'ak-7f3c',
            'preferred_username' => 'hreed',
            'groups' => ['safety'],
        ]);

        $this->returnFromProvider()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.blocked']);
    }

    #[Test]
    public function a_sign_in_with_an_unverified_email_is_refused(): void
    {
        $this->fakeProvider([
            'sub' => 'ak-7f3c',
            'email' => 'halcyon.reed@example.test',
            'email_verified' => false,
            'preferred_username' => 'hreed',
            'groups' => ['safety'],
        ]);

        $this->returnFromProvider()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.blocked']);
    }

    #[Test]
    public function a_deactivated_account_cannot_sign_in(): void
    {
        User::create([
            'username' => 'hreed',
            'email' => 'halcyon.reed@example.test',
            'flags' => ['ts'],
            'active' => false,
        ]);

        $this->fakeProvider([
            'sub' => 'ak-7f3c',
            'email' => 'halcyon.reed@example.test',
            'email_verified' => true,
            'preferred_username' => 'hreed',
            'groups' => ['safety'],
        ]);

        $this->returnFromProvider()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.blocked']);
    }

    #[Test]
    public function signing_out_ends_the_session(): void
    {
        $user = User::create([
            'username' => 'hreed',
            'email' => 'halcyon.reed@example.test',
            'flags' => ['ts'],
        ]);

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout']);
    }

    #[Test]
    public function the_session_endpoint_reports_whether_a_provider_is_configured(): void
    {
        $this->getJson('/api/portal/session')->assertOk()->assertJsonPath('wiki.oidc_configured', true);

        config(['authentik.client_id' => null]);

        $this->getJson('/api/portal/session')->assertOk()->assertJsonPath('wiki.oidc_configured', false);
    }
}
