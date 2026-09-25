<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Jobs\FetchPageInfo;
use App\Models\Investigation;
use App\Models\InvestigationPage;
use App\Models\SafetyCase;
use App\Models\User;
use App\Services\MediaWiki\WikiClient;
use App\Services\MediaWiki\WikiProblem;
use Illuminate\Support\Collection;
use Throwable;

final class InvestigationPages
{
    public const MAX_ADD = 500;

    private const CHUNK = 50;

    /**
     * @param  list<array{wiki: string, title: string}>  $pages
     * @return array{added: list<InvestigationPage>, skipped: list<array{page: string, why: string}>}
     */
    public function add(
        Investigation $investigation,
        array $pages,
        ?User $actor,
        ?SafetyCase $case = null,
        ?string $note = null,
        bool $fetch = true,
    ): array {
        $added = [];
        $skipped = [];

        foreach (Pages::unique($pages) as $page) {
            $existing = $investigation->pages()
                ->where('wiki', $page['wiki'])
                ->where('title', $page['title'])
                ->first();

            if ($existing !== null) {
                if ($case !== null && $existing->case_id === null) {
                    $existing->forceFill(['case_id' => $case->id])->save();
                }

                if ($case === null) {
                    $skipped[] = ['page' => $page['wiki'].': '.$page['title'], 'why' => 'Already on the file.'];
                }

                continue;
            }

            $added[] = $investigation->pages()->create([
                'wiki' => $page['wiki'],
                'title' => $page['title'],
                'case_id' => $case?->id,
                'added_by' => $actor?->id,
                'note' => $note,
            ]);
        }

        if ($added !== [] && $actor !== null) {
            Audit::log('investigation.pages-added', $investigation, [
                'pages' => count($added),
                'wikis' => array_values(array_unique(array_map(fn (InvestigationPage $p) => $p->wiki, $added))),
                'case' => $case?->reference,
            ]);
        }

        if ($added !== [] && $fetch) {
            $this->fetch(collect($added));
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    public function fromCase(Investigation $investigation, SafetyCase $case): int
    {
        $pages = (array) ($case->pages ?? []);
        if ($pages === []) {
            return 0;
        }

        $added = $this->add($investigation, $pages, null, $case, fetch: false)['added'];

        if ($added !== []) {
            FetchPageInfo::dispatch($investigation->id)->afterCommit();
        }

        return count($added);
    }

    public function remove(Investigation $investigation, InvestigationPage $page): void
    {
        $page->delete();

        Audit::log('investigation.page-removed', $investigation, [
            'wiki' => $page->wiki,
            'title' => $page->title,
        ]);
    }

    /**
     * @param  Collection<int, InvestigationPage>  $pages
     * @return array{fetched: int, failed: int, error: ?string}
     */
    public function fetch(Collection $pages): array
    {
        $client = WikiClient::make();

        if (! $client->enabled()) {
            return [
                'fetched' => 0,
                'failed' => $pages->count(),
                'error' => 'Looking pages up on the wiki is switched off in this portal.',
            ];
        }

        $fetched = 0;
        $failed = 0;
        $error = null;

        foreach ($pages->chunk(self::CHUNK) as $chunk) {
            $byWiki = Pages::byWiki($chunk->map(fn (InvestigationPage $p) => $p->asPage())->values()->all());

            try {
                $answers = $client->pageInfo($byWiki);
            } catch (Throwable $e) {
                $error = $this->explain($e);
                $failed += $chunk->count();

                InvestigationPage::query()
                    ->whereKey($chunk->pluck('id')->all())
                    ->update(['info_error' => mb_substr($error, 0, 1000)]);

                continue;
            }

            $byKey = [];
            foreach ($answers as $answer) {
                $byKey[($answer['wiki'] ?? '').'|'.($answer['title'] ?? '')] = $answer;
            }

            foreach ($chunk as $page) {
                $answer = $byKey[$page->key()] ?? null;

                if ($answer === null || ! ($answer['ok'] ?? false)) {
                    $page->forceFill([
                        'info_error' => mb_substr((string) ($answer['error'] ?? 'The wiki did not say anything about this page.'), 0, 1000),
                    ])->save();
                    $failed++;

                    continue;
                }

                $page->forceFill([
                    'exists' => (bool) ($answer['exists'] ?? false),
                    'previously_deleted' => (bool) ($answer['deleted'] ?? false),
                    'page_id' => isset($answer['page_id']) ? (int) $answer['page_id'] : null,
                    'latest_revision' => isset($answer['latest_revision']) ? (int) $answer['latest_revision'] : null,
                    'revisions' => (int) ($answer['revisions'] ?? 0),
                    'creator' => isset($answer['creator']) ? mb_substr((string) $answer['creator'], 0, 255) : null,
                    'editors' => array_values(array_map(fn (array $e) => [
                        'username' => (string) ($e['username'] ?? ''),
                        'registered' => (bool) ($e['registered'] ?? false),
                        'edits' => (int) ($e['edits'] ?? 0),
                        'first' => $e['first'] ?? null,
                        'last' => $e['last'] ?? null,
                        'creator' => (bool) ($e['creator'] ?? false),
                    ], array_filter((array) ($answer['editors'] ?? []), 'is_array'))),
                    'info_fetched_at' => now(),
                    'info_error' => isset($answer['error']) ? mb_substr((string) $answer['error'], 0, 1000) : null,
                ])->save();

                $fetched++;
            }
        }

        return ['fetched' => $fetched, 'failed' => $failed, 'error' => $error];
    }

    public function fetchMissing(Investigation $investigation): array
    {
        return $this->fetch($investigation->pages()->whereNull('info_fetched_at')->get());
    }

    private function explain(Throwable $e): string
    {
        if ($e instanceof WikiProblem && str_contains($e->getMessage(), 'wikioasissafetypageinfo')) {
            return 'The wiki\'s WikiOasisSafety is older than 1.4.0, so it cannot look pages up yet.';
        }

        return $e->getMessage();
    }
}
