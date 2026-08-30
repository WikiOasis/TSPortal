<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PortalFlagsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => 1],
            ['username' => 'Admin', 'flags' => ['ts', 'user-manager', 'admin']],
        );
    }

    /**
     * @return list<string>
     */
    private function endpoints(): array
    {
        $case = SafetyCase::query()->firstOrFail();
        $file = Investigation::query()->firstOrFail();
        $subject = Subject::query()->firstOrFail();
        $removal = DataRemoval::query()->firstOrFail();
        $sanction = Sanction::query()->firstOrFail();

        return [
            '/api/portal/session',
            '/api/portal/dashboard',

            '/api/portal/cases',
            '/api/portal/cases?status=&sort=priority',
            '/api/portal/cases?status=&q='.urlencode('harassment type:report,data with:me is:unfiled'),
            "/api/portal/cases/{$case->id}",
            "/api/portal/cases/{$case->id}/timeline",

            '/api/portal/investigations',
            '/api/portal/investigations?live=1&sort=review',
            "/api/portal/investigations/{$file->id}",
            "/api/portal/investigations/{$file->id}/timeline",

            '/api/portal/subjects',
            "/api/portal/subjects/{$subject->id}",

            '/api/portal/sanctions',
            '/api/portal/sanctions?push_state=partial,failed,manual',

            '/api/portal/removals',
            '/api/portal/removals?outstanding=1',
            "/api/portal/removals/{$removal->id}",

            '/api/portal/wikis',
            '/api/portal/wikis?for=closeable',

            '/api/portal/audit',
            '/api/portal/staff',

            '/api/portal/search/help',
            '/api/portal/objects/search?q=TS-2026',
            "/api/portal/objects/{$case->reference}",
            "/api/portal/objects/{$sanction->reference}",
        ];
    }

    #[Test]
    public function every_read_endpoint_renders(): void
    {
        $this->seed(DemoSeeder::class);

        $staff = $this->staff();
        $failures = [];

        foreach ($this->endpoints() as $endpoint) {
            $response = $this->actingAs($staff)->getJson($endpoint);

            if ($response->status() !== 200) {
                $failures[] = sprintf(
                    '%s → %d %s',
                    $endpoint,
                    $response->status(),
                    (string) ($response->json('message') ?? ''),
                );
            }
        }

        $this->assertSame([], $failures, "These endpoints did not render:\n".implode("\n", $failures));
    }

    #[Test]
    public function every_read_endpoint_refuses_a_stranger(): void
    {
        $this->seed(DemoSeeder::class);

        $endpoints = $this->endpoints();
        $leaked = [];

        foreach ($endpoints as $endpoint) {
            if (str_ends_with($endpoint, '/api/portal/session')) {
                continue;
            }

            if ($this->getJson($endpoint)->status() !== 401) {
                $leaked[] = $endpoint;
            }
        }

        $this->assertSame([], $leaked, "These answered a signed-out caller:\n".implode("\n", $leaked));
    }

    #[Test]
    public function every_read_endpoint_refuses_someone_without_the_flag(): void
    {
        $this->seed(DemoSeeder::class);

        $newcomer = User::create(['mw_central_id' => 77, 'username' => 'Newcomer', 'flags' => []]);
        $leaked = [];

        foreach ($this->endpoints() as $endpoint) {
            if (str_ends_with($endpoint, '/api/portal/session')) {
                continue;
            }

            if ($this->actingAs($newcomer)->getJson($endpoint)->status() !== 403) {
                $leaked[] = $endpoint;
            }
        }

        $this->assertSame([], $leaked, "These answered an account with no flags:\n".implode("\n", $leaked));
    }
}
