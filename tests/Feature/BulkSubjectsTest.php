<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Investigation;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BulkSubjectsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => 1],
            ['username' => 'Admin', 'flags' => ['ts', 'admin']],
        );
    }

    private function file(): Investigation
    {
        return app(InvestigationService::class)->open(
            ['title' => 'Cross-wiki sockpuppetry'],
            $this->staff(),
        );
    }

    #[Test]
    public function a_pasted_list_is_read_into_account_names(): void
    {
        $names = InvestigationService::namesIn(<<<'TEXT'
        * [[User:Halcyon Reed]]
        # User talk:Quiet Marlin
        Sandpiper_99
        Special:Contributions/Ordinary Wren


        TEXT);

        $this->assertSame(
            ['Halcyon Reed', 'Quiet Marlin', 'Sandpiper_99', 'Ordinary Wren'],
            $names,
        );
    }

    #[Test]
    public function commas_pipes_and_semicolons_separate_names_too(): void
    {
        $this->assertSame(
            ['Halcyon Reed', 'Quiet Marlin', 'Ordinary Wren'],
            InvestigationService::namesIn('Halcyon Reed|Quiet Marlin; Ordinary Wren'),
        );
    }

    #[Test]
    public function every_name_is_put_on_the_file(): void
    {
        $file = $this->file();

        $report = app(InvestigationService::class)->addSubjects(
            $file,
            ['Halcyon Reed', 'Quiet Marlin', 'Sandpiper_99'],
        );

        $this->assertCount(3, $report['added']);
        $this->assertSame([], $report['skipped']);

        $this->assertSame(
            ['Halcyon Reed', 'Quiet Marlin', 'Sandpiper 99'],
            $file->subjects()->pluck('username')->all(),
        );
    }

    #[Test]
    public function a_name_already_on_the_file_is_left_alone(): void
    {
        $file = $this->file();
        $service = app(InvestigationService::class);

        $service->addSubjects($file, ['Halcyon Reed']);

        $report = $service->addSubjects($file->refresh(), ['halcyon_reed', 'Quiet Marlin']);

        $this->assertSame(['Quiet Marlin'], $report['added']);
        $this->assertCount(1, $report['skipped']);
        $this->assertSame('Already on the file.', $report['skipped'][0]['why']);

        $this->assertSame(2, $file->subjects()->count());
    }

    #[Test]
    public function a_name_given_twice_in_one_paste_is_only_added_once(): void
    {
        $file = $this->file();

        $report = app(InvestigationService::class)->addSubjects(
            $file,
            ['Halcyon Reed', 'halcyon reed'],
        );

        $this->assertSame(['Halcyon Reed'], $report['added']);
        $this->assertCount(1, $report['skipped']);
        $this->assertSame('Named twice in what was pasted.', $report['skipped'][0]['why']);
    }

    #[Test]
    public function the_role_and_note_are_kept_against_each_of_them(): void
    {
        $file = $this->file();

        app(InvestigationService::class)->addSubjects(
            $file,
            ['Halcyon Reed', 'Quiet Marlin'],
            'witness',
            'From the sockpuppet report.',
        );

        foreach ($file->subjects()->get() as $subject) {
            $this->assertSame('witness', $subject->pivot->role);
            $this->assertSame('From the sockpuppet report.', $subject->pivot->note);
        }
    }

    #[Test]
    public function adding_several_is_one_line_on_the_file_rather_than_one_each(): void
    {
        $file = $this->file();

        app(InvestigationService::class)->addSubjects($file, ['Halcyon Reed', 'Quiet Marlin']);

        $this->assertSame(
            0,
            AuditLog::query()->where('action', 'investigation.subject-added')->count(),
        );

        $log = AuditLog::query()
            ->where('action', 'investigation.subjects-added')
            ->where('target_id', $file->id)
            ->firstOrFail();

        $this->assertSame(['Halcyon Reed', 'Quiet Marlin'], $log->meta['subjects']);
    }

    #[Test]
    public function the_endpoint_takes_a_pasted_block_and_returns_the_file(): void
    {
        $file = $this->file();

        $response = $this->actingAs($this->staff())
            ->postJson("/api/portal/investigations/{$file->id}/subjects/bulk", [
                'text' => "* [[User:Halcyon Reed]]\nQuiet Marlin\nHalcyon Reed",
                'role' => 'subject',
            ])
            ->assertOk();

        $this->assertSame(['Halcyon Reed', 'Quiet Marlin'], $response->json('added'));
        $this->assertCount(1, $response->json('skipped'));
        $this->assertCount(2, $response->json('data.subjects'));
    }

    #[Test]
    public function the_endpoint_says_so_when_it_can_read_no_names(): void
    {
        $file = $this->file();

        $this->actingAs($this->staff())
            ->postJson("/api/portal/investigations/{$file->id}/subjects/bulk", ['text' => "  \n * \n"])
            ->assertStatus(422)
            ->assertJsonPath('error', 'nothing-named');
    }

    #[Test]
    public function the_preview_endpoint_reads_the_same_names_without_saving_anything(): void
    {
        $this->actingAs($this->staff())
            ->postJson('/api/portal/investigations/subjects/preview', [
                'text' => "* [[User:Halcyon Reed]]\nQuiet Marlin",
            ])
            ->assertOk()
            ->assertJsonPath('data', ['Halcyon Reed', 'Quiet Marlin']);

        $this->assertSame(0, Subject::query()->count());
    }
}
