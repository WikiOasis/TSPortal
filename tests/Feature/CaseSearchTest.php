<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CaseComment;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\CaseSearch;
use App\Services\Safety\CaseService;
use App\Services\Safety\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CaseSearchTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => 1],
            ['username' => 'Admin', 'flags' => ['ts', 'admin']],
        );
    }

    private function case(array $attributes = []): SafetyCase
    {
        return SafetyCase::create(array_merge([
            'reference' => SafetyCase::nextReference('A case'),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'A case',
        ], $attributes));
    }

    private function found(string $q): array
    {
        return $this->actingAs($this->staff())
            ->getJson('/api/portal/cases?status=&q='.urlencode($q))
            ->assertOk()
            ->json('data.*.reference');
    }

    #[Test]
    public function every_kind_can_be_asked_for_and_they_can_be_combined(): void
    {
        $report = $this->case(['type' => SafetyCase::TYPE_REPORT]);
        $data = $this->case(['type' => SafetyCase::TYPE_DATA]);
        $appeal = $this->case(['type' => SafetyCase::TYPE_APPEAL]);

        $both = $this->actingAs($this->staff())
            ->getJson('/api/portal/cases?status=&type=report,data')
            ->assertOk()
            ->json('data.*.reference');

        $this->assertContains($report->reference, $both);
        $this->assertContains($data->reference, $both);
        $this->assertNotContains($appeal->reference, $both);
    }

    #[Test]
    public function a_data_protection_request_is_findable_on_its_own(): void
    {
        $data = $this->case(['type' => SafetyCase::TYPE_DATA, 'subject_line' => 'Delete my account']);
        $report = $this->case(['type' => SafetyCase::TYPE_REPORT]);

        $only = $this->actingAs($this->staff())
            ->getJson('/api/portal/cases?status=&type=data')
            ->assertOk()
            ->json('data.*.reference');

        $this->assertSame([$data->reference], $only);
        $this->assertNotContains($report->reference, $only);
    }

    #[Test]
    public function an_unrecognised_kind_narrows_to_nothing_rather_than_everything(): void
    {
        $this->case();

        $this->actingAs($this->staff())
            ->getJson('/api/portal/cases?status=&type=nonsense')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function words_reach_the_conversation(): void
    {
        $case = $this->case(['subject_line' => 'Something bland']);

        app(CaseService::class)->comment(
            $case,
            'Checked the CU log; the two accounts are on the same range.',
            CaseComment::VISIBILITY_INTERNAL,
            $this->staff(),
        );

        $this->assertContains($case->reference, $this->found('same range'));
    }

    #[Test]
    public function words_reach_the_wizard_answers(): void
    {
        $case = $this->case([
            'subject_line' => 'Something bland',
            'answers' => ['details' => 'Four diffs on Talk:Pumice, all after I asked them to stop.'],
        ]);

        $this->assertContains($case->reference, $this->found('Pumice'));
    }

    #[Test]
    public function two_words_need_not_be_in_the_same_field(): void
    {
        $case = $this->case([
            'subject_line' => 'Harassment on a talk page',
            'answers' => ['details' => 'It happened on Pumice.'],
        ]);

        $this->assertContains($case->reference, $this->found('harassment pumice'));
    }

    #[Test]
    public function conditions_and_words_work_in_one_string(): void
    {
        $mine = $this->case([
            'subject_line' => 'Harassment',
            'assigned_to' => $this->staff()->id,
        ]);
        $theirs = $this->case(['subject_line' => 'Harassment']);

        $found = $this->found('harassment with:me');

        $this->assertContains($mine->reference, $found);
        $this->assertNotContains($theirs->reference, $found);
    }

    #[Test]
    public function a_quoted_account_name_stays_whole(): void
    {
        $subject = Subject::forUsername('Quiet Marlin', 44);
        $case = $this->case();
        $case->subjects()->attach($subject->id, ['role' => 'reported']);

        $other = $this->case();

        $found = $this->found('about:"Quiet Marlin"');

        $this->assertContains($case->reference, $found);
        $this->assertNotContains($other->reference, $found);
    }

    #[Test]
    public function reports_with_no_file_can_be_singled_out(): void
    {
        $unfiled = $this->case();
        $filed = $this->case();

        app(InvestigationService::class)->open(['title' => 'A file'], $this->staff(), $filed);

        $found = $this->found('is:unfiled');

        $this->assertContains($unfiled->reference, $found);
        $this->assertNotContains($filed->reference, $found);
    }

    #[Test]
    public function a_colon_that_is_not_a_condition_is_treated_as_words(): void
    {
        $case = $this->case(['about' => ['User:Quiet Marlin']]);

        $this->assertContains($case->reference, $this->found('User:Quiet'));
    }

    #[Test]
    public function the_help_and_the_parser_are_the_same_list(): void
    {
        $help = $this->actingAs($this->staff())
            ->getJson('/api/portal/search/help')
            ->assertOk()
            ->json('data');

        $search = new CaseSearch;

        foreach ($help as $row) {
            [$terms, $filters] = $search->parse($row['example']);

            $this->assertNotEmpty(
                $filters,
                "The help offers {$row['example']}, which the parser reads as plain words.",
            );
            $this->assertSame('', $terms, "{$row['example']} left words behind.");
        }
    }
}
