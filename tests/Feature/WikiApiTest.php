<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CaseComment;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use App\Services\Safety\InvestigationService;
use App\Services\Safety\SanctionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\SignsWikiRequests;
use Tests\TestCase;

class WikiApiTest extends TestCase
{
    use RefreshDatabase;

    private function file(User $staff): Investigation
    {
        return app(InvestigationService::class)->open(['title' => 'Test file'], $staff);
    }

    use SignsWikiRequests;

    #[Test]
    public function an_unsigned_request_is_refused(): void
    {
        $this->postJson('/api/wiki/v1/submissions', ['type' => 'report'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'bad-signature');
    }

    #[Test]
    public function the_same_signed_request_cannot_be_sent_twice(): void
    {
        $path = '/api/wiki/v1/health';
        $headers = $this->signed('GET', $path);

        $this->wikiGet($path, $headers)->assertOk();

        $this->wikiGet($path, $headers)
            ->assertStatus(409)
            ->assertJsonPath('error', 'replayed');
    }

    #[Test]
    public function a_wizard_submission_becomes_a_case(): void
    {
        $response = $this->wikiPost('/api/wiki/v1/submissions', [
            'type' => 'report',
            'flow' => 'report',
            'wiki' => 'oasis.example',
            'anonymous' => false,
            'reporter' => ['central_id' => 42, 'username' => 'Halcyon Reed'],
            'answers' => [
                'users' => ['User:Bright Kettle Media'],
                'pages' => ['Talk:Pumice'],
                'details' => 'Twelve outbound links to the same shop.',
            ],
            'roles' => ['users' => 'users', 'pages' => 'pages', 'details' => 'details'],
        ])->assertCreated();

        $case = SafetyCase::query()->firstOrFail();

        $this->assertSame($response->json('reference'), $case->reference);
        $this->assertStringStartsWith('TS-', $case->reference);
        $this->assertSame('received', $case->status);
        $this->assertSame('oasis.example', $case->wiki);
        $this->assertSame('Twelve outbound links to the same shop.', $case->summary);

        $this->assertEqualsCanonicalizing(
            ['User:Bright Kettle Media', 'Talk:Pumice'],
            $case->about,
        );
        $this->assertSame(['Bright Kettle Media'], $case->subjects->pluck('username')->all());
        $this->assertSame('Halcyon Reed', $case->reporter->username);
    }

    #[Test]
    public function an_anonymous_report_is_never_listed_back(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);

        SafetyCase::create([
            'reference' => 'TS-2026-0001', 'type' => 'report', 'subject_line' => 'Named',
            'reporter_subject_id' => $subject->id, 'anonymous' => false,
        ]);
        SafetyCase::create([
            'reference' => 'TS-2026-0002', 'type' => 'report', 'subject_line' => 'Anonymous',
            'reporter_subject_id' => $subject->id, 'anonymous' => true,
        ]);

        $response = $this->wikiGet('/api/wiki/v1/accounts/42/reports')->assertOk();

        $this->assertSame(['TS-2026-0001'], array_column($response->json('reports'), 'id'));

        $this->assertTrue($response->json('hasAnonymous'));
    }

    #[Test]
    public function one_account_cannot_read_another_accounts_report(): void
    {
        $mine = Subject::forUsername('Halcyon Reed', 42);
        Subject::forUsername('Quiet Marlin', 43);

        SafetyCase::create([
            'reference' => 'TS-2026-0001', 'type' => 'report', 'subject_line' => 'Mine',
            'reporter_subject_id' => $mine->id,
        ]);

        $this->wikiGet('/api/wiki/v1/accounts/43/reports/TS-2026-0001')->assertNotFound();
    }

    #[Test]
    public function only_public_comments_reach_the_wiki(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $case = SafetyCase::create([
            'reference' => 'TS-2026-0001', 'type' => 'report', 'subject_line' => 'A report',
            'reporter_subject_id' => $subject->id,
        ]);

        CaseComment::create([
            'case_id' => $case->id, 'author_type' => 'staff', 'author_label' => 'Trust & Safety',
            'body' => 'We are looking at it.', 'visibility' => 'public',
        ]);
        CaseComment::create([
            'case_id' => $case->id, 'author_type' => 'staff', 'author_label' => 'Trust & Safety',
            'body' => 'Cross-check the IP range before replying.', 'visibility' => 'internal',
        ]);

        $comments = $this->wikiGet('/api/wiki/v1/accounts/42/reports/TS-2026-0001')
            ->assertOk()
            ->json('comments');

        $this->assertCount(1, $comments);
        $this->assertSame('We are looking at it.', $comments[0]['text']);
    }

