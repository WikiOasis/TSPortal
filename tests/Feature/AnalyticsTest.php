<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CaseComment;
use App\Models\CheckUserCheck;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\User;
use App\Services\Safety\Analytics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::create([
            'username' => 'Reviewer',
            'mw_central_id' => 900,
            'flags' => [User::FLAG_TS],
            'active' => true,
        ]);
    }

    private function case(array $attributes = []): SafetyCase
    {
        static $n = 0;
        $n++;

        $timestamps = array_intersect_key($attributes, array_flip(['created_at', 'updated_at']));
        $attributes = array_diff_key($attributes, $timestamps);

        $case = SafetyCase::create($attributes + [
            'reference' => 'TS-2026-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'type' => SafetyCase::TYPE_REPORT,
            'subject_line' => 'A report',
            'wiki' => 'oasiswiki',
        ]);

        if ($timestamps !== []) {
            $case->forceFill($timestamps + ['updated_at' => $timestamps['created_at'] ?? null])->save();
        }

        return $case->refresh();
    }

    private function comment(SafetyCase $case, string $visibility, string $at): void
    {
        CaseComment::create([
            'case_id' => $case->id,
            'author_type' => CaseComment::AUTHOR_STAFF,
            'visibility' => $visibility,
            'body' => 'Something was said.',
        ])->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    private function window(): array
    {
        return [
            CarbonImmutable::parse('2026-06-01')->startOfDay(),
            CarbonImmutable::parse('2026-06-30')->endOfDay(),
        ];
    }

    #[Test]
    public function the_trend_keeps_the_days_on_which_nothing_happened(): void
    {
        $this->case(['created_at' => '2026-06-01 09:00:00']);
        $this->case(['created_at' => '2026-06-05 09:00:00']);

        [$from, $to] = $this->window();
        $overview = app(Analytics::class)->overview($from, $to);

        $this->assertCount(30, $overview['intake']['over_time']);
        $this->assertSame(['bucket' => '2026-06-01', 'total' => 1], $overview['intake']['over_time'][0]);
        $this->assertSame(['bucket' => '2026-06-02', 'total' => 0], $overview['intake']['over_time'][1]);
    }

    #[Test]
    public function the_headline_carries_the_window_before_it(): void
    {
        $this->case(['created_at' => '2026-06-10 09:00:00']);
        $this->case(['created_at' => '2026-06-11 09:00:00']);
        $this->case(['created_at' => '2026-05-15 09:00:00']);

        [$from, $to] = $this->window();
        $overview = app(Analytics::class)->overview($from, $to);

        $this->assertSame(2, $overview['headline']['received']['value']);
        $this->assertSame(1, $overview['headline']['received']['previous']);
    }

    #[Test]
    public function time_to_close_is_a_median_and_not_a_mean(): void
    {
        $this->case(['created_at' => '2026-06-01 00:00:00', 'closed_at' => '2026-06-02 00:00:00']);
        $this->case(['created_at' => '2026-06-01 00:00:00', 'closed_at' => '2026-06-02 00:00:00']);
        $this->case(['created_at' => '2025-06-10 00:00:00', 'closed_at' => '2026-06-10 00:00:00']);

        [$from, $to] = $this->window();
        $overview = app(Analytics::class)->overview($from, $to);

        $this->assertSame(24.0, $overview['handling']['time_to_close']['median_hours']);
        $this->assertGreaterThan(1000, $overview['handling']['time_to_close']['p90_hours']);
    }

    #[Test]
    public function nothing_closed_reads_as_nothing_rather_than_as_instantly(): void
    {
        $this->case(['created_at' => '2026-06-01 00:00:00']);

        [$from, $to] = $this->window();
        $overview = app(Analytics::class)->overview($from, $to);

        $this->assertNull($overview['handling']['time_to_close']['median_hours']);
    }

    #[Test]
    public function a_first_reply_is_a_public_staff_reply_and_not_an_internal_note(): void
    {
        $case = $this->case(['created_at' => '2026-06-01 00:00:00']);

        $this->comment($case, CaseComment::VISIBILITY_INTERNAL, '2026-06-01 01:00:00');

        [$from, $to] = $this->window();
        $overview = app(Analytics::class)->overview($from, $to);

        $this->assertNull($overview['handling']['first_response']['median_hours']);
        $this->assertSame(1, $overview['handling']['first_response']['unanswered']);

        $this->comment($case, CaseComment::VISIBILITY_PUBLIC, '2026-06-01 04:00:00');

        $overview = app(Analytics::class)->overview($from, $to);

        $this->assertSame(4.0, $overview['handling']['first_response']['median_hours']);
        $this->assertSame(0, $overview['handling']['first_response']['unanswered']);
    }

    #[Test]
    public function the_uncategorised_are_counted_rather_than_omitted(): void
    {
        $categorised = $this->case(['created_at' => '2026-06-01 09:00:00', 'category' => 'harassment']);
        $categorised->categories()->create([
            'category' => 'harassment', 'label' => 'Harassment', 'is_primary' => true,
        ]);

        $this->case(['created_at' => '2026-06-02 09:00:00']);

        [$from, $to] = $this->window();
        $overview = app(Analytics::class)->overview($from, $to);

        $this->assertSame(2, $overview['intake']['by_category']['cases']);
        $this->assertSame(1, $overview['intake']['by_category']['uncategorised']);
        $this->assertSame('harassment', $overview['intake']['by_category']['rows'][0]['key']);
    }

    #[Test]
    public function a_case_that_is_two_things_is_counted_under_both(): void
    {
        $case = $this->case(['created_at' => '2026-06-01 09:00:00', 'category' => 'harassment']);
        $case->categories()->createMany([
            ['category' => 'harassment', 'label' => 'Harassment', 'is_primary' => true],
            ['category' => 'doxxing', 'label' => 'Doxxing'],
        ]);

        [$from, $to] = $this->window();
        $rows = app(Analytics::class)->overview($from, $to)['intake']['by_category']['rows'];

        $this->assertCount(2, $rows);
        $this->assertSame(2, array_sum(array_column($rows, 'total')));
    }

    #[Test]
    public function files_that_ended_in_the_window_are_counted_by_outcome(): void
    {
        $concluded = Investigation::create([
            'reference' => 'TS-2026-9001',
            'title' => 'A file',
            'status' => Investigation::STATUS_CONCLUDED,
            'outcome' => Investigation::OUTCOME_SUSPENDED,
            'opened_at' => '2026-05-01 09:00:00',
        ]);
        $concluded->forceFill(['closed_at' => '2026-06-15 09:00:00'])->save();

        $closed = Investigation::create([
            'reference' => 'TS-2026-9002',
            'title' => 'Another file',
            'status' => Investigation::STATUS_CLOSED,
            'opened_at' => '2026-05-01 09:00:00',
        ]);
        $closed->forceFill(['closed_at' => '2026-06-20 09:00:00'])->save();

        Investigation::create([
            'reference' => 'TS-2026-9003',
            'title' => 'Open file',
            'status' => Investigation::STATUS_OPEN,
            'opened_at' => '2026-06-01 09:00:00',
        ]);

        [$from, $to] = $this->window();
        $rows = app(Analytics::class)->overview($from, $to)['outcomes']['investigations_by_outcome'];

        $this->assertEqualsCanonicalizing(
            [
                ['key' => 'suspended', 'total' => 1],
                ['key' => null, 'total' => 1],
            ],
            $rows,
        );
    }

    #[Test]
    public function the_bucket_follows_the_length_of_the_window(): void
    {
        $analytics = app(Analytics::class);
        $day = CarbonImmutable::parse('2026-01-01');

        $this->assertSame('day', $analytics->bucketFor($day, $day->addDays(20)));
        $this->assertSame('week', $analytics->bucketFor($day, $day->addDays(90)));
        $this->assertSame('month', $analytics->bucketFor($day, $day->addDays(700)));
    }

    #[Test]
    public function checkuser_leads_on_the_share_run_without_a_reason(): void
    {
        foreach ([[1, true], [2, true], [3, false], [4, false]] as [$id, $explained]) {
            CheckUserCheck::create([
                'wiki' => 'oasiswiki',
                'log_id' => $id,
                'checked_at' => '2026-06-05 12:00:00',
                'checker_username' => $id > 2 ? 'Second Checker' : 'Marisol Kane',
                'type' => 'userips',
                'target_kind' => 'account',
                'target_fingerprint' => 'aaaa1111bbbb2222',
                'reason' => $explained ? 'Sockpuppetry' : null,
                'reason_given' => $explained,
            ]);
        }

        [$from, $to] = $this->window();
        $checkuser = app(Analytics::class)->overview($from, $to)['checkuser'];

        $this->assertSame(4, $checkuser['total']);
        $this->assertSame(2, $checkuser['unexplained']);
        $this->assertSame(0.5, $checkuser['unexplained_share']);
        $this->assertSame(2, $checkuser['checkers']);

        $this->assertEqualsCanonicalizing(
            [
                ['checker' => 'Marisol Kane', 'total' => 2, 'unexplained' => 0],
                ['checker' => 'Second Checker', 'total' => 2, 'unexplained' => 2],
            ],
            $checkuser['by_checker'],
        );

        $this->assertSame(4, $checkuser['repeat_targets'][0]['total']);
        $this->assertSame(2, $checkuser['repeat_targets'][0]['checkers']);
    }

    #[Test]
    public function the_endpoint_answers_and_says_what_the_filters_can_offer(): void
    {
        $this->case(['created_at' => '2026-06-01 09:00:00', 'wiki' => 'oasiswiki']);
        $this->case(['created_at' => '2026-06-01 09:00:00', 'wiki' => 'oasiswiki-fr']);

        $this->actingAs($this->staff())
            ->getJson('/api/portal/analytics?from=2026-06-01&to=2026-06-30')
            ->assertOk()
            ->assertJsonPath('period.from', '2026-06-01')
            ->assertJsonPath('headline.received.value', 2)
            ->assertJsonPath('options.wikis', ['oasiswiki', 'oasiswiki-fr'])
            ->assertJsonPath('options.checkuser_enabled', false);
    }

    #[Test]
    public function the_day_count_is_a_whole_number(): void
    {
        $this->actingAs($this->staff())
            ->getJson('/api/portal/analytics?from=2026-06-01&to=2026-06-30')
            ->assertOk()
            ->assertJsonPath('period.days', 30);
    }

    #[Test]
    public function one_wiki_can_be_looked_at_on_its_own(): void
    {
        $this->case(['created_at' => '2026-06-01 09:00:00', 'wiki' => 'oasiswiki']);
        $this->case(['created_at' => '2026-06-01 09:00:00', 'wiki' => 'oasiswiki-fr']);

        $this->actingAs($this->staff())
            ->getJson('/api/portal/analytics?from=2026-06-01&to=2026-06-30&wiki=oasiswiki-fr')
            ->assertOk()
            ->assertJsonPath('headline.received.value', 1);
    }

    #[Test]
    public function dates_the_wrong_way_round_are_read_as_the_period_between_them(): void
    {
        $this->case(['created_at' => '2026-06-10 09:00:00']);

        $this->actingAs($this->staff())
            ->getJson('/api/portal/analytics?from=2026-06-30&to=2026-06-01')
            ->assertOk()
            ->assertJsonPath('period.from', '2026-06-01')
            ->assertJsonPath('headline.received.value', 1);
    }

    #[Test]
    public function somebody_without_the_flag_cannot_read_it(): void
    {
        $outsider = User::create([
            'username' => 'Passer By',
            'mw_central_id' => 901,
            'flags' => [],
            'active' => true,
        ]);

        $this->actingAs($outsider)->getJson('/api/portal/analytics')->assertForbidden();
    }

    #[Test]
    public function intake_is_split_between_people_and_automated_scanning(): void
    {
        $this->case(['created_at' => '2026-06-02 09:00:00']);
        $this->case(['created_at' => '2026-06-03 09:00:00', 'automated' => true]);
        $this->case([
            'created_at' => '2026-06-04 09:00:00',
            'automated' => true,
            'status' => SafetyCase::STATUS_ACTION_TAKEN,
            'closed_at' => '2026-06-05 09:00:00',
        ]);

        [$from, $to] = $this->window();
        $bySource = collect(app(Analytics::class)->overview($from, $to)['intake']['by_source'])->keyBy('key');

        $this->assertSame(1, $bySource['people']['total']);
        $this->assertSame(2, $bySource['automated']['total']);
        $this->assertSame(1, $bySource['automated']['closed']);
        $this->assertSame(1, $bySource['automated']['action_taken']);
    }

    #[Test]
    public function the_source_filter_narrows_every_case_figure_but_not_the_split(): void
    {
        $this->case(['created_at' => '2026-06-02 09:00:00']);
        $this->case(['created_at' => '2026-06-02 10:00:00']);
        $this->case(['created_at' => '2026-06-03 09:00:00', 'automated' => true]);

        [$from, $to] = $this->window();
        $people = app(Analytics::class)->overview($from, $to, null, Analytics::SOURCE_PEOPLE);
        $automated = app(Analytics::class)->overview($from, $to, null, Analytics::SOURCE_AUTOMATED);

        $this->assertSame(2, $people['headline']['received']['value']);
        $this->assertSame(1, $automated['headline']['received']['value']);
        $this->assertSame(1, $automated['handling']['backlog']['total']);
        $this->assertSame('automated', $automated['period']['source']);

        $split = collect($people['intake']['by_source'])->pluck('total', 'key')->all();
        $this->assertSame(['people' => 2, 'automated' => 1], $split);
    }

    #[Test]
    public function an_unknown_source_means_every_source(): void
    {
        $this->case(['created_at' => '2026-06-02 09:00:00']);
        $this->case(['created_at' => '2026-06-03 09:00:00', 'automated' => true]);

        [$from, $to] = $this->window();
        $overview = app(Analytics::class)->overview($from, $to, null, 'robots');

        $this->assertSame(2, $overview['headline']['received']['value']);
        $this->assertNull($overview['period']['source']);
    }
}
