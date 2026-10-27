<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InlineImage;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InlineImageTest extends TestCase
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

    private function report(): SafetyCase
    {
        return SafetyCase::create([
            'reference' => SafetyCase::nextReference('Harassment'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'Harassment across several talk pages',
            'status' => SafetyCase::STATUS_RECEIVED,
            'reporter_subject_id' => Subject::forUsername('Halcyon Reed', 42)->id,
        ]);
    }

    private function file(): Investigation
    {
        return app(InvestigationService::class)->open(['title' => 'A file'], $this->staff(), $this->report());
    }

    private function png(string $name = 'screenshot.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    #[Test]
    public function a_picture_on_an_investigation_goes_to_the_attachments_disk(): void
    {
        $file = $this->file();

        $response = $this->actingAs($this->staff())
            ->post("/api/portal/investigations/{$file->id}/images", ['file' => $this->png()], ['Accept' => 'application/json'])
            ->assertCreated();

        $image = InlineImage::query()->sole();

        $this->assertSame('r2', $image->disk);
        $this->assertSame($file->id, $image->investigation_id);
        $this->assertSame('image/png', $image->mime);
        $this->assertStringStartsWith('inline-images/', $image->path);
        Storage::disk('r2')->assertExists($image->path);

        $response->assertJson([
            'uuid' => $image->uuid,
            'token' => "![screenshot.png](image:{$image->uuid})",
        ]);
    }

    #[Test]
    public function staff_can_read_it_back(): void
    {
        $case = $this->report();

        $uuid = $this->actingAs($this->staff())
            ->post("/api/portal/cases/{$case->id}/images", ['file' => $this->png()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('uuid');

        $response = $this->get("/api/portal/inline-images/{$uuid}")->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame(base64_decode(self::PNG), $response->streamedContent());
    }

    #[Test]
    public function only_staff_can_read_it(): void
    {
        $case = $this->report();

        $uuid = $this->actingAs($this->staff())
            ->post("/api/portal/cases/{$case->id}/images", ['file' => $this->png()], ['Accept' => 'application/json'])
            ->json('uuid');

        $outsider = User::create(['username' => 'Outsider', 'mw_central_id' => 2, 'flags' => []]);

        $this->actingAs($outsider)->getJson("/api/portal/inline-images/{$uuid}")->assertForbidden();
    }

    #[Test]
    public function something_that_is_not_a_picture_is_refused(): void
    {
        $case = $this->report();

        $this->actingAs($this->staff())
            ->post(
                "/api/portal/cases/{$case->id}/images",
                ['file' => UploadedFile::fake()->createWithContent('x.png', '<?php echo 1; ?>')],
                ['Accept' => 'application/json'],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, InlineImage::query()->count());
    }

    #[Test]
    public function a_picture_can_go_in_an_internal_note_but_not_a_reply_to_the_reporter(): void
    {
        $case = $this->report();
        $body = "Here it is\n![shot.png](image:0b4d8a5e-8f1c-4a52-9a7c-6c2f4f0f0f0f)";

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/comments", ['body' => $body, 'visibility' => 'internal'])
            ->assertCreated();

        $this->postJson("/api/portal/cases/{$case->id}/comments", ['body' => $body, 'visibility' => 'public'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->assertSame(1, $case->comments()->count());
    }

    #[Test]
    public function a_quoted_reply_to_the_reporter_cannot_quote_an_internal_note(): void
    {
        $case = $this->report();
        $this->actingAs($this->staff());

        $internal = $this->postJson("/api/portal/cases/{$case->id}/comments", ['body' => 'Between us', 'visibility' => 'internal'])
            ->assertCreated()->json('id');

        $this->postJson("/api/portal/cases/{$case->id}/comments", ['body' => 'Hello', 'visibility' => 'public', 'reply_to' => $internal])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reply_to');

        $this->postJson("/api/portal/cases/{$case->id}/comments", ['body' => 'Agreed', 'visibility' => 'internal', 'reply_to' => $internal])
            ->assertCreated()
            ->assertJson(['reply_to' => $internal]);

        $this->getJson("/api/portal/cases/{$case->id}")
            ->assertJsonPath('data.comments.1.reply_to', $internal);
    }

    #[Test]
    public function an_action_reason_cannot_carry_a_picture(): void
    {
        $file = $this->file();
        $subject = Subject::forUsername('Quiet Marlin', 44);

        $this->actingAs($this->staff())
            ->postJson('/api/portal/actions', [
                'type' => 'warning',
                'subject_id' => $subject->id,
                'reason' => '**Harassment** ![x](image:0b4d8a5e-8f1c-4a52-9a7c-6c2f4f0f0f0f)',
                'investigation_reference' => $file->reference,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }
}
