<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\SafetyCase;
use App\Models\User;
use App\Services\Safety\AttachmentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\SignsWikiRequests;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;
    use SignsWikiRequests;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('attachments.disk', 'local');
    }

    private function staff(): User
    {
        return User::create([
            'username' => 'Reviewer',
            'mw_central_id' => 900,
            'flags' => [User::FLAG_TS],
            'active' => true,
        ]);
    }

    private function submit(array $attachments = []): SafetyCase
    {
        $this->wikiPost('/api/wiki/v1/submissions', [
            'type' => 'report',
            'wiki' => 'oasiswiki',
            'reporter' => ['central_id' => 7, 'username' => 'Halcyon Reed'],
            'answers' => ['report' => 'harassment'],
            'attachments' => $attachments,
        ])->assertStatus(201);

        return SafetyCase::query()->latest('id')->firstOrFail();
    }

    private function upload(SafetyCase $case, array $body = []): TestResponse
    {
        return $this->wikiPostFrom('oasiswiki', '/api/wiki/v1/cases/'.$case->reference.'/attachments', $body + [
            'name' => 'screenshot.png',
            'type' => 'image/png',
            'content' => self::PNG,
            'central_id' => 7,
            'username' => 'Halcyon Reed',
        ]);
    }

    #[Test]
    public function a_submission_records_the_files_it_says_are_coming(): void
    {
        $case = $this->submit([
            ['name' => 'screenshot.png', 'type' => 'image/png', 'size' => 70, 'modified' => 1690000000000],
        ]);

        $attachment = $case->attachments()->sole();

        $this->assertSame('pending', $attachment->state());
        $this->assertFalse($attachment->hasFile());
        $this->assertNull($attachment->refused_reason);
    }

    #[Test]
    public function the_bytes_fill_in_the_row_the_submission_already_made(): void
    {
        $case = $this->submit([
            ['name' => 'screenshot.png', 'type' => 'image/png', 'size' => strlen(base64_decode(self::PNG))],
        ]);

        $this->upload($case)->assertStatus(201)->assertJson(['stored' => true]);

        $this->assertSame(1, $case->attachments()->count());

        $attachment = $case->attachments()->sole();
        $this->assertSame('stored', $attachment->state());
        Storage::disk('local')->assertExists($attachment->path);
    }

    #[Test]
    public function the_disk_it_went_to_is_written_on_the_row(): void
    {
        $case = $this->submit();
        $this->upload($case)->assertStatus(201);

        $attachment = $case->attachments()->sole();

        $this->assertSame('local', $attachment->disk);

        config()->set('attachments.disk', 's3');

        $this->assertSame('local', $attachment->fresh()->disk);
        Storage::disk('local')->assertExists($attachment->path);
    }

    #[Test]
    public function the_stored_name_is_random_and_carries_the_detected_extension(): void
    {
        $case = $this->submit();
        $this->upload($case, ['name' => '../../etc/passwd.png'])->assertStatus(201);

        $attachment = $case->attachments()->sole();

        $this->assertSame('passwd.png', $attachment->name);
        $this->assertStringNotContainsString('passwd', (string) $attachment->path);
        $this->assertStringNotContainsString('..', (string) $attachment->path);
        $this->assertStringEndsWith('.png', (string) $attachment->path);
    }

    #[Test]
    public function the_type_is_read_from_the_bytes_and_not_from_what_the_upload_claimed(): void
    {
        $case = $this->submit();

        $this->upload($case, [
            'name' => 'screenshot.png',
            'type' => 'image/png',
            'content' => base64_encode('<?php echo "not a picture"; ?>'),
        ])->assertStatus(201)->assertJson(['stored' => false]);

        $attachment = $case->attachments()->sole();

        $this->assertSame('refused', $attachment->state());
        $this->assertFalse($attachment->hasFile());
        $this->assertNotNull($attachment->refused_reason);
        $this->assertSame(0, count(Storage::disk('local')->allFiles()));
    }

    #[Test]
    public function a_file_over_the_limit_is_refused_with_the_size_in_the_reason(): void
    {
        config()->set('attachments.max_bytes', 32);

        $case = $this->submit();
        $this->upload($case)->assertStatus(201)->assertJson(['stored' => false]);

        $attachment = $case->attachments()->sole();

        $this->assertSame('refused', $attachment->state());
        $this->assertStringContainsString('limit', (string) $attachment->refused_reason);
    }

    #[Test]
    public function the_same_file_twice_is_stored_once(): void
    {
        $case = $this->submit();

        $this->upload($case)->assertStatus(201);
        $this->upload($case, ['name' => 'screenshot-again.png'])->assertStatus(201);

        $this->assertSame(1, $case->attachments()->whereNotNull('path')->count());
        $this->assertSame(1, count(Storage::disk('local')->allFiles()));
    }

    #[Test]
    public function a_wiki_cannot_attach_a_file_to_another_wikis_case(): void
    {
        $case = $this->submit();
        $case->forceFill(['wiki' => 'someotherwiki'])->save();

        $this->upload($case)->assertStatus(404);

        $this->assertSame(0, $case->attachments()->count());
    }

    #[Test]
    public function the_caller_has_to_know_whose_case_it_is(): void
    {
        $case = $this->submit();

        $this->upload($case, ['central_id' => 999, 'username' => 'Someone Else'])->assertStatus(404);

        $this->assertSame(0, $case->attachments()->whereNotNull('path')->count());
    }

    #[Test]
    public function an_anonymous_report_has_no_account_to_check_against(): void
    {
        $this->wikiPost('/api/wiki/v1/submissions', [
            'type' => 'report',
            'wiki' => 'oasiswiki',
            'anonymous' => true,
            'answers' => ['report' => 'harassment'],
        ])->assertStatus(201);

        $case = SafetyCase::query()->latest('id')->firstOrFail();

        $this->wikiPostFrom('oasiswiki', '/api/wiki/v1/cases/'.$case->reference.'/attachments', [
            'name' => 'screenshot.png',
            'type' => 'image/png',
            'content' => self::PNG,
        ])->assertStatus(201)->assertJson(['stored' => true]);
    }

    #[Test]
    public function a_closed_case_does_not_take_new_evidence_quietly(): void
    {
        $case = $this->submit();
        $case->forceFill(['status' => SafetyCase::STATUS_CLOSED])->save();

        $this->upload($case)
            ->assertStatus(409)
            ->assertJsonPath('error', 'closed');
    }

    #[Test]
    public function content_that_is_not_base64_is_refused_rather_than_stored_corrupt(): void
    {
        $case = $this->submit();

        $this->upload($case, ['content' => 'this is not base64 !!!'])->assertStatus(422);

        $this->assertSame(0, $case->attachments()->whereNotNull('path')->count());
    }

    #[Test]
    public function a_reviewer_downloading_a_file_gets_the_bytes_and_leaves_a_line(): void
    {
        $case = $this->submit();
        $this->upload($case)->assertStatus(201);

        $attachment = $case->attachments()->sole();

        $response = $this->actingAs($this->staff())
            ->get('/api/portal/attachments/'.$attachment->id)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame(base64_decode(self::PNG), $response->streamedContent());

        $this->assertTrue(AuditLog::query()->where('action', 'attachment.read')->exists());
    }

    #[Test]
    public function a_file_that_is_not_there_says_which_kind_of_not_there_it_is(): void
    {
        $case = $this->submit([['name' => 'screenshot.png', 'type' => 'image/png']]);

        $this->actingAs($this->staff())
            ->getJson('/api/portal/attachments/'.$case->attachments()->sole()->id)
            ->assertStatus(404)
            ->assertJsonPath('state', 'pending');
    }

    #[Test]
    public function nobody_signed_out_can_download_anything(): void
    {
        $case = $this->submit();
        $this->upload($case)->assertStatus(201);

        $this->getJson('/api/portal/attachments/'.$case->attachments()->sole()->id)
            ->assertStatus(401);
    }

    #[Test]
    public function forgetting_a_file_takes_the_bytes_and_keeps_the_row(): void
    {
        $case = $this->submit();
        $this->upload($case)->assertStatus(201);

        $attachment = $case->attachments()->sole();
        $path = $attachment->path;

        app(AttachmentStore::class)->forget($attachment, 'Erased on request.');

        Storage::disk('local')->assertMissing($path);

        $attachment->refresh();
        $this->assertSame('refused', $attachment->state());
        $this->assertSame('Erased on request.', $attachment->refused_reason);
        $this->assertSame(1, Attachment::query()->count());
    }

    #[Test]
    public function the_wiki_is_told_what_the_portal_will_take(): void
    {
        config()->set('attachments.max_bytes', 1024);

        $this->wikiGet('/api/wiki/v1/health')
            ->assertOk()
            ->assertJsonPath('attachments.enabled', true)
            ->assertJsonPath('attachments.max_bytes', 1024);
    }

    #[Test]
    public function a_declared_type_that_is_not_a_mime_type_is_not_recorded(): void
    {
        $case = $this->submit([[
            'name' => 'screenshot.png',
            'type' => "text/html\r\nX-Injected: yes",
        ]]);

        $this->assertNull($case->attachments()->firstOrFail()->mime);
    }
}
