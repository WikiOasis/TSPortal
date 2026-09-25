<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\CaseComment;
use App\Models\Investigation;
use App\Models\InvestigationPage;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

final class PageDeletions
{
    public const MAX_NOTICES = 200;

    public function __construct(
        private readonly SanctionService $sanctions,
        private readonly CaseService $cases,
    ) {}

    /**
     * @param  array{page_ids: list<int>, reason: string, internal_reason?: ?string, reason_category?: ?string,
     *               notify?: list<array{subject_id?: ?int, username?: ?string, page_ids?: ?list<int>}>,
     *               notice_type?: ?string, notice_reason?: ?string}  $input
     * @return array{deletion: Sanction, cases: list<string>, notices: list<array<string, mixed>>,
     *               skipped: list<array{page: string, why: string}>, done: int, failed: int}
     */
    public function run(Investigation $investigation, User $actor, array $input): array
    {
        if (! $investigation->isLive()) {
            throw new InvalidArgumentException(sprintf(
                '%s is %s. Reopen it before deleting anything under it.',
                $investigation->reference,
                $investigation->status,
            ));
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('Say why the pages are being deleted. It goes in the wiki\'s deletion log.');
        }

        $noticeType = (string) ($input['notice_type'] ?? Sanction::TYPE_WARNING);
        if (! in_array($noticeType, Sanction::NOTICE_TYPES, true)) {
            throw new InvalidArgumentException('Editors can be given a formal warning or a note on file, nothing else.');
        }

        $notify = (array) ($input['notify'] ?? []);
        if (count($notify) > self::MAX_NOTICES) {
            throw new InvalidArgumentException(sprintf('Tell at most %d editors in one go.', self::MAX_NOTICES));
        }

        $ids = array_values(array_unique(array_map('intval', (array) ($input['page_ids'] ?? []))));
        if ($ids === []) {
            throw new InvalidArgumentException('Pick the pages on this file to delete.');
        }

        if (count($ids) > Pages::MAX_PER_ACTION) {
            throw new InvalidArgumentException(sprintf('Delete at most %d pages in one go.', Pages::MAX_PER_ACTION));
        }

        $picked = $investigation->pages()->whereKey($ids)->orderBy('id')->get();
        if ($picked->count() !== count($ids)) {
            throw new InvalidArgumentException('Only pages on this file can be deleted from it. Add them to the file first.');
        }

        [$pages, $skipped] = $this->stillUp($investigation, $picked);

        if ($pages->isEmpty()) {
            throw new InvalidArgumentException('Every page picked has already been deleted under this file.');
        }

        $keys = $pages->map(fn (InvestigationPage $p) => $p->key())->flip();

        $cases = $investigation->cases()->get()->filter(fn (SafetyCase $c) => $pages->contains('case_id', $c->id)
            || collect((array) ($c->pages ?? []))->contains(fn (array $p) => $keys->has(Pages::key($p))))
            ->values();

        $deletion = $this->sanctions->issue(null, [
            'type' => Sanction::TYPE_PAGE_DELETION,
            'pages' => $pages->map(fn (InvestigationPage $p) => $p->asPage())->all(),
            'reason' => $reason,
            'internal_reason' => $input['internal_reason'] ?? null,
            'reason_category' => $input['reason_category'] ?? null,
            'appealable' => false,
        ], $actor, $investigation);

        if ($cases->count() === 1) {
            $deletion->forceFill(['case_id' => $cases->first()->id])->save();
        }

        foreach ($cases as $case) {
            $this->cases->comment(
                $case,
                sprintf('Action taken: %s (%s).', $deletion->label, $deletion->reference),
                CaseComment::VISIBILITY_PUBLIC,
                $actor,
            );

            if ($case->isOpen()) {
                $this->cases->setStatus($case, SafetyCase::STATUS_ACTION_TAKEN, $actor);
            }
        }

        $notices = $this->notify($notify, $pages, $deletion, $investigation, $actor, $input, $noticeType, $reason);
        $done = count(array_filter($notices, fn (array $n) => $n['ok']));

        Audit::log('pages.deleted', $deletion, [
            'investigation' => $investigation->reference,
            'pages' => $pages->count(),
            'wikis' => $deletion->wikis,
            'cases' => $cases->pluck('reference')->all(),
            'notice_type' => $noticeType,
            'notified' => array_values(array_filter(array_column($notices, 'reference'))),
            'refused' => count($notices) - $done,
        ]);

        return [
            'deletion' => $deletion->refresh(),
            'cases' => $cases->pluck('reference')->all(),
            'notices' => $notices,
            'skipped' => $skipped,
            'done' => $done,
            'failed' => count($notices) - $done,
        ];
    }

