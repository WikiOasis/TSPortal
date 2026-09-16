<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CaseComment;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PortalSearchTest extends TestCase
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

    /** @return list<string> */
    private function found(array $params): array
    {
        return $this->actingAs($this->staff())
            ->getJson('/api/portal/search?'.http_build_query($params))
            ->assertOk()
            ->json('data.*.title');
    }

    #[Test]
    public function it_finds_a_case_by_its_title_and_by_its_reference(): void
    {
        $case = $this->case(['subject_line' => 'Persistent harassment on the help desk']);

        $this->assertContains($case->subject_line, $this->found(['q' => 'harassment']));
        $this->assertContains($case->subject_line, $this->found(['q' => $case->reference]));
    }

    #[Test]
    public function it_finds_a_case_by_a_phrase_buried_in_a_comment(): void
    {
        $case = $this->case(['subject_line' => 'Something unremarkable']);

        CaseComment::create([
            'case_id' => $case->id,
            'author_type' => CaseComment::AUTHOR_STAFF,
            'author_label' => 'Admin',
            'body' => 'They turned up at my workplace on Tuesday.',
            'visibility' => CaseComment::VISIBILITY_INTERNAL,
        ]);

        $this->assertContains($case->subject_line, $this->found(['q' => 'workplace']));
    }

    #[Test]
    public function title_only_looks_no_further_than_the_title(): void
    {
        $case = $this->case(['subject_line' => 'Something unremarkable', 'summary' => 'A row about semicolons']);

        $this->assertContains($case->subject_line, $this->found(['q' => 'semicolons']));
        $this->assertNotContains($case->subject_line, $this->found(['q' => 'semicolons', 'titles_only' => 1]));
    }

    #[Test]
    public function it_can_be_held_to_one_kind_of_thing(): void
    {
        $case = $this->case(['subject_line' => 'Sockpuppetry on the wiki']);

        $investigation = Investigation::create([
            'reference' => 'TS-F-2026-0001',
            'title' => 'Sockpuppetry ring',
            'opened_at' => now(),
        ]);

        $everywhere = $this->found(['q' => 'sockpuppetry']);
        $this->assertContains($case->subject_line, $everywhere);
        $this->assertContains($investigation->title, $everywhere);

        $files = $this->found(['q' => 'sockpuppetry', 'kinds' => 'investigation']);
        $this->assertSame([$investigation->title], $files);
    }

    #[Test]
    public function it_can_be_held_to_what_is_open_and_to_what_is_yours(): void
    {
        $staff = $this->staff();

        $mine = $this->case([
            'subject_line' => 'Threats by email',
            'assigned_to' => $staff->id,
        ]);

        $theirs = $this->case(['subject_line' => 'Threats by post']);

        $closed = $this->case([
            'subject_line' => 'Threats, long settled',
            'assigned_to' => $staff->id,
            'status' => SafetyCase::STATUS_CLOSED,
        ]);

        $titles = $this->found(['q' => 'threats', 'mine' => 1]);

        $this->assertContains($mine->subject_line, $titles);
        $this->assertNotContains($theirs->subject_line, $titles);

        $open = $this->found(['q' => 'threats', 'mine' => 1, 'open_only' => 1]);

        $this->assertContains($mine->subject_line, $open);
        $this->assertNotContains($closed->subject_line, $open);
    }

    #[Test]
    public function every_row_says_where_it_goes_and_what_it_is(): void
    {
        $this->case(['subject_line' => 'A report to open']);

        $row = $this->actingAs($this->staff())
            ->getJson('/api/portal/search?q=report')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('case', $row['kind']);
        $this->assertSame('Report', $row['kind_label']);
        $this->assertSame('case', $row['route']);
        $this->assertSame('case', $row['status_of']);
        $this->assertNotNull($row['reference']);
    }

    #[Test]
    public function the_empty_box_offers_what_the_viewer_has_open(): void
    {
        $staff = $this->staff();

        $mine = $this->case(['subject_line' => 'Waiting on me', 'assigned_to' => $staff->id]);
        $this->case(['subject_line' => 'Waiting on somebody else']);

        $titles = $this->actingAs($staff)
            ->getJson('/api/portal/search/recents')
            ->assertOk()
            ->json('data.*.title');

        $this->assertContains($mine->subject_line, $titles);
    }

    #[Test]
    public function the_preview_says_enough_to_know_it_is_the_right_one(): void
    {
        $subject = Subject::forUsername('Quiet Marlin', 42);

        $case = $this->case([
            'subject_line' => 'Harassment in talk pages',
            'summary' => 'They followed me across four wikis.',
            'wiki' => 'oasis.example',
        ]);
        $case->subjects()->attach($subject->id, ['role' => 'reported']);

        $preview = $this->actingAs($this->staff())
            ->getJson("/api/portal/search/preview/case/{$case->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('Harassment in talk pages', $preview['title']);
        $this->assertSame('They followed me across four wikis.', $preview['excerpt']);
        $this->assertSame(['Quiet Marlin'], array_column($preview['accounts'], 'username'));
        $this->assertContains('oasis.example', array_column($preview['facts'], 'value'));
    }

    #[Test]
    public function a_preview_of_something_that_is_gone_is_a_404(): void
    {
        $this->actingAs($this->staff())
            ->getJson('/api/portal/search/preview/case/9999')
            ->assertNotFound();
    }

    #[Test]
    public function searching_is_staff_only(): void
    {
        $outsider = User::create(['mw_central_id' => 9, 'username' => 'Outsider', 'flags' => []]);

        $this->actingAs($outsider)->getJson('/api/portal/search?q=anything')->assertForbidden();
        $this->getJson('/api/portal/search?q=anything')->assertForbidden();
    }
}
