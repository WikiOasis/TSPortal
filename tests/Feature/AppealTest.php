<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\OutboundEvent;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\Analytics;
use App\Services\Safety\AppealMatch;
use App\Services\Safety\AppealParser;
use App\Services\Safety\AppealService;
use App\Services\Safety\CaseService;
use App\Services\Safety\InvestigationService;
use App\Services\Safety\SanctionService;
use App\Services\Safety\Transparency;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\SignsWikiRequests;
use Tests\TestCase;

class AppealTest extends TestCase
{
    use RefreshDatabase;
    use SignsWikiRequests;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['result' => 'ok'])]);
    }

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
            ['title' => 'Test file', 'premise' => 'Opened by the suite.'],
            $this->staff(),
        );
    }

    private function action(Subject $subject, array $input = []): Sanction
    {
        return app(SanctionService::class)->issue(
            $subject,
            array_merge([
                'type' => Sanction::TYPE_LOCK,
                'reason' => 'Repeated threats after a final warning.',
                'reason_category' => 'harassment',
            ], $input),
            $this->staff(),
            $this->file(),
        );
    }

    private function appeal(string $username, string $body, ?string $reference = null): SafetyCase
    {
        $response = $this->wikiPost('/api/wiki/v1/appeals', array_filter([
            'username' => $username,
            'body' => $body,
            'sanction_reference' => $reference,
        ]));

        $response->assertSuccessful();

        return SafetyCase::query()->where('reference', $response->json('reference'))->firstOrFail();
    }

    #[Test]
    public function a_reference_they_gave_is_taken_as_given(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $action = $this->action($subject);

        $case = $this->appeal('Halcyon Reed', 'It was not me.', $action->reference);

        $this->assertSame($action->id, $case->sanction_id);
        $this->assertSame(AppealMatch::SOURCE_STATED, $case->appeal_link_source);
        $this->assertSame(AppealMatch::CERTAIN, $case->appeal_link_confidence);

        $this->assertFalse($case->appealLinkNeedsChecking());
    }

    #[Test]
    public function a_reference_buried_in_what_they_wrote_is_found(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $older = $this->action($subject, ['type' => Sanction::TYPE_WARNING]);
        $this->action($subject);

        $case = $this->appeal(
            'Halcyon Reed',
            "I am writing about {$older->reference}, which I think was a misunderstanding.",
        );

        $this->assertSame($older->id, $case->sanction_id);
        $this->assertSame(AppealMatch::SOURCE_QUOTED, $case->appeal_link_source);
    }

    #[Test]
    public function somebody_elses_reference_is_not_followed(): void
    {
        $them = Subject::forUsername('Halcyon Reed', 42);
        $someoneElse = Subject::forUsername('Bright Kettle', 43);
        $theirs = $this->action($someoneElse);

        $case = $this->appeal('Halcyon Reed', "My friend got {$theirs->reference} and I got the same.");

        $this->assertNull($case->sanction_id);
        $this->assertSame(AppealMatch::SOURCE_NONE, $case->appeal_link_source);

        $this->assertStringContainsString(
            'not the account appealing',
            implode(' ', $case->appeal_link_notes['notes'] ?? []),
        );
    }

    #[Test]
    public function the_only_action_on_file_is_linked_but_still_wants_checking(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $action = $this->action($subject);

        $case = $this->appeal('Halcyon Reed', 'I have been blocked and I do not know why.');

        $this->assertSame($action->id, $case->sanction_id);
        $this->assertSame(AppealMatch::SOURCE_ONLY, $case->appeal_link_source);

        $this->assertTrue($case->appealLinkNeedsChecking());
    }

    #[Test]
    public function the_most_recent_of_several_is_recorded_as_a_guess(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $this->action($subject, ['type' => Sanction::TYPE_WARNING]);
        $this->travel(1)->days();
        $newest = $this->action($subject);

        $case = $this->appeal('Halcyon Reed', 'Please look at this again.');

        $this->assertSame($newest->id, $case->sanction_id);
        $this->assertSame(AppealMatch::SOURCE_LATEST, $case->appeal_link_source);
        $this->assertSame(AppealMatch::GUESS, $case->appeal_link_confidence);

        $this->assertCount(2, $case->appeal_link_notes['considered'] ?? []);
    }

    #[Test]
    public function an_appeal_from_somebody_with_no_actions_is_attached_to_nothing(): void
    {
        Subject::forUsername('Halcyon Reed', 42);

        $case = $this->appeal('Halcyon Reed', 'I think I have been blocked.');

        $this->assertNull($case->sanction_id);
        $this->assertSame(AppealMatch::SOURCE_NONE, $case->appeal_link_source);

        $this->assertStringContainsString(
            'no actions on file',
            implode(' ', $case->appeal_link_notes['notes'] ?? []),
        );
    }

    #[Test]
    public function a_guess_does_not_swallow_a_second_appeal(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $this->action($subject, ['type' => Sanction::TYPE_WARNING]);
        $this->travel(1)->days();
        $this->action($subject);

        $this->appeal('Halcyon Reed', 'About the warning.');
        $this->appeal('Halcyon Reed', 'And separately, about the suspension.');

        $this->assertSame(2, SafetyCase::query()->where('type', SafetyCase::TYPE_APPEAL)->count());
    }

    #[Test]
    public function a_second_appeal_against_a_reference_they_gave_is_still_one_case(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $action = $this->action($subject);

        $this->appeal('Halcyon Reed', 'It was not me.', $action->reference);
        $this->appeal('Halcyon Reed', 'Please, it was not me.', $action->reference);

        $this->assertSame(1, SafetyCase::query()->where('type', SafetyCase::TYPE_APPEAL)->count());
    }

    #[Test]
    public function a_person_can_overrule_the_portal_and_the_answer_sticks(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $warning = $this->action($subject, ['type' => Sanction::TYPE_WARNING]);
        $this->travel(1)->days();
        $this->action($subject);

        $case = $this->appeal('Halcyon Reed', 'Please look at this again.');
        $this->assertSame(AppealMatch::SOURCE_LATEST, $case->appeal_link_source);

        $this->actingAs($this->staff())
            ->putJson("/api/portal/cases/{$case->id}/appeal/action", ['reference' => $warning->reference])
            ->assertOk();

        $case->refresh();

        $this->assertSame($warning->id, $case->sanction_id);
        $this->assertSame(AppealMatch::SOURCE_STAFF, $case->appeal_link_source);

        $this->assertSame(AppealMatch::CERTAIN, $case->appeal_link_confidence);
        $this->assertFalse($case->appealLinkNeedsChecking());

        $this->assertSame('Admin', $case->appeal_link_notes['corrected']['by'] ?? null);
    }

    #[Test]
    public function the_link_can_be_cleared_entirely(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $this->action($subject);

        $case = $this->appeal('Halcyon Reed', 'I have been blocked.');
        $this->assertNotNull($case->sanction_id);

        $this->actingAs($this->staff())
            ->putJson("/api/portal/cases/{$case->id}/appeal/action", ['reference' => null])
            ->assertOk();

        $this->assertNull($case->refresh()->sanction_id);
    }

    #[Test]
    public function an_appeal_cannot_be_attached_to_another_accounts_action(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $this->action($subject);
        $theirs = $this->action(Subject::forUsername('Bright Kettle', 43));

        $case = $this->appeal('Halcyon Reed', 'I have been blocked.');

        $this->actingAs($this->staff())
            ->putJson("/api/portal/cases/{$case->id}/appeal/action", ['reference' => $theirs->reference])
            ->assertStatus(422);
    }

    #[Test]
    public function granting_an_appeal_lifts_the_action(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $action = $this->action($subject);

        $case = $this->appeal('Halcyon Reed', 'It was not me.', $action->reference);

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/appeal/decision", [
                'outcome' => SafetyCase::APPEAL_GRANTED,
                'note' => 'We have looked at this again and lifted it.',
            ])
            ->assertCreated()
            ->assertJsonPath('outcome', SafetyCase::APPEAL_GRANTED);

        $this->assertFalse($action->refresh()->isInForce());
        $this->assertNotNull($action->lifted_at);

        $this->assertStringContainsString($case->reference, (string) $action->lift_reason);

        $case->refresh();
        $this->assertTrue($case->appealAccepted());

        $this->assertNotNull($case->closed_at);
    }

    #[Test]
    public function an_appeal_against_nothing_cannot_be_granted(): void
    {
        Subject::forUsername('Halcyon Reed', 42);
        $case = $this->appeal('Halcyon Reed', 'I think I have been blocked.');

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/appeal/decision", [
                'outcome' => SafetyCase::APPEAL_GRANTED,
                'note' => 'Fine.',
            ])
            ->assertStatus(422);

        $this->assertNull($case->refresh()->appeal_outcome);
    }

    #[Test]
    public function an_appeal_against_nothing_can_still_be_answered(): void
    {
        Subject::forUsername('Halcyon Reed', 42);
        $case = $this->appeal('Halcyon Reed', 'I think I have been blocked.');

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/appeal/decision", [
                'outcome' => SafetyCase::APPEAL_INVALID,
                'note' => 'We cannot find any action against your account.',
            ])
            ->assertCreated();

        $this->assertSame(SafetyCase::APPEAL_INVALID, $case->refresh()->appeal_outcome);
    }

    #[Test]
    public function declining_closes_it_as_considered_rather_than_filed_away(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $action = $this->action($subject);
        $case = $this->appeal('Halcyon Reed', 'It was not me.', $action->reference);

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/appeal/decision", [
                'outcome' => SafetyCase::APPEAL_DECLINED,
                'note' => 'The evidence is unchanged, so the suspension stands.',
            ])
            ->assertCreated();

        $case->refresh();

        $this->assertSame(SafetyCase::STATUS_REJECTED, $case->status);
        $this->assertTrue($action->refresh()->isInForce());

        $this->assertTrue(
            $case->publicComments()->where('body', 'like', '%suspension stands%')->exists(),
            'The reason for refusing an appeal never reached the person refused.'
        );
    }

    #[Test]
    public function a_decision_needs_something_the_appellant_can_read(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $action = $this->action($subject);
        $case = $this->appeal('Halcyon Reed', 'It was not me.', $action->reference);

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/appeal/decision", [
                'outcome' => SafetyCase::APPEAL_GRANTED,
                'note' => '   ',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function an_appeal_is_only_decided_once(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $action = $this->action($subject);
        $case = $this->appeal('Halcyon Reed', 'It was not me.', $action->reference);

        $decide = fn (string $outcome) => $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/appeal/decision", [
                'outcome' => $outcome,
                'note' => 'Answered.',
            ]);

        $decide(SafetyCase::APPEAL_DECLINED)->assertCreated();
        $decide(SafetyCase::APPEAL_GRANTED)->assertStatus(422);

        $this->assertSame(SafetyCase::APPEAL_DECLINED, $case->refresh()->appeal_outcome);
    }

    private function decided(string $reason, string $outcome, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $subject = Subject::forUsername(sprintf('Account %s %s %d', $reason, $outcome, $i));
            $action = $this->action($subject, ['reason_category' => $reason]);

            $case = app(CaseService::class)->createFromSubmission([
                'type' => SafetyCase::TYPE_APPEAL,
                'reporter' => ['username' => $subject->username],
                'summary' => 'Please look at this again.',
                'sanction_reference' => $action->reference,
            ]);

            app(AppealService::class)->decide($case, $outcome, $this->staff(), 'Answered.');
        }
    }

    #[Test]
    public function the_transparency_report_gives_an_acceptance_rate_per_infraction(): void
    {
        $this->decided('sockpuppetry', SafetyCase::APPEAL_GRANTED, 6);
        $this->decided('sockpuppetry', SafetyCase::APPEAL_DECLINED, 6);
        $this->decided('harassment', SafetyCase::APPEAL_DECLINED, 12);

        $figures = app(Transparency::class)->figures(
            CarbonImmutable::now()->subMonth(),
            CarbonImmutable::now()->addDay(),
            threshold: 5,
        );

        $rows = collect($figures['appeals']['by_infraction']['rows'])->keyBy('key');

        $this->assertSame(12, $rows['sockpuppetry']['decided']);
        $this->assertSame(6, $rows['sockpuppetry']['accepted']);
        $this->assertSame(50, $rows['sockpuppetry']['share']);

        $this->assertSame(12, $rows['harassment']['decided']);
        $this->assertSame(0, $rows['harassment']['share']);
    }

    #[Test]
    public function withdrawn_appeals_are_not_counted_as_refusals(): void
    {
        $this->decided('spam', SafetyCase::APPEAL_GRANTED, 6);
        $this->decided('spam', SafetyCase::APPEAL_DECLINED, 6);
        $this->decided('spam', SafetyCase::APPEAL_WITHDRAWN, 12);

        $figures = app(Transparency::class)->figures(
            CarbonImmutable::now()->subMonth(),
            CarbonImmutable::now()->addDay(),
            threshold: 5,
        );

        $this->assertSame(24, $figures['appeals']['decided']['value']);
        $this->assertSame(12, $figures['appeals']['decided_on_the_merits']['value']);
        $this->assertSame(50, $figures['appeals']['accepted_share']);
    }

    #[Test]
    public function a_rate_over_too_few_appeals_is_not_published(): void
    {
        $this->decided('child-protection', SafetyCase::APPEAL_DECLINED, 2);

        $figures = app(Transparency::class)->figures(
            CarbonImmutable::now()->subMonth(),
            CarbonImmutable::now()->addDay(),
            threshold: 5,
        );

        $this->assertSame([], $figures['appeals']['by_infraction']['rows']);
        $this->assertSame(1, $figures['appeals']['by_infraction']['withheld']['rows']);
        $this->assertNull($figures['appeals']['accepted_share']);
    }

    #[Test]
    public function the_report_says_how_many_appeals_it_could_not_place(): void
    {
        Subject::forUsername('Halcyon Reed', 42);
        $this->appeal('Halcyon Reed', 'I think I have been blocked.');

        $figures = app(Transparency::class)->figures(
            CarbonImmutable::now()->subMonth(),
            CarbonImmutable::now()->addDay(),
            threshold: 0,
        );

        $this->assertSame(1, $figures['appeals']['not_linked_to_an_action']['value']);
    }

    #[Test]
    public function the_analytics_page_shows_the_same_rate_unsuppressed(): void
    {
        $this->decided('vandalism', SafetyCase::APPEAL_GRANTED, 1);
        $this->decided('vandalism', SafetyCase::APPEAL_DECLINED, 1);

        $overview = app(Analytics::class)->overview(
            CarbonImmutable::now()->subMonth(),
            CarbonImmutable::now()->addDay(),
        );

        $appeals = $overview['outcomes']['appeals'];

        $this->assertSame(2, $appeals['decided']);
        $this->assertSame(0.5, $appeals['accepted_share']);

        $row = collect($appeals['by_infraction'])->firstWhere('key', 'vandalism');
        $this->assertSame(2, $row['decided']);
        $this->assertSame(1, $row['accepted']);
    }

    #[Test]
    public function the_team_can_see_which_links_nobody_has_confirmed(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $action = $this->action($subject);

        $this->appeal('Halcyon Reed', 'I have been blocked.');

        $overview = app(Analytics::class)->overview(
            CarbonImmutable::now()->subMonth(),
            CarbonImmutable::now()->addDay(),
        );

        $this->assertSame(1, $overview['outcomes']['appeals']['link_needs_checking']);

        $case = SafetyCase::query()->where('type', SafetyCase::TYPE_APPEAL)->firstOrFail();
        app(AppealService::class)->link($case, $action, $this->staff());

        $overview = app(Analytics::class)->overview(
            CarbonImmutable::now()->subMonth(),
            CarbonImmutable::now()->addDay(),
        );

        $this->assertSame(0, $overview['outcomes']['appeals']['link_needs_checking']);
    }

    #[Test]
    public function the_parser_reads_every_answer_not_just_the_summary(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $action = $this->action($subject);

        $match = app(AppealParser::class)->parse(
            $subject,
            null,
            "which_action: {$action->reference}\nwhy: I have read the guideline now.",
        );

        $this->assertSame($action->id, $match->sanction?->id);
        $this->assertSame(AppealMatch::SOURCE_QUOTED, $match->source);
    }

    #[Test]
    public function the_appellants_own_page_is_told_which_action_it_is_against(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $action = $this->action($subject);
        $case = $this->appeal('Halcyon Reed', 'It was not me.', $action->reference);

        $payload = $this->wikiGet("/api/wiki/v1/accounts/42/reports/{$case->reference}")
            ->assertOk()
            ->json();

        $this->assertSame($action->reference, $payload['appeal']['action']['reference']);
        $this->assertSame($action->label, $payload['appeal']['action']['label']);
        $this->assertTrue($payload['appeal']['action']['in_force']);

        $this->assertNull($payload['appeal']['outcome']);
    }

    #[Test]
    public function a_report_is_not_given_an_appeal_block(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);

        $case = app(CaseService::class)->createFromSubmission([
            'type' => SafetyCase::TYPE_REPORT,
            'reporter' => ['username' => $subject->username],
            'summary' => 'Somebody is following me home.',
        ]);

        $payload = $this->wikiGet("/api/wiki/v1/accounts/42/reports/{$case->reference}")
            ->assertOk()
            ->json();

        $this->assertNull($payload['appeal']);
    }

    #[Test]
    public function the_decision_reaches_the_wiki_and_says_which_way_it_went(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $action = $this->action($subject);
        $case = $this->appeal('Halcyon Reed', 'It was not me.', $action->reference);

        app(AppealService::class)->decide(
            $case,
            SafetyCase::APPEAL_GRANTED,
            $this->staff(),
            'We have looked at this again and lifted it.',
        );

        $payload = $this->wikiGet("/api/wiki/v1/accounts/42/reports/{$case->reference}")
            ->assertOk()
            ->json();

        $this->assertSame(SafetyCase::APPEAL_GRANTED, $payload['appeal']['outcome']);
        $this->assertNotNull($payload['appeal']['decided']);

        $this->assertFalse($payload['appeal']['action']['in_force']);
    }

    #[Test]
    public function correcting_the_link_is_sent_to_the_wiki(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $warning = $this->action($subject, ['type' => Sanction::TYPE_WARNING]);
        $this->travel(1)->days();
        $this->action($subject);

        $case = $this->appeal('Halcyon Reed', 'Please look at this again.');

        OutboundEvent::query()->delete();

        app(AppealService::class)->link($case, $warning, $this->staff());

        $event = OutboundEvent::query()
            ->where('event', OutboundEvent::CASE_UPSERT)
            ->latest('id')
            ->first();

        $this->assertNotNull($event, 'Relinking an appeal never reached the wiki.');
        $this->assertSame($warning->reference, $event->payload['appeal']['action']['reference']);
    }

    #[Test]
    public function an_appeal_matched_to_nothing_says_so_rather_than_saying_nothing(): void
    {
        Subject::forUsername('Halcyon Reed', 42);
        $case = $this->appeal('Halcyon Reed', 'I think I have been blocked.');

        $payload = $this->wikiGet("/api/wiki/v1/accounts/42/reports/{$case->reference}")
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('appeal', $payload);
        $this->assertNull($payload['appeal']['action']);
    }

    private function contactAppeal(Subject $subject, string $target, string $grounds = 'Please look again.'): SafetyCase
    {
        $response = $this->wikiPost('/api/wiki/v1/submissions', [
            'type' => SafetyCase::TYPE_CONTACT,
            'flow' => 'contact',
            'reporter' => ['username' => $subject->username, 'central_id' => $subject->mw_central_id],
            'categories' => [[
                'id' => 'appeal',
                'label' => 'Appeal against an action',
                'group' => 'appeal',
                'field' => 'contact-reason',
            ]],
            'roles' => ['appeal_target' => 'appeal-target', 'details' => 'appeal-grounds'],
            'answers' => [
                'contact-reason' => 'appeal',
                'appeal-target' => $target,
                'appeal-grounds' => $grounds,
            ],
        ]);

        $response->assertCreated();

        return SafetyCase::query()->where('reference', $response->json('reference'))->firstOrFail();
    }

    #[Test]
    public function an_appeal_filed_through_the_contact_wizard_is_an_appeal(): void
    {
        $subject = Subject::forUsername('Zippybonzo', 42);
        $action = $this->action($subject);

        $case = $this->contactAppeal($subject, $action->reference);

        $this->assertSame(SafetyCase::TYPE_APPEAL, $case->type);

        $this->assertSame('contact', $case->flow);
    }

    #[Test]
    public function the_wizards_own_question_about_which_action_is_believed(): void
    {
        $subject = Subject::forUsername('Zippybonzo', 42);
        $this->action($subject, ['type' => Sanction::TYPE_WARNING]);
        $this->travel(1)->days();
        $newest = $this->action($subject);
        $this->travel(1)->days();
        $older = Sanction::query()->where('subject_id', $subject->id)->orderBy('issued_at')->first();

        $case = $this->contactAppeal($subject, $older->reference);

        $this->assertSame($older->id, $case->sanction_id, 'the action they picked, not the most recent');
        $this->assertSame(AppealMatch::SOURCE_STATED, $case->appeal_link_source);
        $this->assertFalse($case->appealLinkNeedsChecking());
        $this->assertNotSame($newest->id, $case->sanction_id);
    }

    #[Test]
    public function a_contact_appeal_can_be_decided_and_lifts_the_action(): void
    {
        $subject = Subject::forUsername('Zippybonzo', 42);
        $action = $this->action($subject);
        $case = $this->contactAppeal($subject, $action->reference);

        $this->actingAs($this->staff())
            ->postJson("/api/portal/cases/{$case->id}/appeal/decision", [
                'outcome' => SafetyCase::APPEAL_GRANTED,
                'note' => 'Looked at again; the warning is withdrawn.',
            ])
            ->assertCreated();

        $this->assertFalse($action->refresh()->isInForce());
    }

    #[Test]
    public function the_case_page_carries_the_appeal_panel_for_a_contact_appeal(): void
    {
        $subject = Subject::forUsername('Zippybonzo', 42);
        $action = $this->action($subject, ['reason_category' => 'spam']);
        $case = $this->contactAppeal($subject, $action->reference);

        $payload = $this->actingAs($this->staff())
            ->getJson("/api/portal/cases/{$case->id}")
            ->assertOk()
            ->json('data');

        $this->assertNotNull($payload['appeal'] ?? null, 'the case page has no appeal panel to render');
        $this->assertSame($action->reference, $payload['appeal']['action']['reference']);

        $this->assertSame('Spam or advertising', $payload['appeal']['action']['reason_category_label']);
        $this->assertTrue($payload['appeal']['action']['in_force']);
        $this->assertNotEmpty($payload['appeal']['options'], 'nothing to relink to');
    }

    #[Test]
    public function a_data_request_is_never_turned_into_an_appeal(): void
    {
        $subject = Subject::forUsername('Zippybonzo', 42);

        $response = $this->wikiPost('/api/wiki/v1/submissions', [
            'type' => SafetyCase::TYPE_DATA,
            'flow' => 'data',
            'reporter' => ['username' => $subject->username],
            'categories' => [['id' => 'appeal', 'label' => 'Appeal', 'group' => 'appeal']],
            'answers' => ['what' => 'Please delete my data.'],
        ])->assertCreated();

        $case = SafetyCase::query()->where('reference', $response->json('reference'))->firstOrFail();

        $this->assertSame(SafetyCase::TYPE_DATA, $case->type);
    }

    #[Test]
    public function an_ordinary_message_stays_a_message(): void
    {
        $subject = Subject::forUsername('Zippybonzo', 42);

        $response = $this->wikiPost('/api/wiki/v1/submissions', [
            'type' => SafetyCase::TYPE_CONTACT,
            'flow' => 'contact',
            'reporter' => ['username' => $subject->username],
            'categories' => [['id' => 'other', 'label' => 'Something else', 'group' => 'other']],
            'answers' => ['contact-reason' => 'other', 'what' => 'A general question.'],
        ])->assertCreated();

        $case = SafetyCase::query()->where('reference', $response->json('reference'))->firstOrFail();

        $this->assertSame(SafetyCase::TYPE_CONTACT, $case->type);
    }

    #[Test]
    public function the_backfill_re_reads_appeals_that_were_filed_as_messages(): void
    {
        $subject = Subject::forUsername('Zippybonzo', 42);
        $action = $this->action($subject);

        $case = SafetyCase::create([
            'reference' => SafetyCase::nextReference('legacy'),
            'type' => SafetyCase::TYPE_CONTACT,
            'flow' => 'contact',
            'subject_line' => 'Message to Trust & Safety',
            'reporter_subject_id' => $subject->id,
            'answers' => [
                'contact-reason' => 'appeal',
                'appeal-target' => $action->reference,
                'appeal-grounds' => 'I say so',
            ],
        ]);

        $case->categories()->create([
            'category' => 'appeal',
            'label' => 'Appeal against an action',
            'group' => 'appeal',
            'source_field' => 'contact-reason',
            'is_primary' => true,
        ]);

        $this->migration()->up();

        $case->refresh();

        $this->assertSame(SafetyCase::TYPE_APPEAL, $case->type);
        $this->assertSame($action->id, $case->sanction_id);

        $this->assertStringContainsString($action->reference, $case->subject_line);

        $this->assertArrayHasKey('backfilled', $case->appeal_link_notes);
    }

    #[Test]
    public function the_backfill_leaves_messages_and_data_requests_alone(): void
    {
        $subject = Subject::forUsername('Zippybonzo', 42);

        $message = SafetyCase::create([
            'reference' => SafetyCase::nextReference('a message'),
            'type' => SafetyCase::TYPE_CONTACT,
            'subject_line' => 'Message to Trust & Safety',
            'reporter_subject_id' => $subject->id,
        ]);
        $message->categories()->create([
            'category' => 'other', 'label' => 'Something else', 'group' => 'other', 'is_primary' => true,
        ]);

        $data = SafetyCase::create([
            'reference' => SafetyCase::nextReference('a data request'),
            'type' => SafetyCase::TYPE_DATA,
            'subject_line' => 'Data request',
            'reporter_subject_id' => $subject->id,
        ]);
        $data->categories()->create([
            'category' => 'appeal', 'label' => 'Appeal', 'group' => 'appeal', 'is_primary' => true,
        ]);

        $this->migration()->up();

        $this->assertSame(SafetyCase::TYPE_CONTACT, $message->refresh()->type);
        $this->assertSame(SafetyCase::TYPE_DATA, $data->refresh()->type);
    }

    #[Test]
    public function the_backfill_does_not_move_a_link_somebody_already_made(): void
    {
        $subject = Subject::forUsername('Zippybonzo', 42);
        $chosen = $this->action($subject, ['type' => Sanction::TYPE_WARNING]);
        $this->travel(1)->days();
        $this->action($subject);

        $case = SafetyCase::create([
            'reference' => SafetyCase::nextReference('legacy'),
            'type' => SafetyCase::TYPE_CONTACT,
            'subject_line' => 'Message to Trust & Safety',
            'reporter_subject_id' => $subject->id,
            'sanction_id' => $chosen->id,
        ]);
        $case->categories()->create([
            'category' => 'appeal', 'label' => 'Appeal', 'group' => 'appeal', 'is_primary' => true,
        ]);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($chosen->id, $case->refresh()->sanction_id);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_25_000200_retype_appeals_filed_as_messages.php');
    }
}
