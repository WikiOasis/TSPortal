<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubjectPageTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::create(['mw_central_id' => 1, 'username' => 'Admin', 'flags' => ['ts', 'admin']]);
    }

    #[Test]
    public function it_shows_what_was_filed_by_and_about_an_account(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $other = Subject::forUsername('Quiet Marlin', 43);

        $filed = SafetyCase::create([
            'reference' => 'TS-2026-0001', 'type' => 'report',
            'subject_line' => 'Something they reported', 'reporter_subject_id' => $subject->id,
        ]);
        $filed->subjects()->attach($other->id, ['role' => 'reported']);

        $about = SafetyCase::create([
            'reference' => 'TS-2026-0002', 'type' => 'report',
            'subject_line' => 'Something reported about them',
        ]);
        $about->subjects()->attach($subject->id, ['role' => 'reported']);

        $response = $this->actingAs($this->staff())
            ->getJson("/api/portal/subjects/{$subject->id}")
            ->assertOk();

        $this->assertSame(['TS-2026-0001'], array_column($response->json('cases_filed'), 'reference'));
        $this->assertSame(['TS-2026-0002'], array_column($response->json('cases_about'), 'reference'));

        $this->assertSame('reported', $response->json('cases_about.0.role'));
    }

    #[Test]
    public function a_name_that_matches_no_account_is_flagged_rather_than_hidden(): void
    {
        $subject = Subject::forUsername('198.51.100.7');

        $this->actingAs($this->staff())
            ->getJson("/api/portal/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('unresolved', true);
    }

    #[Test]
    public function the_internal_reason_is_on_the_staff_page_and_nowhere_else(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);

        Sanction::create([
            'reference' => 'AC-2026-0001',
            'subject_id' => $subject->id,
            'type' => Sanction::TYPE_WARNING,
            'label' => 'Formal warning',
            'reason' => 'Edit summaries directed at another contributor.',
            'internal_reason' => 'Second time this year; next one is a block.',
            'issued_at' => now(),
            'active' => true,
        ]);
        $subject->refreshStanding();

        $this->actingAs($this->staff())
            ->getJson("/api/portal/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('sanctions.0.internal_reason', 'Second time this year; next one is a block.');
    }

    #[Test]
    public function names_are_matched_however_they_were_typed(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);

        $this->assertTrue($subject->is(Subject::forUsername('halcyon_reed')));
        $this->assertTrue($subject->is(Subject::forUsername('Halcyon  Reed')));
        $this->assertSame(1, Subject::query()->count());
    }

    #[Test]
    public function a_rename_keeps_the_same_record(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $renamed = Subject::forUsername('Halcyon', 42);

        $this->assertTrue($subject->is($renamed));
        $this->assertSame('Halcyon', $renamed->username);
        $this->assertSame(1, Subject::query()->count());
    }
}
