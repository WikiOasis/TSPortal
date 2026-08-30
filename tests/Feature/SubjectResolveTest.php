<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubjectResolveTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::create(['mw_central_id' => 1, 'username' => 'Admin', 'flags' => ['ts', 'admin']]);
    }

    #[Test]
    public function an_account_already_on_file_is_returned_without_asking_the_wiki(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);

        Http::fake();

        $response = $this->actingAs($this->staff())
            ->postJson('/api/portal/subjects/resolve', ['username' => 'halcyon_reed'])
            ->assertOk();

        $this->assertSame($subject->id, $response->json('id'));
        Http::assertNothingSent();
    }

    #[Test]
    public function a_name_the_wiki_confirms_is_created_locally(): void
    {
        config()->set('mediawiki.s2s.push_enabled', true);

        Http::fake([
            '*' => Http::response(['wikioasissafetylookup' => [
                'exists' => true,
                'central_id' => 99,
                'username' => 'Quiet Marlin',
                'registered_at' => '2020-01-01T00:00:00Z',
            ]]),
        ]);

        $response = $this->actingAs($this->staff())
            ->postJson('/api/portal/subjects/resolve', ['username' => 'quiet marlin'])
            ->assertOk();

        $subject = Subject::query()->where('mw_central_id', 99)->firstOrFail();

        $this->assertSame($subject->id, $response->json('id'));
        $this->assertSame('Quiet Marlin', $subject->username);
        $this->assertFalse($response->json('unresolved'));
    }

    #[Test]
    public function a_name_the_wiki_has_never_heard_of_is_not_created(): void
    {
        config()->set('mediawiki.s2s.push_enabled', true);

        Http::fake([
            '*' => Http::response(['wikioasissafetylookup' => ['exists' => false]]),
        ]);

        $this->actingAs($this->staff())
            ->postJson('/api/portal/subjects/resolve', ['username' => 'Nobody At All'])
            ->assertNotFound()
            ->assertJsonPath('error', 'no-such-account');

        $this->assertSame(0, Subject::query()->count());
    }

    #[Test]
    public function a_wiki_that_cannot_be_asked_does_not_pretend_the_account_does_not_exist(): void
    {
        config()->set('mediawiki.s2s.push_enabled', false);

        $this->actingAs($this->staff())
            ->postJson('/api/portal/subjects/resolve', ['username' => 'Halcyon Reed'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'wiki-unreachable');

        $this->assertSame(0, Subject::query()->count());
    }
}
