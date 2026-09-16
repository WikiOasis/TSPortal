<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CaseComment;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\TransparencyReport;
use App\Models\User;
use App\Services\Search\PortalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchIndexTest extends TestCase
{
    use RefreshDatabase;

    private function useOpenSearch(): void
    {
        config([
            'opensearch.enabled' => true,
            'opensearch.url' => 'http://opensearch.test:9200',
            'opensearch.prefix' => 'tsportal',
        ]);
    }

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

    /** @return list<array<string, mixed>> */
    private function bulkDocuments(): array
    {
        $documents = [];

        foreach (Http::recorded() as [$request]) {
            if (! str_contains($request->url(), '/_bulk')) {
                continue;
            }

            foreach (explode("\n", $request->body()) as $line) {
                $decoded = json_decode($line, true);

                if (is_array($decoded) && isset($decoded['kind'])) {
                    $documents[] = $decoded;
                }
            }
        }

        return $documents;
    }

    #[Test]
    public function it_says_so_and_stops_when_opensearch_is_switched_off(): void
    {
        config(['opensearch.enabled' => false]);

        $this->artisan('tsportal:search-index')
            ->expectsOutputToContain('OpenSearch is switched off.')
            ->assertFailed();
    }

    #[Test]
    public function every_document_it_writes_is_built_from_the_database(): void
    {
        $this->useOpenSearch();
        Http::fake(['*' => Http::response(['errors' => false, 'items' => []])]);

        $case = $this->case([
            'subject_line' => 'Harassment in talk pages',
            'summary' => 'They followed me across four wikis.',
        ]);

        CaseComment::create([
            'case_id' => $case->id,
            'author_type' => CaseComment::AUTHOR_STAFF,
            'author_label' => 'Admin',
            'body' => 'They turned up at my workplace on Tuesday.',
            'visibility' => CaseComment::VISIBILITY_INTERNAL,
        ]);

        $this->artisan('tsportal:search-index', ['--only' => ['case']])->assertSuccessful();

        $documents = $this->bulkDocuments();
        $this->assertCount(1, $documents);

        $document = $documents[0];

        $this->assertSame('case', $document['kind']);
        $this->assertSame($case->id, $document['id']);
        $this->assertSame($case->reference, $document['reference']);
        $this->assertSame('Harassment in talk pages', $document['title']);

        $this->assertStringContainsString('They followed me across four wikis.', $document['body']);
        $this->assertStringContainsString('They turned up at my workplace on Tuesday.', $document['body']);
    }

    #[Test]
    public function fresh_throws_the_index_away_and_builds_it_again(): void
    {
        $this->useOpenSearch();
        Http::fake(['*' => Http::response(['errors' => false, 'items' => []])]);

        $this->artisan('tsportal:search-index', ['--only' => ['case'], '--fresh' => true])
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === 'http://opensearch.test:9200/tsportal-case');

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === 'http://opensearch.test:9200/tsportal-case');
    }

    #[Test]
    public function a_full_run_drops_whatever_it_did_not_write(): void
    {
        $this->useOpenSearch();
        Http::fake(['*' => Http::response(['errors' => false, 'items' => [], 'deleted' => 2])]);

        $this->case();

        $this->artisan('tsportal:search-index', ['--only' => ['case']])->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/_delete_by_query')
            && str_contains($request->body(), 'must_not'));
    }

    #[Test]
    public function a_run_with_since_leaves_the_rest_of_the_index_alone(): void
    {
        $this->useOpenSearch();
        Http::fake(['*' => Http::response(['errors' => false, 'items' => []])]);

        $this->case();

        $this->artisan('tsportal:search-index', ['--only' => ['case'], '--since' => '15m'])
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/_delete_by_query'));
    }

    #[Test]
    public function what_a_search_hands_back_is_read_out_of_the_database(): void
    {
        $this->useOpenSearch();

        $case = $this->case(['subject_line' => 'What the database says']);

        Http::fake([
            '*/_search*' => Http::response([
                'hits' => [
                    'total' => ['value' => 1],
                    'hits' => [[
                        '_id' => (string) $case->id,
                        '_source' => ['kind' => 'case', 'id' => $case->id],
                        'highlight' => ['body' => ["a phrase \u{2062}[workplace]\u{2063} in context"]],
                    ]],
                ],
            ]),
        ]);

        $found = app(PortalSearch::class)->find('workplace');

        $this->assertSame('opensearch', $found['engine']);
        $this->assertCount(1, $found['rows']);

        $row = $found['rows'][0];

        $this->assertSame('What the database says', $row['title']);
        $this->assertSame($case->reference, $row['reference']);

        $this->assertSame(
            [['text' => 'a phrase ', 'match' => false], ['text' => 'workplace', 'match' => true], ['text' => ' in context', 'match' => false]],
            $row['snippet'],
        );
    }

    #[Test]
    public function a_cluster_that_does_not_answer_falls_back_to_the_database(): void
    {
        $this->useOpenSearch();

        $case = $this->case(['subject_line' => 'Still findable without a cluster']);

        Http::fake(['*' => Http::response(['error' => ['reason' => 'no such index']], 500)]);

        $found = app(PortalSearch::class)->find('findable');

        $this->assertSame('database', $found['engine']);
        $this->assertTrue($found['degraded']);
        $this->assertSame([$case->subject_line], array_column($found['rows'], 'title'));
    }

    #[Test]
    public function the_check_says_where_the_index_and_the_database_disagree(): void
    {
        $this->useOpenSearch();

        $this->case();

        Http::fake(['*' => Http::response(['count' => 0])]);

        $this->artisan('tsportal:search-index', ['--only' => ['case'], '--check' => true])
            ->expectsOutputToContain('The index and the database disagree.')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/_bulk'));
    }

    #[Test]
    public function what_looks_alike_is_asked_of_the_cluster_and_read_out_of_the_database(): void
    {
        $this->useOpenSearch();

        $subject = Subject::forUsername('Quiet Marlin', 42);

        $case = $this->case(['subject_line' => 'Harassment in talk pages']);
        $case->subjects()->attach($subject->id, ['role' => 'reported']);

        $investigation = Investigation::create([
            'reference' => 'TS-2026-0900',
            'title' => 'A pattern of following people about',
            'opened_at' => now(),
        ]);
        $investigation->subjects()->attach($subject->id, ['role' => 'suspect']);

        Http::fake([
            '*/_search*' => Http::response([
                'hits' => [
                    'hits' => [[
                        '_source' => ['kind' => 'investigation', 'id' => $investigation->id],
                    ]],
                ],
            ]),
        ]);

        $found = $this->actingAs($this->staff())
            ->getJson("/api/portal/search/related/case/{$case->id}")
            ->assertOk()
            ->json();

        $this->assertSame('opensearch', $found['meta']['engine']);
        $this->assertSame($case->reference, $found['meta']['seed']['reference']);

        $this->assertSame([$investigation->title], array_column($found['data'], 'title'));
        $this->assertSame('Also about Quiet Marlin', $found['data'][0]['related_by']);

        Http::assertSent(function (Request $request) use ($case) {
            $body = json_decode($request->body(), true);
            $should = $body['query']['bool']['should'] ?? [];

            return str_contains($request->url(), '/_search')
                && $body['size'] === 5
                && $should[0]['more_like_this']['like'] === [
                    "Harassment in talk pages\nQuiet Marlin",
                ]
                && $should[1]['terms']['accounts.raw'] === ['Quiet Marlin']
                && $should[2]['match_phrase']['body']['query'] === $case->reference
                && $body['query']['bool']['must_not'] === [['bool' => ['filter' => [
                    ['term' => ['kind' => 'case']],
                    ['term' => ['id' => $case->id]],
                ]]]];
        });
    }

    #[Test]
    public function a_transparency_report_is_compared_by_its_words_and_its_period(): void
    {
        $this->useOpenSearch();

        $report = TransparencyReport::create([
            'reference' => 'TS-2026-0500',
            'title' => 'Transparency report, first half of 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
        ]);

        Http::fake(['*/_search*' => Http::response(['hits' => ['hits' => []]])]);

        $this->actingAs($this->staff())
            ->getJson("/api/portal/search/related/transparency/{$report->id}")
            ->assertOk()
            ->assertJsonPath('meta.seed.kind_label', 'Transparency report');

        Http::assertSent(function (Request $request) {
            $should = json_decode($request->body(), true)['query']['bool']['should'] ?? [];

            return str_contains($request->url(), '/_search')
                && ($should[2]['range']['created_at'] ?? null) === [
                    'gte' => '2026-01-01',
                    'lte' => '2026-06-30',
                    'boost' => 2,
                ];
        });
    }

    #[Test]
    public function without_a_cluster_nothing_looks_alike(): void
    {
        config(['opensearch.enabled' => false]);

        $case = $this->case();

        $found = $this->actingAs($this->staff())
            ->getJson("/api/portal/search/related/case/{$case->id}")
            ->assertOk()
            ->json();

        $this->assertSame([], $found['data']);
        $this->assertSame('database', $found['meta']['engine']);
        $this->assertFalse($found['meta']['full_text']);
    }

    #[Test]
    public function a_cluster_that_does_not_answer_leaves_the_related_list_empty(): void
    {
        $this->useOpenSearch();

        $case = $this->case();

        Http::fake(['*' => Http::response(['error' => ['reason' => 'no such index']], 500)]);

        $this->actingAs($this->staff())
            ->getJson("/api/portal/search/related/case/{$case->id}")
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.degraded', true);
    }

    #[Test]
    public function there_is_nothing_like_something_that_is_gone(): void
    {
        $this->useOpenSearch();

        $this->actingAs($this->staff())
            ->getJson('/api/portal/search/related/case/9999')
            ->assertNotFound();

        $this->actingAs($this->staff())
            ->getJson('/api/portal/search/related/dashboard/1')
            ->assertNotFound();
    }

    #[Test]
    public function the_portal_knows_whether_full_text_search_is_there(): void
    {
        $staff = User::create(['mw_central_id' => 1, 'username' => 'Admin', 'flags' => ['ts', 'admin']]);

        config(['opensearch.enabled' => false]);

        $this->actingAs($staff)
            ->getJson('/api/portal/search/recents')
            ->assertOk()
            ->assertJsonPath('meta.full_text', false);
    }
}