    #[Test]
    public function a_reporter_can_reply_from_the_wiki(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $case = SafetyCase::create([
            'reference' => 'TS-2026-0001', 'type' => 'report', 'subject_line' => 'A report',
            'reporter_subject_id' => $subject->id, 'status' => SafetyCase::STATUS_CLOSED,
        ]);

        $this->wikiPost('/api/wiki/v1/cases/TS-2026-0001/comments', [
            'central_id' => 42,
            'username' => 'Halcyon Reed',
            'body' => 'It happened again yesterday.',
        ])->assertCreated();

        $case->refresh();

        $this->assertSame('It happened again yesterday.', $case->comments()->first()->body);
        $this->assertSame('public', $case->comments()->first()->visibility);

        $this->assertSame(SafetyCase::STATUS_IN_REVIEW, $case->status);
    }

    #[Test]
    public function a_suspended_account_is_told_it_may_appeal(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $staff = User::create([
            'mw_central_id' => 1, 'username' => 'Admin', 'flags' => ['ts', 'admin'],
        ]);

        app(SanctionService::class)->issue($subject, [
            'type' => Sanction::TYPE_LOCK,
            'reason' => 'Repeated threats after a final warning.',
        ], $staff, $this->file($staff));

        $this->wikiGet('/api/wiki/v1/accounts/Halcyon%20Reed/login-status')
            ->assertOk()
            ->assertJsonPath('banned', true)
            ->assertJsonPath('appealable', true)
            ->assertJsonPath('appeal_pending', false)
            ->assertJsonPath('reason', 'Repeated threats after a final warning.');
    }

    #[Test]
    public function an_account_in_good_standing_is_not_told_it_is_banned(): void
    {
        Subject::forUsername('Halcyon Reed', 42);

        $this->wikiGet('/api/wiki/v1/accounts/42/login-status')
            ->assertOk()
            ->assertExactJson(['banned' => false]);
    }

    #[Test]
    public function an_unknown_account_gets_good_standing_rather_than_an_error(): void
    {
        $this->wikiGet('/api/wiki/v1/accounts/Nobody%20At%20All/standing')
            ->assertOk()
            ->assertJsonPath('standing', 'good')
            ->assertJsonPath('actions', []);
    }

    #[Test]
    public function a_second_appeal_against_the_same_action_is_not_a_second_case(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $staff = User::create(['mw_central_id' => 1, 'username' => 'Admin', 'flags' => ['ts', 'admin']]);

        $sanction = app(SanctionService::class)->issue($subject, [
            'type' => Sanction::TYPE_LOCK,
            'reason' => 'Threats.',
        ], $staff, $this->file($staff));

        $first = $this->wikiPost('/api/wiki/v1/appeals', [
            'username' => 'Halcyon Reed',
            'email' => 'halcyon@example.org',
            'sanction_reference' => $sanction->reference,
            'body' => 'It was not me.',
        ])->assertCreated();

        $second = $this->wikiPost('/api/wiki/v1/appeals', [
            'username' => 'Halcyon Reed',
            'sanction_reference' => $sanction->reference,
            'body' => 'Please, it was not me.',
        ])->assertOk();

        $this->assertTrue($second->json('duplicate'));
        $this->assertSame($first->json('reference'), $second->json('reference'));
        $this->assertSame(1, SafetyCase::query()->where('type', 'appeal')->count());
    }

    #[Test]
    public function an_appeal_records_an_address_to_reply_to(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);

        $this->wikiPost('/api/wiki/v1/appeals', [
            'username' => 'Halcyon Reed',
            'email' => 'halcyon@example.org',
            'body' => 'I would like this looked at again.',
        ])->assertCreated();

        $this->assertSame('halcyon@example.org', $subject->fresh()->email);
    }

    #[Test]
    public function an_appeal_does_not_replace_an_address_already_on_file(): void
    {
        $subject = Subject::forUsername('Halcyon Reed', 42);
        $subject->forceFill(['email' => 'halcyon@example.org'])->save();

        $this->wikiPost('/api/wiki/v1/appeals', [
            'username' => 'Halcyon Reed',
            'email' => 'attacker@example.net',
            'body' => 'Let me back in.',
        ])->assertCreated();

        $this->assertSame('halcyon@example.org', $subject->fresh()->email);
    }
}
