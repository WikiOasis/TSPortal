<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\BulkActions;
use App\Services\Safety\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BulkActionTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $flags = ['ts', 'admin'], int $id = 1, string $name = 'Admin'): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => $id],
            ['username' => $name, 'flags' => $flags],
        );
    }

    /**
     * @param  list<string>  $accounts
     */
    private function file(array $accounts = ['Halcyon Reed', 'Quiet Marlin']): Investigation
    {
        $file = app(InvestigationService::class)->open(
            ['title' => 'Cross-wiki sockpuppetry', 'premise' => 'Opened by the suite.'],
            $this->staff(),
        );

        app(InvestigationService::class)->addSubjects($file, $accounts);

        return $file->refresh();
    }

    #[Test]
    public function one_action_goes_against_every_account_picked(): void
    {
        $file = $this->file();
        $ids = $file->subjects()->pluck('subjects.id')->all();

        $outcome = app(BulkActions::class)->run($file, $this->staff(), [
            'kind' => BulkActions::KIND_ACTION,
            'subject_ids' => $ids,
            'type' => Sanction::TYPE_WARNING,
            'reason' => 'Sockpuppetry across several wikis.',
        ]);

        $this->assertSame(2, $outcome['done']);
        $this->assertSame(0, $outcome['failed']);

        $this->assertSame(2, $file->sanctions()->count());

        foreach ($file->sanctions()->get() as $sanction) {
            $this->assertSame(Sanction::TYPE_WARNING, $sanction->type);
            $this->assertSame('Sockpuppetry across several wikis.', $sanction->reason);
        }
    }

    #[Test]
    public function an_account_not_on_the_file_is_refused_and_the_rest_go_through(): void
    {
        $file = $this->file();
        $stranger = Subject::forUsername('Nobody In Particular', 99);

        $outcome = app(BulkActions::class)->run($file, $this->staff(), [
            'kind' => BulkActions::KIND_ACTION,
            'subject_ids' => [...$file->subjects()->pluck('subjects.id')->all(), $stranger->id],
            'type' => Sanction::TYPE_WARNING,
            'reason' => 'Sockpuppetry across several wikis.',
        ]);

        $this->assertSame(2, $outcome['done']);
        $this->assertSame(1, $outcome['failed']);

        $refused = collect($outcome['results'])->firstWhere('subject_id', $stranger->id);

        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('Not named on this investigation', $refused['message']);

        $this->assertSame(0, Sanction::query()->where('subject_id', $stranger->id)->count());
    }

    #[Test]
    public function an_erasure_can_be_started_for_several_accounts_at_once(): void
    {
        $file = $this->file();

        $outcome = app(BulkActions::class)->run($file, $this->staff(), [
            'kind' => BulkActions::KIND_ERASURE,
            'subject_ids' => $file->subjects()->pluck('subjects.id')->all(),
            'reason' => 'Accounts belonged to children.',
            'legal_basis' => 'minor',
            'hold' => true,
        ]);

        $this->assertSame(2, $outcome['done']);

        $removals = DataRemoval::query()->where('investigation_id', $file->id)->get();

        $this->assertCount(2, $removals);

        foreach ($removals as $removal) {
            $this->assertSame('minor', $removal->legal_basis);
            $this->assertSame(DataRemoval::STATE_REQUESTED, $removal->state);
        }
    }

    #[Test]
    public function an_account_already_being_erased_is_skipped_rather_than_erased_twice(): void
    {
        $file = $this->file();
        $ids = $file->subjects()->pluck('subjects.id')->all();

        $input = [
            'kind' => BulkActions::KIND_ERASURE,
            'subject_ids' => $ids,
            'reason' => 'Accounts belonged to children.',
            'legal_basis' => 'minor',
            'hold' => true,
        ];

        app(BulkActions::class)->run($file, $this->staff(), $input);
        $outcome = app(BulkActions::class)->run($file, $this->staff(), $input);

        $this->assertSame(0, $outcome['done']);
        $this->assertSame(2, $outcome['failed']);
        $this->assertStringContainsString('Already being erased', $outcome['results'][0]['message']);

        $this->assertSame(2, DataRemoval::query()->where('investigation_id', $file->id)->count());
    }

    #[Test]
    public function nothing_can_be_done_under_a_file_that_is_not_open(): void
    {
        $file = $this->file();

        app(InvestigationService::class)->close($file, $this->staff(), 'Done with.');

        $this->expectException(\InvalidArgumentException::class);

        app(BulkActions::class)->run($file->refresh(), $this->staff(), [
            'kind' => BulkActions::KIND_ACTION,
            'subject_ids' => $file->subjects()->pluck('subjects.id')->all(),
            'type' => Sanction::TYPE_WARNING,
            'reason' => 'Too late.',
        ]);
    }

    #[Test]
    public function deleting_a_wiki_is_not_something_to_do_in_bulk(): void
    {
        $file = $this->file();

        $this->expectException(\InvalidArgumentException::class);

        app(BulkActions::class)->run($file, $this->staff(), [
            'kind' => BulkActions::KIND_ACTION,
            'subject_ids' => $file->subjects()->pluck('subjects.id')->all(),
            'type' => Sanction::TYPE_WIKI_DELETION,
            'reason' => 'No.',
        ]);
    }

    #[Test]
    public function what_was_done_at_once_is_written_to_the_file(): void
    {
        $file = $this->file();

        app(BulkActions::class)->run($file, $this->staff(), [
            'kind' => BulkActions::KIND_ACTION,
            'subject_ids' => $file->subjects()->pluck('subjects.id')->all(),
            'type' => Sanction::TYPE_WARNING,
            'reason' => 'Sockpuppetry across several wikis.',
        ]);

        $log = AuditLog::query()
            ->where('action', 'investigation.bulk-action')
            ->where('target_id', $file->id)
            ->firstOrFail();

        $this->assertSame(2, $log->meta['done']);
        $this->assertSame(0, $log->meta['failed']);
        $this->assertContains('Halcyon Reed', $log->meta['accounts']);
    }

    #[Test]
    public function the_endpoint_runs_it_and_reports_each_account(): void
    {
        $file = $this->file();

        $response = $this->actingAs($this->staff())
            ->postJson("/api/portal/investigations/{$file->id}/bulk-actions", [
                'kind' => 'action',
                'subject_ids' => $file->subjects()->pluck('subjects.id')->all(),
                'type' => Sanction::TYPE_WARNING,
                'reason' => 'Sockpuppetry across several wikis.',
            ])
            ->assertOk()
            ->assertJsonPath('done', 2)
            ->assertJsonPath('failed', 0);

        $this->assertCount(2, $response->json('results'));
        $this->assertCount(2, $response->json('data.sanctions'));
    }

    #[Test]
    public function suspending_several_accounts_needs_the_admin_flag(): void
    {
        $file = $this->file();

        $caseworker = $this->staff(['ts'], 9, 'Caseworker');

        $this->actingAs($caseworker)
            ->postJson("/api/portal/investigations/{$file->id}/bulk-actions", [
                'kind' => 'action',
                'subject_ids' => $file->subjects()->pluck('subjects.id')->all(),
                'type' => Sanction::TYPE_LOCK,
                'reason' => 'Nope.',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'missing-flag');

        $this->assertSame(0, $file->sanctions()->count());
    }

    #[Test]
    public function the_endpoint_wants_an_action_type_when_the_kind_is_an_action(): void
    {
        $file = $this->file();

        $this->actingAs($this->staff())
            ->postJson("/api/portal/investigations/{$file->id}/bulk-actions", [
                'kind' => 'action',
                'subject_ids' => $file->subjects()->pluck('subjects.id')->all(),
                'reason' => 'Nothing said about what to do.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }
}
