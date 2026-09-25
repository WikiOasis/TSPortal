<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\InvestigationPage;
use App\Models\OutboundEvent;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use App\Models\Wiki;
use App\Services\MediaWiki\WikiClient;
use App\Services\Safety\CaseService;
use App\Services\Safety\InvestigationPages;
use App\Services\Safety\InvestigationService;
use App\Services\Safety\PageDeletions;
use App\Services\Safety\Pages;
use App\Services\Safety\SanctionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $flags = ['ts']): User
    {
        return User::query()->firstOrCreate(
            ['mw_central_id' => 1],
            ['username' => 'Staffer', 'flags' => $flags],
        );
    }

    private function report(string $page, string $author, string $wiki = 'examplewiki', bool $automated = false): SafetyCase
    {
        return app(CaseService::class)->createFromSubmission([
            'type' => 'report',
            'flow' => $automated ? 'jev' : 'report',
            'wiki' => $wiki,
            'anonymous' => $automated,
            'automated' => $automated,
            'reporter' => $automated ? null : ['username' => 'Careful Reader', 'central_id' => 500],
            'answers' => ['page' => [$page], 'user' => [$author], 'details' => 'It is bad.'],
            'roles' => ['pages' => 'page', 'users' => 'user', 'details' => 'details'],
        ]);
    }

    private function file(): Investigation
    {
        return app(InvestigationService::class)->open(['title' => 'Spam wave'], $this->staff());
    }

    /**
     * @param  array<string, array{creator: string, editors: list<string>}>  $history
     */
    private function wiki(array $history = []): void
    {
        config()->set('mediawiki.s2s.push_enabled', true);
        config()->set('mediawiki.supported_actions', WikiClient::TASKS);

        Http::fake(function (Request $request) use ($history) {
            parse_str($request->body(), $params);

            if (($params['action'] ?? '') !== 'wikioasissafetypageinfo') {
                return Http::response(['wikioasissafetyenforce' => ['ok' => true, 'wikis' => ['examplewiki']]]);
            }

            $answers = [];
            foreach ((array) json_decode((string) $params['pages'], true) as $wiki => $titles) {
                foreach ($titles as $title) {
                    $known = $history[$title] ?? null;
                    $answers[] = $known === null
                        ? ['wiki' => $wiki, 'title' => $title, 'ok' => true, 'exists' => false, 'deleted' => false, 'revisions' => 0, 'creator' => null, 'editors' => []]
                        : [
                            'wiki' => $wiki,
                            'title' => $title,
                            'ok' => true,
                            'exists' => true,
                            'page_id' => 10,
                            'revisions' => count($known['editors']),
                            'creator' => $known['creator'],
                            'editors' => array_map(fn (string $name) => [
                                'username' => $name,
                                'registered' => true,
                                'edits' => 1,
                                'creator' => $name === $known['creator'],
                            ], $known['editors']),
                        ];
                }
            }

            return Http::response(['wikioasissafetypageinfo' => ['pages' => $answers]]);
        });
    }

    #[Test]
    public function a_report_remembers_which_pages_it_is_about(): void
    {
        $case = $this->report('Bad_page', 'Vandal One');

        $this->assertSame([['wiki' => 'examplewiki', 'title' => 'Bad page']], $case->pages);
    }

    #[Test]
    public function a_pasted_list_is_read_into_pages(): void
    {
        Wiki::query()->create(['dbname' => 'otherwiki', 'url' => 'https://other.wikioasis.org']);

        $read = Pages::inText(
            "* [[Spam page]]\nTalk:Foo_bar\nother:thing\notherwiki:Main_Page\nhttps://other.wikioasis.org/wiki/Linked_page\n\n",
            'examplewiki',
        );

        $this->assertSame([
            ['wiki' => 'examplewiki', 'title' => 'Spam page'],
            ['wiki' => 'examplewiki', 'title' => 'Talk:Foo bar'],
            ['wiki' => 'examplewiki', 'title' => 'other:thing'],
            ['wiki' => 'otherwiki', 'title' => 'Main Page'],
            ['wiki' => 'otherwiki', 'title' => 'Linked page'],
        ], $read['pages']);

        $this->assertCount(1, Pages::inText('No wiki named', null)['skipped']);
    }

    #[Test]
    public function attaching_a_report_puts_its_pages_on_the_file_and_looks_up_their_editors(): void
    {
        $this->wiki(['Bad page' => ['creator' => 'Vandal One', 'editors' => ['Vandal One', 'Fixer']]]);

        $case = $this->report('Bad page', 'Vandal One');
        $file = app(InvestigationService::class)->open(['title' => 'From a report'], $this->staff(), $case);

        $page = $file->pages()->sole();
        $this->assertSame('Bad page', $page->title);
        $this->assertSame($case->id, $page->case_id);
        $this->assertTrue($page->exists);
        $this->assertSame('Vandal One', $page->creator);
        $this->assertSame(['Vandal One', 'Fixer'], array_column($page->editors, 'username'));
    }

    #[Test]
    public function pages_can_be_added_in_bulk(): void
    {
        $this->wiki();
        $file = $this->file();

        $this->actingAs($this->staff())
            ->postJson("/api/portal/investigations/{$file->id}/pages", [
                'text' => "First\nSecond\nFirst\notherwiki:Third",
                'wiki' => 'examplewiki',
            ])
            ->assertOk()
            ->assertJsonPath('added', 3)
            ->assertJsonCount(3, 'data.pages');

        $this->actingAs($this->staff())
            ->postJson("/api/portal/investigations/{$file->id}/pages", ['text' => 'First', 'wiki' => 'examplewiki'])
            ->assertOk()
            ->assertJsonPath('added', 0)
            ->assertJsonPath('skipped.0.why', 'Already on the file.');
    }

    #[Test]
    public function an_old_extension_is_explained(): void
    {
        config()->set('mediawiki.s2s.push_enabled', true);
        Http::fake(['*' => Http::response(['error' => [
            'code' => 'badvalue',
            'info' => 'Unrecognized value for parameter "action": wikioasissafetypageinfo.',
        ]])]);

        $file = $this->file();
        app(InvestigationPages::class)->add($file, [['wiki' => 'examplewiki', 'title' => 'Page']], $this->staff());

        $this->assertStringContainsString('older than 1.4.0', (string) $file->pages()->sole()->info_error);
    }

    #[Test]
    public function deleting_pages_warns_each_editor_about_their_own_pages_only(): void
    {
        $this->wiki([
            'First page' => ['creator' => 'Vandal One', 'editors' => ['Vandal One']],
            'Second page' => ['creator' => 'Vandal Two', 'editors' => ['Vandal Two']],
        ]);

        $one = $this->report('First page', 'Vandal One', automated: true);
        $two = $this->report('Second page', 'Vandal Two', automated: true);

        $file = app(InvestigationService::class)->openForCases(
            SafetyCase::query()->whereKey([$one->id, $two->id])->get(),
            $this->staff(),
            InvestigationService::MODE_ONE,
        )['opened'][0];

        $first = $file->pages()->where('title', 'First page')->sole();
        $second = $file->pages()->where('title', 'Second page')->sole();

        $outcome = app(PageDeletions::class)->run($file, $this->staff(), [
            'page_ids' => [$first->id, $second->id],
            'reason' => 'Spam.',
            'notice_type' => Sanction::TYPE_WARNING,
            'notify' => [
                ['username' => 'Vandal One', 'page_ids' => [$first->id]],
                ['username' => 'Vandal Two', 'page_ids' => [$second->id]],
            ],
        ]);

        $deletion = $outcome['deletion'];
        $this->assertSame(Sanction::TYPE_PAGE_DELETION, $deletion->type);
        $this->assertSame(['examplewiki'], $deletion->wikis);
        $this->assertSame(Sanction::PUSH_QUEUED, $deletion->push_state);
        $this->assertSame(2, $outcome['done']);

        Http::assertSent(fn ($request) => str_contains($request->body(), 'task=delete-page')
            && str_contains(urldecode($request->body()), '"examplewiki":["First page","Second page"]'));

        $toOne = Sanction::query()->where('subject_id', Subject::forUsername('Vandal One')->id)->sole();
        $this->assertSame($deletion->id, $toOne->prompted_by_id);
        $this->assertSame([['wiki' => 'examplewiki', 'title' => 'First page']], $toOne->pages);
        $this->assertStringNotContainsString('Second page', $toOne->reason);
        $this->assertSame('examplewiki: First page', $toOne->whereItApplies());

        $this->assertTrue(OutboundEvent::query()
            ->where('event', OutboundEvent::SANCTION_UPSERT)
            ->get()
            ->contains(fn (OutboundEvent $e) => $e->payload['reference'] === $toOne->reference && $e->payload['notify'] === true));

        foreach ([$one, $two] as $case) {
            $this->assertSame(SafetyCase::STATUS_ACTION_TAKEN, $case->refresh()->status);
        }
    }

    #[Test]
    public function only_pages_on_the_file_can_be_deleted_from_it(): void
    {
        $this->wiki();

        $mine = $this->file();
        $theirs = $this->file();
        app(InvestigationPages::class)->add($theirs, [['wiki' => 'examplewiki', 'title' => 'Elsewhere']], $this->staff());

        $this->expectException(\InvalidArgumentException::class);

        app(PageDeletions::class)->run($mine, $this->staff(), [
            'page_ids' => [$theirs->pages()->sole()->id],
            'reason' => 'Spam.',
        ]);
    }

    #[Test]
    public function a_page_already_deleted_under_the_file_is_not_deleted_twice(): void
    {
        $this->wiki();

        $file = $this->file();
        app(InvestigationPages::class)->add($file, [
            ['wiki' => 'examplewiki', 'title' => 'One'],
            ['wiki' => 'examplewiki', 'title' => 'Two'],
        ], $this->staff());
        [$one, $two] = $file->pages()->orderBy('id')->get()->all();

        app(PageDeletions::class)->run($file, $this->staff(), ['page_ids' => [$one->id], 'reason' => 'Spam.']);
        $outcome = app(PageDeletions::class)->run($file, $this->staff(), ['page_ids' => [$one->id, $two->id], 'reason' => 'Spam.']);

        $this->assertSame([['wiki' => 'examplewiki', 'title' => 'Two']], $outcome['deletion']->pages);
        $this->assertCount(1, $outcome['skipped']);
    }

    #[Test]
    public function lifting_a_page_deletion_undeletes_the_pages_and_the_wiki_reports_per_wiki(): void
    {
        $this->wiki();

        $file = $this->file();
        app(InvestigationPages::class)->add($file, [['wiki' => 'examplewiki', 'title' => 'One']], $this->staff());

        $deletion = app(PageDeletions::class)->run($file, $this->staff(), [
            'page_ids' => [$file->pages()->sole()->id],
            'reason' => 'Spam.',
        ])['deletion'];

        app(SanctionService::class)->progress($deletion, 'examplewiki', true);
        $this->assertSame(Sanction::PUSH_PUSHED, $deletion->refresh()->push_state);

        app(SanctionService::class)->lift($deletion, $this->staff(), 'It was fine after all.');

        Http::assertSent(fn ($request) => str_contains($request->body(), 'task=undelete-page'));
    }

    #[Test]
    public function the_file_lists_its_pages_with_editors_and_deletion_state(): void
    {
        $this->wiki(['Bad page' => ['creator' => 'Vandal One', 'editors' => ['Vandal One', 'Fixer']]]);

        $case = $this->report('Bad page', 'Vandal One');
        $file = app(InvestigationService::class)->open(['title' => 'x'], $this->staff(), $case);
        $page = $file->pages()->sole();

        $this->actingAs($this->staff())
            ->postJson("/api/portal/investigations/{$file->id}/page-deletions", [
                'page_ids' => [$page->id],
                'reason' => 'Spam.',
                'notify' => [['username' => 'Vandal One', 'page_ids' => [$page->id]]],
            ])
            ->assertCreated()
            ->assertJsonPath('pages', 1)
            ->assertJsonPath('done', 1);

        $this->actingAs($this->staff())
            ->getJson("/api/portal/investigations/{$file->id}")
            ->assertOk()
            ->assertJsonPath('data.pages.0.title', 'Bad page')
            ->assertJsonPath('data.pages.0.creator', 'Vandal One')
            ->assertJsonPath('data.pages.0.editors.0.username', 'Vandal One')
            ->assertJsonPath('data.pages.0.editors.0.reported', true)
            ->assertJsonPath('data.pages.0.editors.1.reported', false)
            ->assertJsonPath('data.pages.0.cases.0.id', $case->id)
            ->assertJsonPath('data.pages.0.deleted.push_state', Sanction::PUSH_QUEUED);
    }

    #[Test]
    public function a_page_can_be_taken_off_the_file(): void
    {
        $this->wiki();

        $file = $this->file();
        app(InvestigationPages::class)->add($file, [['wiki' => 'examplewiki', 'title' => 'One']], $this->staff());
        $page = $file->pages()->sole();

        $this->actingAs($this->staff())
            ->deleteJson("/api/portal/investigations/{$file->id}/pages/{$page->id}")
            ->assertOk();

        $this->assertSame(0, InvestigationPage::query()->count());
    }

    #[Test]
    public function there_is_no_way_to_delete_pages_outside_a_file(): void
    {
        $file = $this->file();

        $this->actingAs($this->staff())
            ->postJson('/api/portal/page-deletions', ['reason' => 'Spam.'])
            ->assertNotFound();

        $this->actingAs($this->staff())
            ->postJson('/api/portal/actions', [
                'type' => Sanction::TYPE_PAGE_DELETION,
                'reason' => 'Spam.',
                'investigation_reference' => $file->reference,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'use-page-deletions');
    }

    #[Test]
    public function several_reports_can_each_get_a_file_of_their_own(): void
    {
        $one = $this->report('First page', 'Vandal One');
        $two = $this->report('Second page', 'Vandal Two');
        $filed = $this->report('Third page', 'Vandal Three');
        app(InvestigationService::class)->open(['title' => 'Already'], $this->staff(), $filed);

        $this->actingAs($this->staff())
            ->postJson('/api/portal/investigations/from-cases', [
                'case_ids' => [$one->id, $two->id, $filed->id],
                'mode' => 'each',
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'opened')
            ->assertJsonPath('skipped.0.case', $filed->reference);

        $this->assertNotSame($one->refresh()->investigation_id, $two->refresh()->investigation_id);
        $this->assertSame('First page', Investigation::query()->find($one->investigation_id)->pages()->sole()->title);
    }

    #[Test]
    public function several_flags_can_share_one_file(): void
    {
        $one = $this->report('First page', 'Vandal One', automated: true);
        $two = $this->report('Second page', 'Vandal Two', automated: true);

        $this->actingAs($this->staff())
            ->postJson('/api/portal/investigations/from-cases', [
                'case_ids' => [$one->id, $two->id],
                'mode' => 'one',
                'title' => 'Automated flags',
            ])
            ->assertCreated()
            ->assertJsonCount(1, 'opened');

        $this->assertSame($one->refresh()->investigation_id, $two->refresh()->investigation_id);
        $this->assertSame(2, Investigation::query()->find($one->investigation_id)->pages()->count());
    }
}
