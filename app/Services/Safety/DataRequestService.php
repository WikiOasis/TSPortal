<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\CaseComment;
use App\Models\DataRemoval;
use App\Models\SafetyCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DataRequestService
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly DataRemovalService $removals,
    ) {}

    public function setKind(SafetyCase $case, string $kind): SafetyCase
    {
        $this->mustBeDataRequest($case);

        if (! in_array($kind, SafetyCase::DATA_KINDS, true)) {
            throw new RuntimeException("'{$kind}' is not a kind of data request.");
        }

        if ($case->data_kind === $kind) {
            return $case;
        }

        $from = $case->data_kind;
        $case->data_kind = $kind;
        $case->save();

        Audit::log('data-request.kind', $case, ['from' => $from, 'to' => $kind]);

        return $case;
    }

    /**
     * @return array{case: SafetyCase, removal: ?DataRemoval}
     */
    public function approve(SafetyCase $case, User $actor, string $note, bool $act = true): array
    {
        $this->mustBeDataRequest($case);
        $this->mustKnowKind($case);

        if ($case->data_decision !== null) {
            throw new RuntimeException(
                "{$case->reference} has already been {$case->data_decision}."
            );
        }

        DB::transaction(function () use ($case, $actor, $note) {
            $case->forceFill([
                'data_decision' => SafetyCase::DECISION_APPROVED,
                'data_decision_note' => $note,
                'data_decided_by' => $actor->id,
                'data_decided_at' => now(),
            ])->save();
        });

        Audit::log('data-request.approved', $case, [
            'kind' => $case->data_kind,
            'starting' => $act,
        ]);

        if (trim($note) !== '' && ! $case->anonymous) {
            $this->cases->comment($case, $note, CaseComment::VISIBILITY_PUBLIC, $actor);
        }

        $removal = null;

        if ($act && $case->data_kind === SafetyCase::DATA_ERASURE) {
            $removal = $this->startErasure($case, $actor);
        }

        if ($case->data_kind !== SafetyCase::DATA_ERASURE) {
            $this->cases->setStatus($case, SafetyCase::STATUS_IN_REVIEW, $actor);
        }

        return ['case' => $case->refresh(), 'removal' => $removal];
    }

    public function decline(SafetyCase $case, User $actor, string $reason): SafetyCase
    {
        $this->mustBeDataRequest($case);

        if (trim($reason) === '') {
            throw new RuntimeException('Declining a data request needs a reason the requester can read.');
        }

        if ($case->data_decision !== null) {
            throw new RuntimeException("{$case->reference} has already been {$case->data_decision}.");
        }

        $case->forceFill([
            'data_decision' => SafetyCase::DECISION_DECLINED,
            'data_decision_note' => $reason,
            'data_decided_by' => $actor->id,
            'data_decided_at' => now(),
        ])->save();

        Audit::log('data-request.declined', $case, ['kind' => $case->data_kind]);

        if (! $case->anonymous) {
            $this->cases->comment($case, $reason, CaseComment::VISIBILITY_PUBLIC, $actor);
        }

        $this->cases->setStatus($case, SafetyCase::STATUS_REJECTED, $actor, $reason);

        return $case->refresh();
    }

    private function startErasure(SafetyCase $case, User $actor): ?DataRemoval
    {
        $subject = $case->reporter;

        if ($subject === null) {
            throw new RuntimeException(
                'There is no account attached to this request, so there is nothing to erase.'
            );
        }

        $existing = DataRemoval::query()
            ->where('subject_id', $subject->id)
            ->outstanding()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->removals->request(
            $subject,
            [
                'reason' => sprintf(
                    'Approved data request %s. %s',
                    $case->reference,
                    $case->data_decision_note ?? '',
                ),
                'legal_basis' => 'gdpr-17',
            ],
            $actor,
            $case,
            $case->investigation,
        );
    }

    private function mustBeDataRequest(SafetyCase $case): void
    {
        if (! $case->isDataRequest()) {
            throw new RuntimeException("{$case->reference} is not a data request.");
        }
    }

    private function mustKnowKind(SafetyCase $case): void
    {
        if ($case->data_kind === null) {
            throw new RuntimeException(
                'Say what this request is asking for before approving it. Approving without '
                .'knowing what was asked for is how a correction gets answered by deletion. '
                .'If it is asking for a copy of what we hold, decline it and point them at '
                .'safety@wikioasis.org: the portal does not answer those.'
            );
        }
    }
}
