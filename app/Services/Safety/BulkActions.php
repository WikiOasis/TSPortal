<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use InvalidArgumentException;
use Throwable;

final class BulkActions
{
    public const KIND_ACTION = 'action';

    public const KIND_ERASURE = 'erasure';

    public const KINDS = [self::KIND_ACTION, self::KIND_ERASURE];

    public function __construct(
        private readonly SanctionService $sanctions,
        private readonly DataRemovalService $removals,
    ) {}

    /**
     * @param  array{kind: string, subject_ids: list<int>, type?: ?string, label?: ?string,
     *               scope?: ?string, wikis?: ?list<string>, reason: string, internal_reason?: ?string,
     *               reason_category?: ?string, expires_at?: ?string, appealable?: bool,
     *               legal_basis?: ?string, hold?: bool, case_reference?: ?string}  $input
     * @return array{kind: string, done: int, failed: int, results: list<array<string, mixed>>}
     */
    public function run(Investigation $investigation, User $actor, array $input): array
    {
        $kind = $input['kind'] ?? self::KIND_ACTION;

        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("There is no bulk '{$kind}' to run.");
        }

        if (! $investigation->isLive()) {
            throw new InvalidArgumentException(sprintf(
                '%s is %s. Reopen it before working under it.',
                $investigation->reference,
                $investigation->status,
            ));
        }

        if ($kind === self::KIND_ACTION) {
            $type = $input['type'] ?? '';

            if (in_array($type, Sanction::WIKI_TARGETED, true)) {
                throw new InvalidArgumentException(
                    'Deleting a wiki is not something to do against a list of accounts. Take it one at a time.'
                );
            }

            if (! in_array($type, Sanction::TYPES, true)) {
                throw new InvalidArgumentException('Say which action to take against them.');
            }
        }

        if (trim((string) ($input['reason'] ?? '')) === '') {
            throw new InvalidArgumentException('Say why, in words the accounts can be given.');
        }

        $case = ! empty($input['case_reference'])
            ? SafetyCase::query()->where('reference', $input['case_reference'])->first()
            : null;

        $wanted = array_values(array_unique(array_map('intval', (array) ($input['subject_ids'] ?? []))));

        if ($wanted === []) {
            throw new InvalidArgumentException('Nothing was selected.');
        }

        $linked = $investigation->subjects()->whereKey($wanted)->get()->keyBy('id');

        $results = [];

        foreach ($wanted as $id) {
            $subject = $linked->get($id);

            if ($subject === null) {
                $results[] = $this->refused($id, null, 'Not named on this investigation, so nothing was done.');

                continue;
            }

            $results[] = $kind === self::KIND_ERASURE
                ? $this->eraseOne($subject, $investigation, $actor, $input)
                : $this->actOne($subject, $investigation, $actor, $input, $case);
        }

        $done = count(array_filter($results, fn (array $r) => $r['ok']));

        Audit::log('investigation.bulk-action', $investigation, [
            'kind' => $kind,
            'type' => $kind === self::KIND_ACTION ? ($input['type'] ?? null) : null,
            'legal_basis' => $kind === self::KIND_ERASURE ? ($input['legal_basis'] ?? null) : null,
            'accounts' => array_values(array_filter(array_column($results, 'username'))),
            'done' => $done,
            'failed' => count($results) - $done,
            'references' => array_values(array_filter(array_column($results, 'reference'))),
            'case' => $case?->reference,
        ]);

        return [
            'kind' => $kind,
            'done' => $done,
            'failed' => count($results) - $done,
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function actOne(
        Subject $subject,
        Investigation $investigation,
        User $actor,
        array $input,
        ?SafetyCase $case,
    ): array {
        if ($subject->isErased()) {
            return $this->refused(
                $subject->id,
                $subject->username,
                'This account has been erased, so nothing can be done against it.',
            );
        }

        try {
            $sanction = $this->sanctions->issue($subject, $input, $actor, $investigation, $case);
        } catch (Throwable $e) {
            return $this->refused($subject->id, $subject->username, $e->getMessage());
        }

        return [
            'subject_id' => $subject->id,
            'username' => $subject->username,
            'ok' => true,
            'reference' => $sanction->reference,
            'state' => $sanction->push_state,
            'needs_a_person' => in_array($sanction->push_state, Sanction::NEEDS_A_PERSON, true),
            'message' => in_array($sanction->push_state, Sanction::NEEDS_A_PERSON, true)
                ? ($sanction->push_error ?? 'Recorded here; somebody has to carry it out.')
                : 'Done.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function eraseOne(Subject $subject, Investigation $investigation, User $actor, array $input): array
    {
        if ($subject->isErased()) {
            return $this->refused($subject->id, $subject->username, 'Already erased.');
        }

        $outstanding = DataRemoval::query()
            ->where('subject_id', $subject->id)
            ->outstanding()
            ->first();

        if ($outstanding !== null) {
            return $this->refused($subject->id, $subject->username, sprintf(
                'Already being erased under %s (%s).',
                $outstanding->reference,
                $outstanding->label(),
            ));
        }

        try {
            $removal = $this->removals->request(
                $subject,
                [
                    'reason' => $input['reason'],
                    'legal_basis' => $input['legal_basis'] ?? null,
                    'wikis' => $input['wikis'] ?? null,
                ],
                $actor,
                null,
                $investigation,
                (bool) ($input['hold'] ?? false),
            );
        } catch (Throwable $e) {
            return $this->refused($subject->id, $subject->username, $e->getMessage());
        }

        $started = $removal->state !== DataRemoval::STATE_REQUESTED;

        return [
            'subject_id' => $subject->id,
            'username' => $subject->username,
            'ok' => true,
            'reference' => $removal->reference,
            'state' => $removal->state,
            'needs_a_person' => false,
            'message' => $removal->error ?? ($started
                ? sprintf('Started. It becomes %s.', $removal->target_username)
                : 'Written down and not started.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function refused(int $subjectId, ?string $username, string $why): array
    {
        return [
            'subject_id' => $subjectId,
            'username' => $username,
            'ok' => false,
            'reference' => null,
            'state' => null,
            'needs_a_person' => false,
            'message' => $why,
        ];
    }
}