    /**
     * @param  Collection<int, InvestigationPage>  $picked
     * @return array{0: Collection<int, InvestigationPage>, 1: list<array{page: string, why: string}>}
     */
    private function stillUp(Investigation $investigation, Collection $picked): array
    {
        $deleted = [];

        $investigation->sanctions()
            ->where('type', Sanction::TYPE_PAGE_DELETION)
            ->where('active', true)
            ->get()
            ->each(function (Sanction $s) use (&$deleted) {
                foreach ((array) ($s->pages ?? []) as $page) {
                    $deleted[Pages::key($page)] ??= $s->reference;
                }
            });

        $skipped = [];

        $up = $picked->filter(function (InvestigationPage $page) use ($deleted, &$skipped) {
            if (! isset($deleted[$page->key()])) {
                return true;
            }

            $skipped[] = [
                'page' => $page->wiki.': '.$page->title,
                'why' => sprintf('Already deleted under %s.', $deleted[$page->key()]),
            ];

            return false;
        })->values();

        return [$up, $skipped];
    }

    /**
     * @param  list<mixed>  $notify
     * @param  Collection<int, InvestigationPage>  $pages
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    private function notify(
        array $notify,
        Collection $pages,
        Sanction $deletion,
        Investigation $investigation,
        User $actor,
        array $input,
        string $noticeType,
        string $reason,
    ): array {
        $results = [];
        $seen = [];
        $told = trim((string) ($input['notice_reason'] ?? ''));

        foreach ($notify as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $subject = $this->subjectFor($entry);

            if ($subject === null) {
                $results[] = $this->refused(null, (string) ($entry['username'] ?? ''), 'Not an account the portal can find.');

                continue;
            }

            if (isset($seen[$subject->id])) {
                continue;
            }
            $seen[$subject->id] = true;

            if ($subject->isErased()) {
                $results[] = $this->refused($subject->id, $subject->username, 'This account has been erased, so it cannot be told anything.');

                continue;
            }

            $only = array_map('intval', (array) ($entry['page_ids'] ?? []));
            $theirs = $only === [] ? $pages : $pages->filter(fn (InvestigationPage $p) => in_array($p->id, $only, true));

            if ($theirs->isEmpty()) {
                $theirs = $pages;
            }

            $list = $theirs->map(fn (InvestigationPage $p) => $p->asPage())->values()->all();

            try {
                $notice = $this->sanctions->issue($subject, [
                    'type' => $noticeType,
                    'reason' => sprintf(
                        "%s\n\n%s deleted: %s.",
                        $told !== '' ? $told : $reason,
                        count($list) === 1 ? 'Page' : 'Pages',
                        Pages::describe($list, 3000),
                    ),
                    'internal_reason' => $input['internal_reason'] ?? null,
                    'reason_category' => $input['reason_category'] ?? null,
                    'scope' => Pages::describe($list),
                    'pages' => $list,
                    'prompted_by_id' => $deletion->id,
                    'appealable' => true,
                ], $actor, $investigation);
            } catch (Throwable $e) {
                $results[] = $this->refused($subject->id, $subject->username, $e->getMessage());

                continue;
            }

            $results[] = [
                'subject_id' => $subject->id,
                'username' => $subject->username,
                'ok' => true,
                'reference' => $notice->reference,
                'pages' => count($list),
                'state' => $notice->push_state,
                'message' => 'Told.',
            ];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function subjectFor(array $entry): ?Subject
    {
        if (! empty($entry['subject_id'])) {
            return Subject::query()->find((int) $entry['subject_id']);
        }

        $name = trim((string) ($entry['username'] ?? ''));
        $name = preg_replace('/^\s*(User|User talk)\s*:\s*/iu', '', $name) ?? $name;

        return $name === '' ? null : Subject::forUsername($name);
    }

    /**
     * @return array<string, mixed>
     */
    private function refused(?int $subjectId, ?string $username, string $why): array
    {
        return [
            'subject_id' => $subjectId,
            'username' => $username,
            'ok' => false,
            'reference' => null,
            'pages' => 0,
            'state' => null,
            'message' => $why,
        ];
    }
}
