<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileAndCatchUpTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
        config()->set('attachments.disk', 'r2');
    }

    private function staff(): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => 1],
            ['username' => 'Admin', 'flags' => ['ts', 'admin']],
        );
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('bg.png', base64_decode(self::PNG));
    }

    private function report(string $line = 'A report'): SafetyCase
    {
        return SafetyCase::create([
            'reference' => SafetyCase::nextReference('Harassment'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => $line,
            'status' => SafetyCase::STATUS_RECEIVED,
            'reporter_subject_id' => Subject::forUsername('Halcyon Reed', 42)->id,
        ]);
    }

    #[Test]
    public function a_background_is_stored_and_served_with_its_type(): void
    {
        $url = $this->actingAs($this->staff())
            ->postJson('/api/portal/me/backgrounds', ['file' => $this->png()])
            ->assertOk()->json('url');

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function a_refused_write_is_reported_instead_of_pretending_to_work(): void
    {
        Storage::shouldReceive('disk')->andReturn(new class
        {
            public function put(): bool
            {
                return false;
            }
        });

        $this->actingAs($this->staff())
            ->postJson('/api/portal/me/backgrounds', ['file' => $this->png()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    #[Test]
    public function banners_can_be_uploaded_and_removed(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->postJson('/api/portal/me/banner', ['file' => $this->png()])
            ->assertOk()->assertJsonStructure(['banner_url']);

        $this->getJson("/api/portal/staff/{$user->id}")->assertJsonPath('banner_url', fn ($u) => $u !== null);
        $this->get("/api/portal/banners/{$user->id}")->assertOk();

        $this->deleteJson('/api/portal/me/banner')->assertJsonPath('banner_url', null);
    }

    #[Test]
    public function gradients_need_between_two_and_five_stops(): void
    {
        $this->actingAs($this->staff());

        foreach ([['#112233'], ['#111111', '#222222', '#333333', '#444444', '#555555', '#666666']] as $stops) {
            $this->putJson('/api/portal/me/profile', ['theme' => ['gradient' => ['stops' => $stops, 'angle' => 90]]])
                ->assertStatus(422);
        }

        $this->putJson('/api/portal/me/profile', [
            'bio' => 'Hello **world**',
            'theme' => ['accent' => '#7c5cff', 'gradient' => ['stops' => ['#111111', '#222222', '#333333'], 'angle' => 45]],
        ])->assertOk()->assertJsonPath('profile_theme.gradient.stops.2', '#333333');
    }

    #[Test]
    public function catch_up_lists_unread_open_work_and_reappears_after_new_activity(): void
    {
        $user = $this->staff();
        $case = $this->report();
        $this->actingAs($user);

        $this->getJson('/api/portal/catch-up')->assertJsonPath('meta.unread', 1);

        $this->putJson("/api/portal/catch-up/{$case->id}")->assertJsonPath('unread', 0);
        $this->getJson('/api/portal/catch-up')->assertJsonCount(0, 'data');
        $this->getJson('/api/portal/catch-up?show=all')->assertJsonPath('data.0.unread', false);

        $case->forceFill(['updated_at' => now()->addMinute()])->save();
        $this->getJson('/api/portal/catch-up')->assertJsonCount(1, 'data');

        $this->travel(2)->minutes();
        $this->putJson("/api/portal/catch-up/{$case->id}")->assertJsonPath('unread', 0);
        $this->deleteJson("/api/portal/catch-up/{$case->id}")->assertJsonPath('unread', 1);

        $this->postJson('/api/portal/catch-up/read')->assertJsonPath('unread', 0);
    }
}
