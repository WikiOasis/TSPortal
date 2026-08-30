<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\CaseComment;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AppealService
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly SanctionService $sanctions,
        private readonly WikiSync $sync,
    ) {}

    public function link(SafetyCase $case, ?Sanction $sanction, User $actor): SafetyCase
    {
        $this->mustBeAnAppeal($case);

        if ($sanction !== null && $case->reporter !== null && $sanction->subject_id !== $case->reporter->id) {
            throw new RuntimeException(sprintf(
                '%s is against %s, and this appeal is from %s. Attaching it would put this '
                .'appeal on another account\'s record.',
                $sanction->reference,
                $sanction->subject?->username ?? 'another account',
                $case->reporter->username,
            ));
        }

        $from = $case->sanction_id;

        $notes = (array) ($case->appeal_link_notes ?? []);
        $notes['explanation'] = 'Set by hand.';
        $notes['corrected'] = [
            'by' => $actor->username,
            'at' => now()->toIso8601String(),
            'from' => $from === null ? null : Sanction::query()->whereKey($from)->value('reference'),
            'to' => $sanction?->reference,
        ];

        $case->forceFill([
            'sanction_id' => $sanction?->id,
            'appeal_link_source' => $sanction === null ? AppealMatch::SOURCE_NONE : AppealMatch::SOURCE_STAFF,
            'appeal_link_confidence' => $sanction === null ? AppealMatch::NONE : AppealMatch::CERTAIN,
            'appeal_link_notes' => $notes,
        ]);

        if ($case->investigation_id === null && $sanction?->investigation_id !== null) {
            $case->investigation_id = $sanction->investigation_id;
        }

        $case->save();

        Audit::log('appeal.linked', $case, [
            'from' => $notes['corrected']['from'],
            'to' => $sanction?->reference,
            'by' => $actor->username,
        ]);

        $this->sync->pushCase($case);

        return $case->refresh();
    }

    /**
     * @return array{case: SafetyCase, lifted: ?Sanction}
     */
    public function decide(
        SafetyCase $case,
        string $outcome,
        User $actor,
        string $note,
        bool $lift = true,
    ): array {
        $this->mustBeAnAppeal($case);

        if (! in_array($outcome, SafetyCase::APPEAL_OUTCOMES, true)) {
            throw new RuntimeException("'{$outcome}' is not a way an appeal can end.");
        }

        if ($case->appealDecided()) {
            throw new RuntimeException(
                "{$case->reference} was already {$case->appeal_outcome}. Reopening a decided appeal "
                .'is a new appeal, not an edit of this one.'
            );
        }

        if (trim($note) === '') {
            throw new RuntimeException('An appeal decision needs something the appellant can read.');
        }

        $action = $case->sanction;

        if (in_array($outcome, SafetyCase::APPEAL_ACCEPTED, true) && $action === null) {
            throw new RuntimeException(
                'Say which action this appeal is against before granting it. An appeal granted '
                .'against nothing lifts nothing, and is counted in the overall acceptance rate '
                .'while appearing in no category of it.'
            );
        }

        $lifted = null;

        $decision = DB::transaction(function () use ($case, $outcome, $actor, $note) {
            $case->forceFill([
                'appeal_outcome' => $outcome,
                'appeal_outcome_note' => $note,
                'appeal_decided_by' => $actor->id,
                'appeal_decided_at' => now(),
            ])->save();

            return $case;
        });

        Audit::log('appeal.decided', $case, [
            'outcome' => $outcome,
            'action' => $action?->reference,
            'reason_category' => $action?->reason_category,
            'link' => $case->appeal_link_source,
            'link_confidence' => $case->appeal_link_confidence,
        ]);

        if ($lift && in_array($outcome, SafetyCase::APPEAL_UNDOES_ACTION, true) && $action?->isInForce()) {
            $lifted = $this->sanctions->lift(
                $action,
                $actor,
                sprintf('Appeal %s granted. %s', $case->reference, $note),
            );
        }

        if (! $case->anonymous) {
            $this->cases->comment($case, $note, CaseComment::VISIBILITY_PUBLIC, $actor);
        }

        $this->cases->setStatus($case, $this->statusFor($outcome), $actor, $note);

        if (in_array($outcome, SafetyCase::APPEAL_ACCEPTED, true) && $case->closed_at === null) {
            $case->forceFill(['closed_at' => now()])->save();
        }

        return ['case' => $case->refresh(), 'lifted' => $lifted];
    }

    private function statusFor(string $outcome): string
    {
        return match ($outcome) {
            SafetyCase::APPEAL_GRANTED, SafetyCase::APPEAL_PARTLY_GRANTED => SafetyCase::STATUS_ACTION_TAKEN,
            SafetyCase::APPEAL_WITHDRAWN => SafetyCase::STATUS_CLOSED,
            default => SafetyCase::STATUS_REJECTED,
        };
    }

    private function mustBeAnAppeal(SafetyCase $case): void
    {
        if (! $case->isAppeal()) {
            throw new RuntimeException("{$case->reference} is not an appeal.");
        }
    }
}
