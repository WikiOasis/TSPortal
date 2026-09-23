<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SafetyCase;
use App\Models\TransparencyReport;
use App\Models\User;
use App\Services\Safety\Transparency;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransparencyTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $flags = [User::FLAG_TS]): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'username' => 'Reviewer '.$n,
            'mw_central_id' => 900 + $n,
            'flags' => $flags,
            'active' => true,
        ]);
    }

    private function case(array $attributes = [], array $categories = []): SafetyCase
    {
        static $n = 0;
        $n++;

        $created = $attributes['created_at'] ?? '2026-06-05 09:00:00';
        unset($attributes['created_at']);

        $case = SafetyCase::create($attributes + [
            'reference' => 'TS-2026-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'A report',
        ]);

        $case->forceFill(['created_at' => $created, 'updated_at' => $created])->save();

        foreach ($categories as $index => [$id, $label]) {
            $case->categories()->create([
                'category' => $id,
                'label' => $label,
                'is_primary' => $index === 0,
            ]);
        }

        return $case;
    }

    private function report(int $threshold = 5): TransparencyReport
    {
        config()->set('categories.suppression_threshold', $threshold);

        return app(Transparency::class)->open(
            'Trust & Safety, Q2 2026',
            CarbonImmutable::parse('2026-04-01'),
            CarbonImmutable::parse('2026-06-30')->endOfDay(),
            $this->staff(),
        );
    }

    #[Test]
    public function a_report_is_opened_generated_and_numbered_in_one_step(): void
    {
        $this->case();

        $report = $this->report();

        $this->assertNotNull($report->figures);
        $this->assertNotNull($report->generated_at);
        $this->assertSame(TransparencyReport::STATUS_DRAFT, $report->status);
        $this->assertMatchesRegularExpression('/^TS-\d{4}-\d+$/', $report->reference);
    }

    #[Test]
    public function counts_at_or_below_the_threshold_are_banded_and_not_stored(): void
    {
        $this->case();
        $this->case();

        $figures = $this->report(5)->figures;

        $this->assertTrue($figures['reports']['total']['suppressed']);
        $this->assertSame('fewer than 6', $figures['reports']['total']['display']);
        $this->assertNull($figures['reports']['total']['value']);
    }

    #[Test]
    public function a_count_above_the_threshold_is_printed_exactly(): void
    {
        foreach (range(1, 6) as $i) {
            $this->case();
        }

        $figures = $this->report(5)->figures;

        $this->assertFalse($figures['reports']['total']['suppressed']);
        $this->assertSame(6, $figures['reports']['total']['value']);
    }

    #[Test]
    public function zero_is_always_printed_exactly(): void
    {
        $figures = $this->report(5)->figures;

        $this->assertFalse($figures['reports']['total']['suppressed']);
        $this->assertSame(0, $figures['reports']['total']['value']);
    }

    #[Test]
    public function suppressed_rows_are_summarised_rather_than_silently_dropped(): void
    {
        foreach (range(1, 6) as $i) {
            $this->case([], [['harassment', 'Harassment']]);
        }
        $this->case([], [['spam', 'Spam']]);
        $this->case([], [['doxxing', 'Doxxing']]);

        $section = $this->report(5)->figures['reports']['by_category'];

        $this->assertSame(['harassment'], array_column($section['rows'], 'key'));
        $this->assertSame(2, $section['withheld']['rows']);
        $this->assertSame(0, $section['withheld']['total']);
    }

    #[Test]
    public function a_report_says_how_many_arrived_with_no_category(): void
    {
        foreach (range(1, 6) as $i) {
            $this->case();
        }

        $figures = $this->report(5)->figures;

        $this->assertSame(6, $figures['reports']['uncategorised']['value']);
        $this->assertSame(5, $figures['method']['threshold']);
        $this->assertStringContainsString('fewer than 6', $figures['method']['suppression']);
    }

    #[Test]
    public function a_threshold_of_zero_prints_everything_and_says_so(): void
    {
        $this->case();

        $figures = $this->report(0)->figures;

        $this->assertSame(1, $figures['reports']['total']['value']);
        $this->assertStringContainsString('no suppression', $figures['method']['suppression']);
    }

    #[Test]
    public function a_published_report_cannot_be_recomputed(): void
    {
        $report = $this->report();
        $admin = $this->staff([User::FLAG_TS, User::FLAG_ADMIN]);

        $this->actingAs($admin)
            ->postJson("/api/portal/transparency/{$report->id}/publish")
            ->assertOk()
            ->assertJsonPath('status', 'published');

        $this->actingAs($admin)
            ->postJson("/api/portal/transparency/{$report->id}/regenerate")
            ->assertStatus(409)
            ->assertJsonPath('error', 'conflict');
    }

    #[Test]
    public function a_published_report_keeps_its_figures_when_the_data_moves(): void
    {
        foreach (range(1, 6) as $i) {
            $this->case();
        }

        $report = $this->report(0);
        $admin = $this->staff([User::FLAG_TS, User::FLAG_ADMIN]);
        app(Transparency::class)->publish($report, $admin);

        $this->case(['created_at' => '2026-06-20 09:00:00']);

        $this->actingAs($admin)
            ->getJson("/api/portal/transparency/{$report->id}")
            ->assertOk()
            ->assertJsonPath('figures.reports.total.value', 6);
    }

    #[Test]
    public function publishing_needs_the_admin_flag(): void
    {
        $report = $this->report();

        $this->actingAs($this->staff())
            ->postJson("/api/portal/transparency/{$report->id}/publish")
            ->assertForbidden();
    }

    #[Test]
    public function a_report_with_no_figures_cannot_be_published(): void
    {
        $report = TransparencyReport::create([
            'reference' => 'TS-2026-9999',
            'title' => 'Empty',
            'period_start' => '2026-04-01',
            'period_end' => '2026-06-30',
        ]);

        $this->actingAs($this->staff([User::FLAG_TS, User::FLAG_ADMIN]))
            ->postJson("/api/portal/transparency/{$report->id}/publish")
            ->assertStatus(409);
    }

    #[Test]
    public function notes_can_still_be_written_after_publication(): void
    {
        $report = $this->report();
        $admin = $this->staff([User::FLAG_TS, User::FLAG_ADMIN]);
        app(Transparency::class)->publish($report, $admin);

        $this->actingAs($admin)
            ->patchJson("/api/portal/transparency/{$report->id}", ['notes' => 'The March spike was one campaign.'])
            ->assertOk()
            ->assertJsonPath('notes', 'The March spike was one campaign.');
    }

    #[Test]
    public function the_csv_leaves_a_suppressed_value_empty(): void
    {
        $this->case();

        $report = $this->report(5);

        $response = $this->actingAs($this->staff())
            ->get("/api/portal/transparency/{$report->id}/export")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->getContent();

        $this->assertStringContainsString('Section,Item,Value,Note', $csv);
        $response->assertHeader(
            'Content-Disposition',
            sprintf('attachment; filename="%s.csv"', $report->reference)
        );
        $this->assertStringContainsString('"fewer than 6"', $csv);
    }

    #[Test]
    public function a_period_the_wrong_way_round_is_refused(): void
    {
        $this->actingAs($this->staff())
            ->postJson('/api/portal/transparency', [
                'title' => 'Backwards',
                'period_start' => '2026-06-30',
                'period_end' => '2026-04-01',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function a_report_separates_reports_filed_by_people_from_automated_ones(): void
    {
        foreach (range(1, 6) as $i) {
            $this->case();
        }
        foreach (range(1, 7) as $i) {
            $this->case([
                'automated' => true,
                'status' => SafetyCase::STATUS_REJECTED,
                'closed_at' => '2026-06-10 09:00:00',
            ]);
        }

        $figures = $this->report(5)->figures;

        $sources = collect($figures['reports']['by_source']['rows'])->keyBy('key');
        $this->assertSame(6, $sources['people']['total']);
        $this->assertSame(7, $sources['automated']['total']);
        $this->assertSame('Raised by automated scanning', $sources['automated']['label']);

        $this->assertSame(13, $figures['reports']['total']['value']);
        $this->assertSame(7, $figures['reports']['automated']['total']['value']);
        $this->assertSame(
            ['Looked into, no action taken'],
            array_column($figures['reports']['automated']['how_they_ended']['rows'], 'label')
        );
        $this->assertStringContainsString('automated scanning', $figures['method']['automated']);
    }

    #[Test]
    public function a_handful_of_automated_reports_is_banded_like_any_other_count(): void
    {
        foreach (range(1, 6) as $i) {
            $this->case();
        }
        $this->case(['automated' => true]);

        $figures = $this->report(5)->figures;

        $this->assertSame(['people'], array_column($figures['reports']['by_source']['rows'], 'key'));
        $this->assertSame(1, $figures['reports']['by_source']['withheld']['rows']);
        $this->assertTrue($figures['reports']['automated']['total']['suppressed']);
    }
}
