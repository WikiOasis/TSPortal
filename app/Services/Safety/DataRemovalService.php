<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\Attachment;
use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\User;
use App\Services\MediaWiki\WikiClient;
use App\Services\MediaWiki\WikiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class DataRemovalService
{
    private const MAX_WAITS = 12;

    /**
     * @param  array{reason: string, legal_basis?: ?string, wikis?: ?list<string>}  $input
     */
    public function request(
        Subject $subject,
        array $input,
        User $requester,
        ?SafetyCase $case = null,
        ?Investigation $investigation = null,
        bool $recordOnly = false,
    ): DataRemoval {
        if ($subject->username === '') {
            throw new RuntimeException('There is no account name to erase.');
        }

        $removal = DB::transaction(function () use ($subject, $input, $requester, $case, $investigation) {
            $reference = References::allocate('DataRemoval', 'Data removal: '.$subject->username);

            $removal = DataRemoval::create([
                'reference' => $reference,
                'subject_id' => $subject->id,
                'case_id' => $case?->id,
                'investigation_id' => $investigation?->id ?? $case?->investigation_id,
                'target_username' => DataRemoval::usernameFor(),
                'previous_username' => $subject->username,
                'state' => DataRemoval::STATE_REQUESTED,
                'legal_basis' => $input['legal_basis'] ?? null,
                'reason' => $input['reason'],
                'wikis' => $input['wikis'] ?? null,
                'requested_by' => $requester->id,
            ]);

            References::attach($reference, $removal, 'Data removal: '.$subject->username);

            return $removal;
        });

        Audit::log('data-removal.requested', $removal, [
            'reference' => $removal->reference,
            'account' => $subject->username,
            'becomes' => $removal->target_username,
            'basis' => $removal->legal_basis,
            'case' => $case?->reference,
            'immediate' => ! $recordOnly,
        ]);

        return $recordOnly ? $removal : $this->erase($removal, $requester);
    }

    public function erase(DataRemoval $removal, User $actor): DataRemoval
    {
        if ($removal->state !== DataRemoval::STATE_REQUESTED) {
            throw new RuntimeException("{$removal->reference} has already been started.");
        }

        $removal->forceFill([
            'state' => DataRemoval::STATE_APPROVED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ])->save();

        Audit::log('data-removal.started', $removal, [
            'reference' => $removal->reference,
            'account' => $removal->previous_username,
            'requested_by' => $removal->requester?->username,
        ]);

        return $removal->refresh();
    }

    public function refuse(DataRemoval $removal, User $actor, string $reason): DataRemoval
    {
        if ($removal->state !== DataRemoval::STATE_REQUESTED) {
            throw new RuntimeException("{$removal->reference} has already been started; it cannot be refused now.");
        }

        $removal->forceFill([
            'state' => DataRemoval::STATE_REFUSED,
            'refusal_reason' => $reason,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'completed_at' => now(),
        ])->save();

        Audit::log('data-removal.refused', $removal, ['reason' => $reason]);

        return $removal;
    }

    public function rename(DataRemoval $removal): DataRemoval
    {
        $client = $this->client();

        if ($client === null) {
            return $this->hold(
                $removal,
                'Erasure is switched off on this portal, or the wiki cannot be reached. '
                .$removal->reference.' is approved and will be sent when it is turned on.',
            );
        }

        try {
            $result = $client->enforce('rename', [
                'reference' => $removal->reference,
                'username' => $removal->previous_username,
                'centralid' => $removal->subject->mw_central_id,
                'newname' => $removal->target_username,
                'reason' => $removal->reference,
            ]);

            $removal->forceFill([
                'state' => DataRemoval::STATE_RENAMING,
                'result' => array_merge($removal->result ?? [], ['rename' => $result, 'waited' => 0]),
                'error' => null,
                'last_problem' => null,
                'next_attempt_at' => null,
            ])->save();

            Audit::log('data-removal.sent', $removal, ['stage' => 'rename', 'result' => $result]);
        } catch (Throwable $e) {
            $this->fail($removal, 'rename', $e);
        }

        return $removal->refresh();
    }

    /**
     * @param  callable(string, string): void|null  $note
     * @return array{checked: int, moved: int, waiting: int}
     */
    public function advanceDue(?string $reference = null, ?callable $note = null): array
    {
        $note ??= static fn (string $level, string $text) => null;

        if (! config('mediawiki.pii.enabled')) {
            $note('comment', 'Erasure is switched off on this portal (MW_PII_ENABLED). Nothing to do.');

            return ['checked' => 0, 'moved' => 0, 'waiting' => 0];
        }

        $pending = DataRemoval::query()
            ->due()
            ->when($reference, fn ($q, $ref) => $q->where('reference', $ref))
            ->with('subject')
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            $waiting = DataRemoval::query()->outstanding()->count();

            $note('line', $waiting === 0
                ? 'Nothing outstanding.'
                : sprintf('Nothing due. %d still waiting on the wiki.', $waiting));

            return ['checked' => 0, 'moved' => 0, 'waiting' => $waiting];
        }

        $moved = 0;

        foreach ($pending as $removal) {
            try {
                $before = $removal->state;
                $after = $this->advance($removal)->state;

                if ($before !== $after) {
                    $moved++;
                    $note('line', sprintf('%s: %s → %s', $removal->reference, $before, $after));

                    continue;
                }

                $removal->refresh();
                if ($removal->last_problem !== null) {
                    $note('comment', sprintf(
                        '%s: waiting — %s (next %s)',
                        $removal->reference,
                        $removal->last_problem,
                        $removal->next_attempt_at?->diffForHumans() ?? 'now',
                    ));
                }
            } catch (Throwable $e) {
                $note('error', sprintf('%s: %s', $removal->reference, $e->getMessage()));
            }
        }

        return [
            'checked' => $pending->count(),
            'moved' => $moved,
            'waiting' => DataRemoval::query()->outstanding()->count(),
        ];
    }

    public function advance(DataRemoval $removal): DataRemoval
    {
        if ($removal->isWaiting()) {
            return $removal;
        }

        return match ($removal->state) {
            DataRemoval::STATE_APPROVED => $this->rename($removal),

            DataRemoval::STATE_RENAMING => $this->checkRename($removal),

            DataRemoval::STATE_RENAMED => $this->scrub($removal),

            DataRemoval::STATE_FAILED => $this->retryFailed($removal),

            default => $removal,
        };
    }

    private function checkRename(DataRemoval $removal): DataRemoval
    {
        $client = $this->client();
        if ($client === null) {
            return $removal;
        }

        $timeout = (int) config('mediawiki.pii.rename_timeout_hours', 24);
        if ($removal->updated_at !== null && $removal->updated_at->addHours($timeout)->isPast()) {
            return $this->hold(
                $removal,
                sprintf(
                    'The rename to %s has been unfinished for over %d hours. Check the rename queue '
                    .'on the wiki, then use Try again.',
                    $removal->target_username,
                    $timeout,
                ),
            );
        }

        try {
            $status = $client->enforce('renamestatus', [
                'reference' => $removal->reference,
                'username' => $removal->previous_username,
                'newname' => $removal->target_username,
            ]);

            if (! ($status['complete'] ?? false)) {
                return $removal;
            }

            $removal->forceFill([
                'state' => DataRemoval::STATE_RENAMED,
                'renamed_at' => now(),
                'result' => array_merge($removal->result ?? [], ['renamestatus' => $status]),
            ])->save();

            $removal->subject->forceFill([
                'wiki_username' => $removal->target_username,
                'erased_at' => now(),
            ])->save();

            Audit::log('data-removal.renamed', $removal, ['became' => $removal->target_username]);

            $removal->refresh()->resetBackOff();

            return $this->scrub($removal->refresh());
        } catch (Throwable $e) {
            $this->fail($removal, 'renamestatus', $e);
        }

        return $removal->refresh();
    }

    private function retryFailed(DataRemoval $removal): DataRemoval
    {
        if ($removal->renamed_at === null) {
            $removal->forceFill(['state' => DataRemoval::STATE_APPROVED])->save();

            return $this->rename($removal);
        }

        $removal->forceFill(['state' => DataRemoval::STATE_RENAMED])->save();

        return $this->scrub($removal);
    }

    public function scrub(DataRemoval $removal): DataRemoval
    {
        if ($removal->state !== DataRemoval::STATE_RENAMED) {
            throw new RuntimeException(
                "{$removal->reference} is not ready to be scrubbed: it is {$removal->state}, and the "
                .'scrub works on the new name, so the rename has to have finished first.'
            );
        }

        $client = $this->client();
        if ($client === null) {
            return $this->hold($removal, 'The wiki cannot be reached, so the erasure has not started.');
        }

        try {
            $result = $client->enforce('removepii', [
                'reference' => $removal->reference,
                'username' => $removal->previous_username,
                'newname' => $removal->target_username,
                'wikis' => $removal->wikis === null ? null : implode('|', $removal->wikis),
            ]);

            $pending = array_values((array) ($result['wikis'] ?? []));

            $removal->forceFill([
                'state' => DataRemoval::STATE_SCRUBBING,
                'result' => array_merge($removal->result ?? [], [
                    'removepii' => $result,
                    'pending' => $pending,
                    'finished' => [],
                ]),
                'error' => null,
            ])->save();

            Audit::log('data-removal.sent', $removal, ['stage' => 'removepii', 'wikis' => $pending]);

            if ($pending === []) {
                return $this->complete($removal);
            }
        } catch (Throwable $e) {
            $this->fail($removal, 'removepii', $e);
        }

        return $removal->refresh();
    }

    public function progress(DataRemoval $removal, string $wiki, bool $ok, ?string $error = null): DataRemoval
    {
        $result = $removal->result ?? [];
        $pending = array_values(array_diff((array) ($result['pending'] ?? []), [$wiki]));
        $finished = array_values(array_unique(array_merge((array) ($result['finished'] ?? []), [$wiki])));

        $failures = (array) ($result['failures'] ?? []);
        if (! $ok) {
            $failures[$wiki] = $error ?? 'no reason given';
        }

        $removal->forceFill([
            'result' => array_merge($result, [
                'pending' => $pending,
                'finished' => $finished,
                'failures' => $failures,
            ]),
        ])->save();

        if ($pending !== []) {
            return $removal;
        }

        if ($failures !== []) {
            $removal->forceFill([
                'state' => DataRemoval::STATE_FAILED,
                'error' => 'Some wikis could not finish: '.implode(', ', array_keys($failures)),
                'completed_at' => now(),
            ])->save();

            Audit::log('data-removal.failed', $removal, ['failures' => $failures]);

            return $removal;
        }

        return $this->complete($removal);
    }

    private function complete(DataRemoval $removal): DataRemoval
    {
        $removal->forceFill([
            'state' => DataRemoval::STATE_DONE,
            'completed_at' => now(),
            'error' => null,
        ])->save();

        Audit::log('data-removal.completed', $removal, [
            'reference' => $removal->reference,

            'files_retained' => $this->filesStillHeld($removal),
            'retention_starts' => now()->toIso8601String(),
        ]);

        $case = $removal->safetyCase;
        if ($case !== null && $case->isOpen()) {
            app(CaseService::class)->setStatus(
                $case,
                SafetyCase::STATUS_ACTION_TAKEN,
                $removal->approver,
                'The data has been removed.',
            );
        }

        return $removal;
    }

    private function filesStillHeld(DataRemoval $removal): int
    {
        if ($removal->subject_id === null) {
            return 0;
        }

        return Attachment::query()
            ->whereNotNull('path')
            ->whereIn('case_id', SafetyCase::query()
                ->where('reporter_subject_id', $removal->subject_id)
                ->select('id'))
            ->count();
    }

    private function hold(DataRemoval $removal, string $why): DataRemoval
    {
        $removal->forceFill(['error' => $why])->save();

        Log::warning('A data removal is held up', ['reference' => $removal->reference, 'why' => $why]);

        return $removal;
    }

    private function fail(DataRemoval $removal, string $stage, Throwable $e): void
    {
        $retryable = ! $e instanceof WikiProblem || $e->isRetryable();

        if ($retryable && $removal->attemptsMade() < self::MAX_WAITS) {
            $removal->backOff(sprintf('%s: %s', $stage, $e->getMessage()));

            Log::info('A data removal is waiting on the wiki', [
                'reference' => $removal->reference,
                'stage' => $stage,
                'problem' => $e->getMessage(),
                'next' => $removal->next_attempt_at?->toIso8601String(),
            ]);

            return;
        }

        Log::error('A data removal stage failed', [
            'reference' => $removal->reference,
            'stage' => $stage,
            'error' => $e->getMessage(),
            'gave_up_waiting' => $retryable,
        ]);

        $removal->forceFill([
            'state' => DataRemoval::STATE_FAILED,
            'error' => $retryable
                ? sprintf(
                    '%s: gave up after waiting through %d attempts. Last problem: %s',
                    $stage,
                    $removal->attemptsMade(),
                    $e->getMessage(),
                )
                : sprintf('%s: %s', $stage, $e->getMessage()),
        ])->save();

        Audit::log('data-removal.failed', $removal, [
            'stage' => $stage,
            'error' => $e->getMessage(),
            'waited' => $removal->attemptsMade(),
        ]);
    }

    private function client(): ?WikiClient
    {
        if (! config('mediawiki.pii.enabled')) {
            return null;
        }

        $client = WikiClient::make();

        return $client->enabled() ? $client : null;
    }
}
