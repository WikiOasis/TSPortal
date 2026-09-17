<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CaseCategory;
use App\Models\CaseComment;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\DuplicateReports;
use App\Services\Safety\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DuplicateCaseTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $flags = ['ts', 'admin']): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => 1],
            ['username' => 'Admin', 'flags' => $flags],
        );
    }

    private function report(string $line = 'Harassment on a talk page', ?Subject $reporter = null): SafetyCase
    {
        return SafetyCase::create([
            'reference' => SafetyCase::nextReference($line),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => $line,
            'status' => SafetyCase::STATUS_RECEIVED,
            'reporter_subject_id' => $reporter?->id,
        ]);
    }

    #[Test]
    public function merging_closes_the_duplicate_and_points_it_at_the_one_it_duplicates(): void
    {
        $first = $this->report('The original');
        $second = $this->report('The same thing again');

        app(DuplicateReports::class)->merge($second, $first, $this->staff(), 'Same incident.');

        $second->refresh();

        $this->assertSame(SafetyCase::STATUS_DUPLICATE, $second->status);
        $this->assertSame($first->id, $second->duplicate_of_id);
        $this->assertSame('Same incident.', $second->duplicate_note);
        $this->assertNotNull($second->closed_at);
        $this->assertFalse($second->isOpen());

        $this->assertSame([$second->id], $first->duplicates()->pluck('id')->all());
    }

    #[Test]
    public function whoever_filed_the_duplicate_is_told_it_is_one(): void
    {
        $reporter = Subject::forUsername('Halcyon Reed', 42);

        $first = $this->report('The original');
        $second = $this->report('The same thing again', $reporter);

        app(DuplicateReports::class)->merge($second, $first, $this->staff());

        $public = $second->comments()
            ->where('visibility', CaseComment::VISIBILITY_PUBLIC)
            ->pluck('body')
            ->implode("\n");

        $this->assertStringContainsString('duplicate', $public);
    }

    #[Test]
    public function what_the_reporter_is_told_does_not_name_the_other_report(): void
    {
        $reporter = Subject::forUsername('Halcyon Reed', 42);

        $first = $this->report('The original');
        $second = $this->report('The same thing again', $reporter);

        app(DuplicateReports::class)->merge($second, $first, $this->staff(), 'Filed twice within the hour.');

        foreach ($second->comments()->where('visibility', CaseComment::VISIBILITY_PUBLIC)->get() as $comment) {
            $this->assertStringNotContainsString($first->reference, $comment->body);
            $this->assertStringNotContainsString('Filed twice within the hour.', $comment->body);
        }
    }

    #[Test]
    public function the_accounts_and_categories_it_named_move_onto_the_one_it_duplicates(): void
    {
        $named = Subject::forUsername('Quiet Marlin', 43);

        $first = $this->report('The original');
        $second = $this->report('The same thing again');

        $second->subjects()->attach($named->id, ['role' => 'reported']);
        CaseCategory::create([
            'case_id' => $second->id,
            'category' => 'harassment',
            'label' => 'Harassment',
            'is_primary' => true,
        ]);

        app(DuplicateReports::class)->merge($second, $first, $this->staff());

        $this->assertSame([$named->id], $first->subjects()->pluck('subjects.id')->all());
        $this->assertSame(['harassment'], $first->categories()->pluck('category')->all());
    }

    #[Test]
    public function accounts_carried_over_join_the_investigation_the_other_report_is_on(): void
    {
        $named = Subject::forUsername('Quiet Marlin', 43);

        $first = $this->report('The original');
        $file = app(InvestigationService::class)->open(['title' => 'A file'], $this->staff(), $first);

        $second = $this->report('The same thing again');
        $second->subjects()->attach($named->id, ['role' => 'reported']);

        app(DuplicateReports::class)->merge($second, $first->refresh(), $this->staff());

        $this->assertContains($named->id, $file->subjects()->pluck('subjects.id')->all());
    }

    #[Test]
    public function merging_into_something_already_merged_follows_it_to_the_real_one(): void
    {
        $root = $this->report('The original');
        $middle = $this->report('The second');
        $last = $this->report('The third');

        $duplicates = app(DuplicateReports::class);

        $duplicates->merge($middle, $root, $this->staff());
        $duplicates->merge($last, $middle->refresh(), $this->staff());

        $this->assertSame($root->id, $last->refresh()->duplicate_of_id);
    }

    #[Test]
    public function merging_a_report_that_already_has_duplicates_takes_them_with_it(): void
    {
        $root = $this->report('The original');
        $middle = $this->report('The second');
        $tail = $this->report('The third');

        $duplicates = app(DuplicateReports::class);

        $duplicates->merge($tail, $middle, $this->staff());
        $duplicates->merge($middle->refresh(), $root, $this->staff());

        $this->assertSame($root->id, $tail->refresh()->duplicate_of_id);
        $this->assertSame($root->id, $middle->refresh()->duplicate_of_id);
    }

    #[Test]
    public function a_report_cannot_duplicate_itself(): void
    {
        $case = $this->report();

        $this->expectException(\InvalidArgumentException::class);

        app(DuplicateReports::class)->merge($case, $case, $this->staff());
    }

    #[Test]
    public function two_different_kinds_are_not_duplicates_of_each_other(): void
    {
        $report = $this->report();

        $appeal = SafetyCase::create([
            'reference' => SafetyCase::nextReference('An appeal'),
            'type' => SafetyCase::TYPE_APPEAL,
            'subject_line' => 'An appeal',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(DuplicateReports::class)->merge($appeal, $report, $this->staff());
    }

    #[Test]
    public function taking_it_back_out_reopens_it_and_forgets_the_link(): void
    {
        $first = $this->report('The original');
        $second = $this->report('The same thing again');

        $duplicates = app(DuplicateReports::class);

        $duplicates->merge($second, $first, $this->staff());
        $duplicates->unmerge($second->refresh(), $this->staff());

        $second->refresh();

        $this->assertNull($second->duplicate_of_id);
        $this->assertSame(SafetyCase::STATUS_IN_REVIEW, $second->status);
        $this->assertTrue($second->isOpen());
    }

    #[Test]
    public function the_endpoint_merges_by_reference(): void
    {
        $first = $this->report('The original');
        $second = $this->report('The same thing again');

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$second->id}/duplicate", [
                'of' => $first->reference,
                'note' => 'Same incident.',
            ])
            ->assertOk()
            ->assertJsonPath('of', $first->reference)
            ->assertJsonPath('case.status', SafetyCase::STATUS_DUPLICATE)
            ->assertJsonPath('case.duplicate_of.reference', $first->reference);
    }

    #[Test]
    public function the_endpoint_says_so_when_there_is_no_such_report(): void
    {
        $case = $this->report();

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/duplicate", ['of' => 'TS-2026-9999'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'no-such-report');
    }

    #[Test]
    public function a_case_cannot_be_closed_as_a_duplicate_through_the_plain_status_change(): void
    {
        $case = $this->report();

        $this->actingAs($this->staff())
            ->patchJson("/api/portal/cases/{$case->id}", ['status' => SafetyCase::STATUS_DUPLICATE])
            ->assertStatus(422);

        $this->assertSame(SafetyCase::STATUS_RECEIVED, $case->refresh()->status);
    }

    #[Test]
    public function moving_a_duplicate_to_another_status_forgets_the_link(): void
    {
        $first = $this->report('The original');
        $second = $this->report('The same thing again');

        app(DuplicateReports::class)->merge($second, $first, $this->staff());

        $this->actingAs($this->staff())
            ->patchJson("/api/portal/cases/{$second->id}", ['status' => SafetyCase::STATUS_IN_REVIEW])
            ->assertOk();

        $this->assertNull($second->refresh()->duplicate_of_id);
    }

    #[Test]
    public function the_candidates_list_offers_a_report_about_the_same_account(): void
    {
        $named = Subject::forUsername('Quiet Marlin', 43);

        $first = $this->report('The original');
        $first->subjects()->attach($named->id, ['role' => 'reported']);

        $second = $this->report('The same thing again');
        $second->subjects()->attach($named->id, ['role' => 'reported']);

        $unrelated = $this->report('Something else entirely');
        $unrelated->subjects()->attach(Subject::forUsername('Someone Else', 44)->id, ['role' => 'reported']);

        $response = $this->actingAs($this->staff())
            ->getJson("/api/portal/cases/{$second->id}/duplicates/candidates")
            ->assertOk();

        $offered = array_column($response->json('data'), 'reference');

        $this->assertContains($first->reference, $offered);
        $this->assertNotContains($unrelated->reference, $offered);
        $this->assertNotContains($second->reference, $offered);
    }

    #[Test]
    public function the_timeline_shows_the_merge_on_both_reports(): void
    {
        $first = $this->report('The original');
        $second = $this->report('The same thing again');

        app(DuplicateReports::class)->merge($second, $first, $this->staff());

        $onDuplicate = $this->actingAs($this->staff())
            ->getJson("/api/portal/cases/{$second->id}/timeline")
            ->assertOk()
            ->json('data');

        $onOriginal = $this->actingAs($this->staff())
            ->getJson("/api/portal/cases/{$first->id}/timeline")
            ->assertOk()
            ->json('data');

        $this->assertContains('duplicate', array_column($onDuplicate, 'kind'));
        $this->assertContains('duplicate', array_column($onOriginal, 'kind'));
    }

    #[Test]
    public function an_investigation_conclusion_leaves_a_duplicate_alone(): void
    {
        $first = $this->report('The original');
        $second = $this->report('The same thing again');

        $file = app(InvestigationService::class)->open(['title' => 'A file'], $this->staff(), $first);

        app(InvestigationService::class)->attachCase($file, $second, $this->staff());
        app(DuplicateReports::class)->merge($second->refresh(), $first->refresh(), $this->staff());

        app(InvestigationService::class)->conclude($file, $this->staff(), [
            'outcome' => Investigation::OUTCOME_WARNED,
        ]);

        $this->assertSame(SafetyCase::STATUS_DUPLICATE, $second->refresh()->status);
    }
}
