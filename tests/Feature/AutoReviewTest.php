<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AutomatedReview;
use App\Models\SafetyCase;
use App\Models\User;
use App\Services\AutoReview\AutoReview;
use App\Services\AutoReview\ClassificationFailed;
use App\Services\AutoReview\Classifier;
use App\Services\AutoReview\LineDiff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\SignsWikiRequests;
use Tests\TestCase;

class AutoReviewTest extends TestCase
{
    use RefreshDatabase;
    use SignsWikiRequests;

    private string $verdict = 'unlikely';

    private int $modelCalls = 0;

    private bool $wikiDown = false;

    private ?string $modelAnswer = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('autoreview.enabled', true);
        config()->set('autoreview.openrouter.key', 'test-key');
        config()->set('autoreview.openrouter.url', 'https://openrouter.test/api/v1');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'openrouter.test')) {
                $this->modelCalls++;

                if ($this->modelAnswer !== null) {
                    return Http::response(['choices' => [['message' => ['content' => $this->modelAnswer]]]]);
                }

                return Http::response([
                    'model' => 'z-ai/glm-5.3-flash',
                    'choices' => [['message' => ['content' => "```json\n".json_encode([
                        'bucket' => $this->verdict,
                        'confidence' => 0.9,
                        'page_summary' => 'A fan wiki page about a fictional war.',
                        'change_summary' => 'Adds a paragraph of battle lore.',
                        'reason' => 'In-universe fiction that tripped a violence keyword.',
                        'signals' => ['fiction', 'On Topic'],
                        'suggested_action' => 'close-no-action',
                    ])."\n```"]]],
                    'usage' => ['total_tokens' => 900, 'cost' => 0.0004],
                ]);
            }

            if ($this->wikiDown) {
                return Http::response('down', 503);
            }

            if (str_contains($request->url(), 'oasiswiki.wikioasis.org/w/api.php')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                if (($query['revids'] ?? '') === '99') {
                    return Http::response(['query' => ['pages' => [['revisions' => [
                        ['slots' => ['main' => ['content' => "Intro\nThe war began.\nOutro"]]]],
                    ]]]]);
                }

                return Http::response(['query' => [
                    'pages' => [[
                        'pageid' => 5,
                        'title' => 'Great War',
                        'lastrevid' => 101,
                        'revisions' => [[
                            'revid' => 100,
                            'parentid' => 99,
                            'user' => 'Scribe',
                            'timestamp' => '2026-09-20T10:00:00Z',
                            'comment' => 'lore',
                            'size' => 60,
                            'tags' => ['mw-reverted'],
                            'slots' => ['main' => ['content' => "Intro\nThe war began and thousands died.\nOutro"]],
                        ]],
                    ]],
                    'users' => [['name' => 'Scribe', 'editcount' => 412, 'registration' => '2024-01-01T00:00:00Z', 'groups' => ['*', 'user', 'sysop']]],
                ]]);
            }

            return Http::response([], 404);
        });
    }

    private function staff(): User
    {
        return User::firstOrCreate(['username' => 'Reviewer'], [
            'mw_central_id' => 900,
            'flags' => [User::FLAG_TS],
            'active' => true,
        ]);
    }

    private function automated(int $revision = 100, string $page = 'Great War', string $author = 'Scribe'): SafetyCase
    {
        $this->wikiPost('/api/wiki/v1/submissions', [
            'type' => 'report',
            'flow' => 'jev',
            'wiki' => 'oasiswiki',
            'anonymous' => true,
            'automated' => true,
            'answers' => [
                'page' => [$page],
                'wiki' => ['oasiswiki'],
                'user' => [$author],
                'details' => 'Jev flagged an edit.',
                'revision' => $revision,
                'revision_url' => "https://oasiswiki.wikioasis.org/w/index.php?title=Great_War&diff={$revision}&oldid=99",
                'scan_mode' => 'edit',
                'edit_summary' => 'lore',
                'jev' => ['harm' => 0.71, 'vandalism' => 0.1],
            ],
            'categories' => [['id' => 'violence', 'label' => 'Violence', 'group' => 'automated']],
        ])->assertStatus(201);

        return SafetyCase::query()->latest('id')->firstOrFail();
    }

    private function manual(): SafetyCase
    {
        $this->wikiPost('/api/wiki/v1/submissions', [
            'type' => 'report',
            'wiki' => 'oasiswiki',
            'reporter' => ['central_id' => 7, 'username' => 'Halcyon Reed'],
            'answers' => [
                'report' => 'Someone keeps harassing me',
                'revision' => 100,
                'revision_url' => 'https://oasiswiki.wikioasis.org/w/index.php?diff=100',
            ],
        ])->assertStatus(201);

        return SafetyCase::query()->latest('id')->firstOrFail();
    }

    #[Test]
    public function an_automated_report_is_classified_when_it_arrives(): void
    {
        $this->verdict = 'urgent';

        $case = $this->automated();
        $review = $case->automatedReview()->firstOrFail();

        $this->assertSame(AutomatedReview::STATE_DONE, $review->state);
        $this->assertSame('urgent', $review->bucket);
        $this->assertSame(['fiction', 'on-topic'], $review->signals);
        $this->assertSame('diff', $review->evidence_source);
        $this->assertTrue($review->evidence['reverted']);
        $this->assertFalse($review->evidence['current']);
        $this->assertSame(['sysop'], $review->evidence['author']['groups']);
        $this->assertStringContainsString('+ ', $review->evidence['diff']);
        $this->assertStringContainsString('thousands died', $review->evidence['diff']);
        $this->assertSame(SafetyCase::PRIORITY_URGENT, $case->refresh()->priority);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'openrouter.test')
            && $r['model'] === 'z-ai/glm-5.3-flash'
            && $r->hasHeader('Authorization', 'Bearer test-key'));
    }

    #[Test]
    public function a_report_filed_by_a_person_is_never_sent_to_the_model(): void
    {
        $case = $this->manual();

        $this->assertNull($case->automatedReview()->first());
        $this->assertSame(0, $this->modelCalls);

        $autoReview = app(AutoReview::class);
        $this->assertNull($autoReview->classify($case->id));
        $this->assertSame(0, $autoReview->queueWaiting(caseIds: [$case->id]));
        $this->assertSame(0, $autoReview->trackMissing());

        $this->actingAs($this->staff())
            ->postJson('/api/portal/autoreview/classify', ['case_ids' => [$case->id]])
            ->assertOk()
            ->assertJsonPath('queued', 0);

        $this->actingAs($this->staff())
            ->putJson("/api/portal/autoreview/items/{$case->id}/bucket", ['bucket' => 'urgent'])
            ->assertStatus(422);

        $this->actingAs($this->staff())
            ->getJson("/api/portal/autoreview/items/{$case->id}")
            ->assertNotFound();

        $this->assertSame(0, $this->modelCalls);
        $this->assertSame(0, AutomatedReview::query()->count());
    }

    #[Test]
    public function a_manual_report_cannot_be_closed_or_merged_through_automated_review(): void
    {
        $manual = $this->manual();
        $automated = $this->automated();

        $this->actingAs($this->staff())
            ->postJson('/api/portal/autoreview/close', ['case_ids' => [$manual->id, $automated->id]])
            ->assertOk()
            ->assertJsonPath('closed', [$automated->id])
            ->assertJsonPath('skipped', [$manual->id]);

        $this->assertSame(SafetyCase::STATUS_RECEIVED, $manual->refresh()->status);
        $this->assertSame(SafetyCase::STATUS_REJECTED, $automated->refresh()->status);

        $this->actingAs($this->staff())
            ->postJson('/api/portal/autoreview/merge', ['case_ids' => [$manual->id, $automated->id]])
            ->assertStatus(422);
    }

    #[Test]
    public function nothing_is_sent_while_automated_review_is_switched_off(): void
    {
        config()->set('autoreview.enabled', false);

        $case = $this->automated();

        $this->assertSame(AutomatedReview::STATE_PENDING, $case->automatedReview()->firstOrFail()->state);
        $this->assertSame(0, $this->modelCalls);
    }

    #[Test]
    public function the_same_revision_flagged_twice_is_folded_into_the_first_report(): void
    {
        $first = $this->automated();
        $second = $this->automated();

        $this->assertSame(SafetyCase::STATUS_DUPLICATE, $second->refresh()->status);
        $this->assertSame($first->id, $second->duplicate_of_id);
        $this->assertSame(AutomatedReview::STATE_MERGED, $second->automatedReview->state);
        $this->assertSame(1, $this->modelCalls);
    }

    #[Test]
    public function the_triage_endpoints_sort_group_and_close(): void
    {
        $this->verdict = 'unlikely';
        $a = $this->automated(100, 'Great War');
        $b = $this->automated(200, 'Great War');
        $this->verdict = 'review';
        $c = $this->automated(300, 'Other page', 'Vandal');

        $staff = $this->staff();

        $this->actingAs($staff)->getJson('/api/portal/autoreview')
            ->assertOk()
            ->assertJsonPath('counts.unlikely', 2)
            ->assertJsonPath('counts.review', 1)
            ->assertJsonPath('counts.open', 3);

        $this->actingAs($staff)->getJson('/api/portal/autoreview/items?view=unlikely')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.review.bucket', 'unlikely')
            ->assertJsonPath('data.0.review.facts.reverted', true)
            ->assertJsonMissingPath('data.0.review.evidence');

        $this->actingAs($staff)->getJson("/api/portal/autoreview/items/{$a->id}")
            ->assertOk()
            ->assertJsonPath('data.review.evidence.truncated', false);

        $this->actingAs($staff)->getJson('/api/portal/autoreview/groups?by=page')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Great War')
            ->assertJsonPath('data.0.unlikely', 2);

        $this->actingAs($staff)->putJson("/api/portal/autoreview/items/{$c->id}/bucket", ['bucket' => 'urgent'])
            ->assertOk()
            ->assertJsonPath('data.review.bucket', 'urgent')
            ->assertJsonPath('data.review.model_bucket', 'review')
            ->assertJsonPath('counts.urgent', 1);

        $this->assertSame(SafetyCase::PRIORITY_URGENT, $c->refresh()->priority);

        $this->actingAs($staff)->postJson('/api/portal/autoreview/close', ['case_ids' => [$a->id, $b->id], 'note' => 'Lore.'])
            ->assertOk()
            ->assertJsonPath('counts.unlikely', 0);

        $this->assertSame('Lore.', $a->refresh()->resolution);

        $stats = $this->actingAs($staff)->getJson('/api/portal/autoreview/stats')->assertOk()->json();
        $unlikely = collect($stats['buckets'])->firstWhere('bucket', 'unlikely');
        $review = collect($stats['buckets'])->firstWhere('bucket', 'review');

        $this->assertSame(2, $unlikely['no_action']);
        $this->assertSame(1, $review['moved_to']['urgent']);
    }

    #[Test]
    public function the_queue_puts_urgent_automated_flags_ahead_of_unlikely_ones(): void
    {
        $this->verdict = 'unlikely';
        $unlikely = $this->automated(100);
        config()->set('autoreview.set_priority', false);
        $this->verdict = 'urgent';
        $urgent = $this->automated(200);

        $order = $this->actingAs($this->staff())
            ->getJson('/api/portal/cases?source=automated')
            ->assertOk()
            ->json('data.*.reference');

        $this->assertSame([$urgent->reference, $unlikely->reference], $order);

        $this->actingAs($this->staff())
            ->getJson('/api/portal/cases?bucket=unlikely')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.autoreview.bucket', 'unlikely');
    }

    #[Test]
    public function a_wiki_that_cannot_be_read_still_gets_classified_from_metadata(): void
    {
        $this->wikiDown = true;
        $this->modelAnswer = '{"bucket":"review","confidence":40}';

        $case = $this->automated();
        $review = $case->automatedReview()->firstOrFail();

        $this->assertSame('metadata', $review->evidence_source);
        $this->assertSame('review', $review->bucket);
        $this->assertSame(0.4, $review->confidence);
        $this->assertStringContainsString('HTTP 503', (string) $review->evidence['problem']);
    }

    #[Test]
    public function a_staff_priority_change_is_not_overwritten(): void
    {
        config()->set('autoreview.enabled', false);
        $case = $this->automated();
        $case->forceFill(['priority' => SafetyCase::PRIORITY_NORMAL])->save();

        config()->set('autoreview.enabled', true);
        $this->verdict = 'unlikely';
        app(AutoReview::class)->classify($case->id);

        $this->assertSame(SafetyCase::PRIORITY_NORMAL, $case->refresh()->priority);
    }

    #[Test]
    public function the_parser_refuses_an_answer_without_a_bucket(): void
    {
        $classifier = app(Classifier::class);

        $this->assertSame('unlikely', $classifier->parse('<think>hmm</think>{"bucket":"low"}')['bucket']);

        $this->expectException(ClassificationFailed::class);
        $classifier->parse('{"bucket":"maybe"}');
    }

    #[Test]
    public function the_diff_marks_the_changed_part_of_a_long_line(): void
    {
        $long = str_repeat('Lorem ipsum dolor sit amet. ', 40);
        [$diff] = (new LineDiff)->render("a\n{$long}END\nb", "a\n{$long}INSERTED END\nb", 5000);

        $this->assertStringContainsString('[+INSERTED +]', $diff);
        $this->assertLessThan(1200, mb_strlen($diff));
    }

    #[Test]
    public function taking_a_flag_claims_it_records_agreement_and_hides_it_from_everyone_else(): void
    {
        $this->verdict = 'urgent';
        $case = $this->automated();
        $me = $this->staff();
        $other = User::create(['username' => 'Other', 'mw_central_id' => 901, 'flags' => [User::FLAG_TS], 'active' => true]);

        $this->actingAs($me)->postJson("/api/portal/autoreview/items/{$case->id}/take")
            ->assertOk()
            ->assertJsonPath('data.assignee.username', 'Reviewer')
            ->assertJsonPath('data.review.confirmed_by', 'Reviewer');

        $this->assertSame(SafetyCase::STATUS_IN_REVIEW, $case->refresh()->status);

        $this->actingAs($me)->getJson('/api/portal/autoreview/items?view=urgent')->assertJsonCount(1, 'data');
        $this->actingAs($other)->getJson('/api/portal/autoreview/items?view=urgent')->assertJsonCount(0, 'data');
        $this->actingAs($other)->getJson('/api/portal/autoreview/items?view=urgent&taken=show')->assertJsonCount(1, 'data');

        $this->actingAs($other)->postJson("/api/portal/autoreview/items/{$case->id}/take")
            ->assertStatus(422)
            ->assertJsonPath('message', "{$case->reference} has already been taken by Reviewer.");

        $urgent = collect($this->actingAs($me)->getJson('/api/portal/autoreview/stats')->json('buckets'))->firstWhere('bucket', 'urgent');
        $this->assertSame(1, $urgent['taken']);
        $this->assertSame(1, $urgent['agreed']);
    }

    #[Test]
    public function the_dashboard_keeps_automated_flags_out_of_just_in_and_counts_them_apart(): void
    {
        $this->verdict = 'urgent';
        $this->automated();
        $person = $this->manual();

        $this->actingAs($this->staff())->getJson('/api/portal/dashboard')
            ->assertOk()
            ->assertJsonPath('recent.0.reference', $person->reference)
            ->assertJsonCount(1, 'recent')
            ->assertJsonPath('open_items.automated', 1)
            ->assertJsonPath('automation.urgent', 1)
            ->assertJsonPath('automation.unassigned_urgent', 1);
    }

    #[Test]
    public function automated_triage_and_the_staff_working_it_are_announced_in_slack(): void
    {
        config()->set('slack.enabled', true);
        config()->set('slack.queue', false);
        config()->set('slack.webhooks', ['default' => 'https://hooks.slack.test/default']);

        $said = function (): string {
            $lines = [];
            foreach (Http::recorded() as [$request]) {
                if (str_contains($request->url(), 'hooks.slack.test')) {
                    $lines[] = $request['text'];
                }
            }

            return implode("\n", $lines);
        };

        $this->verdict = 'review';
        $first = $this->automated(101);
        $this->verdict = 'unlikely';
        $second = $this->automated(102);
        $repeat = $this->automated(102);

        $this->actingAs($this->staff())
            ->putJson("/api/portal/autoreview/items/{$first->id}/bucket", ['bucket' => 'urgent'])
            ->assertOk();
        $this->actingAs($this->staff())
            ->postJson("/api/portal/autoreview/items/{$first->id}/take")
            ->assertOk();
        $this->actingAs($this->staff())
            ->putJson("/api/portal/cases/{$second->id}/categories", ['categories' => [['id' => 'harassment', 'label' => 'Harassment']]])
            ->assertOk();

        $text = $said();

        $this->assertStringContainsString("sorted *{$first->reference}* as _needs review_", $text);
        $this->assertStringContainsString("sorted *{$second->reference}* as _unlikely to need review_", $text);
        $this->assertStringContainsString("*{$repeat->reference}* flagged the same revision again and was merged into *{$second->reference}*", $text);
        $this->assertStringContainsString("*{$first->reference}* moved from _needs review_ to _needs review quickly_ by Reviewer", $text);
        $this->assertStringContainsString("*{$first->reference}* taken by Reviewer", $text);
        $this->assertStringContainsString("*{$second->reference}* re-filed under Harassment", $text);
        $this->assertStringNotContainsString('priority by', $text, 'the priority triage sets is part of the triage line, not its own message');
    }
}
