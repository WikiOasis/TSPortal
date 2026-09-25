<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Mail\SanctionIssuedMail;
use App\Models\CaseComment;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use App\Services\MediaWiki\WikiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SanctionService
{
    public function __construct(
        private readonly WikiSync $sync,
        private readonly CaseService $cases,
        private readonly SubjectResolver $subjects,
    ) {}

    /**
     * @param  array{type: string, scope?: ?string, wikis?: ?list<string>, reason: string,
     *               internal_reason?: ?string, reason_category?: ?string,
     *               expires_at?: ?string, appealable?: bool,
     *               pages?: ?list<array{wiki: string, title: string}>, prompted_by_id?: ?int}  $input
     */
    public function issue(
        ?Subject $subject,
        array $input,
        User $issuer,
        Investigation $investigation,
        ?SafetyCase $case = null,
    ): Sanction {
        $type = $input['type'];
        if (! in_array($type, Sanction::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown sanction type '{$type}'.");
        }

        $wikis = array_values(array_filter((array) ($input['wikis'] ?? [])));
        $pages = null;

        if (in_array($type, Sanction::PAGE_TARGETED, true)) {
            $pages = Pages::normalise((array) ($input['pages'] ?? []));

            if ($pages === []) {
                throw new \InvalidArgumentException('Deleting pages needs at least one page to delete.');
            }

            if (count($pages) > Pages::MAX_PER_ACTION) {
                throw new \InvalidArgumentException(sprintf(
                    'That is %d pages. Delete at most %d in one go.',
                    count($pages),
                    Pages::MAX_PER_ACTION,
                ));
            }

            $wikis = array_map('strval', array_keys(Pages::byWiki($pages)));
        } elseif (isset($input['pages'])) {
            $pages = Pages::normalise((array) $input['pages']) ?: null;
        }

        if (in_array($type, Sanction::WIKI_TARGETED, true)) {
            if ($wikis === []) {
                throw new \InvalidArgumentException('Deleting a wiki needs a wiki to delete.');
            }
        } elseif ($subject === null) {
            throw new \InvalidArgumentException("A '{$type}' has to be against an account.");
        }

        if ($type === Sanction::TYPE_BLOCK && $wikis === []) {
            throw new \InvalidArgumentException('A block has to name at least one wiki.');
        }

        $subject = $this->subjects->resolve($subject);

        if ($subject !== null) {
            $investigation->subjects()->syncWithoutDetaching([$subject->id => ['role' => 'subject']]);
        }

        if ($type === Sanction::TYPE_OTHER && trim((string) ($input['label'] ?? '')) === '') {
            throw new \InvalidArgumentException('A logged action needs a description of what was done.');
        }

        $target = $subject?->username ?? ($pages !== null && $type === Sanction::TYPE_PAGE_DELETION
            ? Pages::describe($pages, 120)
            : implode(', ', $wikis));

        $sanction = DB::transaction(function () use ($subject, $input, $issuer, $case, $investigation, $type, $wikis, $pages, $target) {
            $reference = Sanction::nextReference(sprintf(
                '%s: %s',
                Sanction::LABELS[$type] ?? $type,
                $target,
            ));

            $sanction = Sanction::create([
                'reference' => $reference,
                'subject_id' => $subject?->id,
                'case_id' => $case?->id,
                'investigation_id' => $investigation->id,
                'prompted_by_id' => $input['prompted_by_id'] ?? null,
                'type' => $type,
                'label' => $type === Sanction::TYPE_OTHER && ! empty($input['label'])
                    ? $input['label']
                    : (Sanction::LABELS[$type] ?? $type),
                'scope' => $input['scope'] ?? null,
                'wikis' => $wikis !== [] ? $wikis : null,
                'pages' => $pages,
                'reason' => $input['reason'],
                'internal_reason' => $input['internal_reason'] ?? null,

                'reason_category' => $this->reasonCategory($input),

                'issued_at' => now(),
                'expires_at' => $input['expires_at'] ?? null,
                'active' => true,
                'appealable' => $input['appealable'] ?? true,
                'issued_by' => $issuer->id,
            ]);

            References::attach($reference, $sanction, sprintf(
                '%s: %s',
                Sanction::LABELS[$type] ?? $type,
                $target,
            ));

            $subject?->refreshStanding();

            return $sanction;
        });

        Audit::log('sanction.issued', $sanction, [
            'subject' => $subject?->username,
            'wikis' => $wikis,
            'pages' => $pages !== null ? count($pages) : null,
            'prompted_by' => isset($input['prompted_by_id']) ? Sanction::query()->whereKey($input['prompted_by_id'])->value('reference') : null,
            'type' => $type,
            'reason_category' => $sanction->reason_category,
            'expires' => $input['expires_at'] ?? null,
            'case' => $case?->reference,
            'investigation' => $investigation->reference,
        ]);

        if ($case !== null) {
            $this->cases->comment(
                $case,
                sprintf('Action taken: %s (%s).', $sanction->label, $sanction->reference),
                CaseComment::VISIBILITY_PUBLIC,
                $issuer,
            );

            $this->cases->setStatus($case, SafetyCase::STATUS_ACTION_TAKEN, $issuer);
        }

        $this->sync->pushSanction($sanction);
        $this->enforce($sanction);
        $this->emailSubject($sanction);

        return $sanction;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function reasonCategory(array $input): ?string
    {
        $given = $input['reason_category'] ?? null;

        if (! is_string($given) || trim($given) === '') {
            return null;
        }

        $given = strtolower(trim($given));

        return array_key_exists($given, (array) config('categories.action_reasons', []))
            ? $given
            : null;
    }

    public function lift(Sanction $sanction, User $actor, string $reason): Sanction
    {
        DB::transaction(function () use ($sanction, $actor, $reason) {
            $sanction->forceFill([
                'active' => false,
                'lifted_by' => $actor->id,
                'lifted_at' => now(),
                'lift_reason' => $reason,
            ])->save();

            $sanction->subject?->refreshStanding();
        });

        Audit::log('sanction.lifted', $sanction, ['reason' => $reason]);

        $this->sync->pushSanction($sanction);

        if (in_array($sanction->type, [Sanction::TYPE_LOCK, Sanction::TYPE_BLOCK, Sanction::TYPE_WIKI_DELETION, Sanction::TYPE_PAGE_DELETION], true)) {
            $this->enforce($sanction, unlock: true);
        }

        return $sanction;
    }

    public function acknowledge(Sanction $sanction, User $actor, ?string $note = null): Sanction
    {
        if (! in_array($sanction->push_state, Sanction::NEEDS_A_PERSON, true)) {
            throw new \InvalidArgumentException(
                "This action is '{$sanction->push_state}', which is not something to mark as done by hand."
            );
        }

        $was = $sanction->push_state;

        $sanction->forceFill([
            'push_state' => Sanction::PUSH_ACKNOWLEDGED,
            'acknowledged_by' => $actor->id,
            'acknowledged_at' => now(),
            'acknowledgement_note' => $note,
        ])->save();

        Audit::log('sanction.acknowledged', $sanction, ['was' => $was, 'note' => $note]);

        return $sanction;
    }

    /**
     * @return int
     */
    public function expireDue(): int
    {
        $due = Sanction::query()
            ->where('active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('subject')
            ->get();

        foreach ($due as $sanction) {
            $sanction->forceFill(['active' => false])->save();
            $sanction->subject->refreshStanding();

            Audit::log('sanction.expired', $sanction, [], actorLabel: 'scheduler');

            $this->sync->pushSanction($sanction);
            $this->sync->pushStanding($sanction->subject);

            if ($sanction->type === Sanction::TYPE_LOCK) {
                $this->enforce($sanction, unlock: true);
            }
        }

        return $due->count();
    }

    private function enforce(Sanction $sanction, bool $unlock = false): void
    {
        if ($sanction->isRecordOnly()) {
            $sanction->forceFill([
                'push_state' => Sanction::PUSH_RECORDED,
                'pushed_at' => now(),
                'push_error' => null,
            ])->save();

            return;
        }

        $action = $unlock ? $this->undoAction($sanction) : $sanction->wikiAction();
        $client = WikiClient::make();

        if (! $client->enabled()) {
            $sanction->forceFill([
                'push_state' => Sanction::PUSH_MANUAL,
                'push_error' => 'Pushing to the wiki is switched off in this portal.',
            ])->save();

            return;
        }

        if (! in_array($action, (array) config('mediawiki.supported_actions', []), true)) {
            $sanction->forceFill([
                'push_state' => Sanction::PUSH_MANUAL,
                'push_error' => "The WikiOasisSafety extension cannot carry out '{$action}' yet — someone needs to do this by hand.",
            ])->save();

            return;
        }

        $wantsCentralLock = in_array($action, ['lock', 'unlock'], true)
            && (bool) config('mediawiki.centralauth_lock');

        try {
            $result = $client->enforce($action, [
                'reference' => $sanction->reference,
                'centralid' => $sanction->subject?->mw_central_id,
                'username' => $sanction->subject?->wikiName(),
                'wikis' => $sanction->wikis !== null ? implode('|', $sanction->wikis) : null,
                'reason' => $sanction->reason,
                'expiry' => $sanction->expires_at?->toIso8601String() ?? 'never',
                'centralauth' => $wantsCentralLock ? 1 : 0,
                'pages' => $sanction->pages !== null && $sanction->isPageTargeted()
                    ? json_encode(Pages::byWiki($sanction->pages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
            ]);

            $partial = $wantsCentralLock && ! $this->centralLockConfirmed($action, $result);

            $sanction->forceFill([
                'push_state' => $partial ? Sanction::PUSH_PARTIAL : Sanction::PUSH_PUSHED,
                'pushed_at' => now(),
                'push_error' => $partial ? $this->centralLockProblem($result) : null,
                'push_result' => $result,
            ])->save();

            if (in_array($action, Sanction::FANNED_OUT, true)) {
                $this->awaitWikis($sanction, $result);
            }
        } catch (Throwable $e) {
            Log::error('Could not enforce a sanction on the wiki', [
                'sanction' => $sanction->reference,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            $sanction->forceFill([
                'push_state' => Sanction::PUSH_FAILED,
                'push_error' => $e->getMessage(),
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function awaitWikis(Sanction $sanction, array $result): void
    {
        $pending = array_values((array) ($result['wikis'] ?? []));
        $refused = (array) ($result['refused'] ?? []);

        $sanction->forceFill([
            'push_result' => array_merge($result, [
                'pending' => $pending,
                'finished' => [],
                'failures' => $refused,
            ]),
        ])->save();

        if ($pending === []) {
            $this->settle($sanction);

            return;
        }

        $sanction->forceFill([
            'push_state' => Sanction::PUSH_QUEUED,
            'push_error' => null,
        ])->save();
    }

    public function progress(Sanction $sanction, string $wiki, bool $ok, ?string $error = null): Sanction
    {
        $result = $sanction->push_result ?? [];

        $pending = array_values(array_diff((array) ($result['pending'] ?? []), [$wiki]));
        $finished = array_values(array_unique(array_merge((array) ($result['finished'] ?? []), [$wiki])));
        $failures = (array) ($result['failures'] ?? []);

        if (! $ok) {
            $failures[$wiki] = $error ?? 'no reason given';
        }

        $sanction->forceFill([
            'push_result' => array_merge($result, [
                'pending' => $pending,
                'finished' => $finished,
                'failures' => $failures,
            ]),
        ])->save();

        Audit::log('sanction.wiki-reported', $sanction, [
            'wiki' => $wiki,
            'ok' => $ok,
            'outstanding' => count($pending),
        ]);

        if ($pending === []) {
            $this->settle($sanction);
        }

        return $sanction->refresh();
    }

    private function settle(Sanction $sanction): void
    {
        $result = $sanction->push_result ?? [];
        $failures = (array) ($result['failures'] ?? []);
        $finished = (array) ($result['finished'] ?? []);

        if ($failures === []) {
            $sanction->forceFill([
                'push_state' => Sanction::PUSH_PUSHED,
                'pushed_at' => now(),
                'push_error' => null,
            ])->save();

            return;
        }

        $sanction->forceFill([
            'push_state' => $finished === [] ? Sanction::PUSH_FAILED : Sanction::PUSH_PARTIAL,
            'push_error' => sprintf(
                '%s on %s. %s',
                $finished === [] ? 'Could not be carried out' : 'Not carried out',
                implode(', ', array_keys($failures)),
                $finished === []
                    ? 'The action is recorded here and has not taken effect anywhere.'
                    : 'It is in force on '.implode(', ', $finished).' and nowhere else.',
            ),
        ])->save();
    }

    private function undoAction(Sanction $sanction): string
    {
        return match ($sanction->type) {
            Sanction::TYPE_BLOCK => 'unblock',
            Sanction::TYPE_WIKI_DELETION => 'undelete-wiki',
            Sanction::TYPE_PAGE_DELETION => 'undelete-page',
            default => 'unlock',
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function centralLockConfirmed(string $action, array $result): bool
    {
        $expected = $action === 'unlock' ? 'unlocked' : 'locked';

        return ($result['centralauth'] ?? null) === $expected;
    }

    /** @param array<string, mixed> $result */
    private function centralLockProblem(array $result): string
    {
        $state = (string) ($result['centralauth'] ?? 'not reported');
        $detail = isset($result['centralauth_error']) ? ' — '.(string) $result['centralauth_error'] : '';

        return sprintf(
            'The wiki recorded this but did not confirm the CentralAuth lock (%s)%s. '
            .'Until somebody locks the account centrally it can still sign in through the API.',
            $state,
            $detail,
        );
    }

    private function emailSubject(Sanction $sanction): void
    {
        $to = $sanction->subject?->email;
        if ($to === null || $to === '') {
            return;
        }

        Mail::to($to)->queue(new SanctionIssuedMail($sanction, $to));
    }
}
