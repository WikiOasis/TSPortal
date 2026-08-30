<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\CaseComment;
use App\Models\Investigation;
use App\Models\InvestigationNote;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class InvestigationService
{
    public function __construct(private readonly CaseService $cases) {}

    /**
     * @param  array{title?: ?string, premise?: ?string, priority?: ?string,
     *               subjects?: list<array{username: string, central_id?: ?int, role?: ?string, note?: ?string}>,
     *               review_at?: ?string, assign_to_me?: bool}  $input
     */
    public function open(array $input, User $actor, ?SafetyCase $from = null): Investigation
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = $from !== null
                ? sprintf('Investigation arising from %s', $from->reference)
                : 'Investigation';
        }

        $investigation = DB::transaction(function () use ($input, $actor, $title) {
            $reference = References::allocate('Investigation', $title);

            $investigation = Investigation::create([
                'reference' => $reference,
                'title' => $title,
                'premise' => $input['premise'] ?? null,
                'status' => Investigation::STATUS_OPEN,
                'priority' => $input['priority'] ?? 'normal',
                'opened_by' => $actor->id,
                'assigned_to' => ($input['assign_to_me'] ?? true) ? $actor->id : null,
                'opened_at' => now(),
                'review_at' => $input['review_at'] ?? null,
            ]);

            References::attach($reference, $investigation, $title);

            return $investigation;
        });

        foreach ((array) ($input['subjects'] ?? []) as $named) {
            if (! is_array($named) || ! isset($named['username'])) {
                continue;
            }
            $this->addSubject(
                $investigation,
                (string) $named['username'],
                isset($named['central_id']) ? (int) $named['central_id'] : null,
                (string) ($named['role'] ?? 'subject'),
                isset($named['note']) ? (string) $named['note'] : null,
                audit: false,
            );
        }

        Audit::log('investigation.opened', $investigation, [
            'reference' => $investigation->reference,
            'from_case' => $from?->reference,
            'subjects' => $investigation->subjects()->pluck('username')->all(),
        ]);

        if ($from !== null) {
            $this->attachCase($investigation, $from, $actor);
        }

        return $investigation;
    }

    public function attachCase(Investigation $investigation, SafetyCase $case, User $actor): SafetyCase
    {
        if ($case->investigation_id === $investigation->id) {
            return $case;
        }

        $case->investigation_id = $investigation->id;
        $case->save();

        Audit::log('investigation.case-attached', $investigation, [
            'case' => $case->reference,
            'previous' => $case->getOriginal('investigation_id'),
        ]);

        $case->loadMissing('subjects');
        foreach ($case->subjects as $subject) {
            $this->linkSubject($investigation, $subject, $subject->pivot->role === 'reporter' ? 'reporter' : 'subject');
        }

        if ($case->isOpen() && $case->status !== SafetyCase::STATUS_INVESTIGATING) {
            $this->cases->setStatus($case, SafetyCase::STATUS_INVESTIGATING, $actor);
        }

        return $case->refresh();
    }

    public function detachCase(Investigation $investigation, SafetyCase $case, User $actor): SafetyCase
    {
        $case->investigation_id = null;
        $case->save();

        Audit::log('investigation.case-detached', $investigation, ['case' => $case->reference]);

        if ($case->status === SafetyCase::STATUS_INVESTIGATING) {
            $this->cases->setStatus($case, SafetyCase::STATUS_IN_REVIEW, $actor);
        }

        return $case->refresh();
    }

    public function addSubject(
        Investigation $investigation,
        string $username,
        ?int $centralId = null,
        string $role = 'subject',
        ?string $note = null,
        bool $audit = true,
    ): Subject {
        $subject = Subject::forUsername($username, $centralId);

        $this->linkSubject($investigation, $subject, $role, $note);

        if ($audit) {
            Audit::log('investigation.subject-added', $investigation, [
                'subject' => $subject->username,
                'role' => $role,
            ]);
        }

        return $subject;
    }

    public function removeSubject(Investigation $investigation, Subject $subject): void
    {
        $investigation->subjects()->detach($subject->id);

        Audit::log('investigation.subject-removed', $investigation, ['subject' => $subject->username]);
    }

    public function note(Investigation $investigation, string $body, string $kind, User $author): InvestigationNote
    {
        $kind = in_array($kind, InvestigationNote::KINDS, true) ? $kind : InvestigationNote::KIND_NOTE;

        $note = InvestigationNote::create([
            'investigation_id' => $investigation->id,
            'author_id' => $author->id,
            'kind' => $kind,
            'body' => $body,
        ]);

        $investigation->touch();

        Audit::log('investigation.note', $investigation, ['kind' => $kind, 'note_id' => $note->id]);

        return $note;
    }

    public function assign(Investigation $investigation, ?User $assignee): Investigation
    {
        $investigation->assigned_to = $assignee?->id;
        $investigation->save();

        Audit::log('investigation.assigned', $investigation, ['assignee' => $assignee?->username]);

        return $investigation;
    }

    public function setStatus(Investigation $investigation, string $status, User $actor, ?string $reviewAt = null): Investigation
    {
        if (! in_array($status, Investigation::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown investigation status '{$status}'.");
        }

        $from = $investigation->status;
        if ($from === $status) {
            return $investigation;
        }

        $investigation->status = $status;

        if (in_array($status, Investigation::LIVE_STATUSES, true)) {
            $investigation->closed_at = null;
            $investigation->closed_by = null;
            $investigation->review_at = $reviewAt ?? $investigation->review_at;
        }

        $investigation->save();

        Audit::log('investigation.status', $investigation, ['from' => $from, 'to' => $status]);

        return $investigation;
    }

    /**
     * @param  array{outcome?: ?string, findings?: ?string, disclosable?: bool, message?: ?string}  $input
     */
    public function conclude(Investigation $investigation, User $actor, array $input): Investigation
    {
        $outcome = $input['outcome'] ?? null;
        if ($outcome === null || ! in_array($outcome, Investigation::OUTCOMES, true)) {
            $outcome = $investigation->inferredOutcome();
        }

        $acted = ! in_array($outcome, [
            Investigation::OUTCOME_NO_ACTION,
            Investigation::OUTCOME_UNFOUNDED,
            Investigation::OUTCOME_INSUFFICIENT,
        ], true);

        DB::transaction(function () use ($investigation, $actor, $input, $outcome) {
            $investigation->forceFill([
                'status' => Investigation::STATUS_CONCLUDED,
                'outcome' => $outcome,
                'findings' => $input['findings'] ?? $investigation->findings,
                'outcome_disclosable' => (bool) ($input['disclosable'] ?? false),
                'closed_by' => $actor->id,
                'closed_at' => now(),
                'review_at' => null,
            ])->save();
        });

        Audit::log('investigation.concluded', $investigation, [
            'outcome' => $outcome,
            'cases' => $investigation->cases()->pluck('reference')->all(),
        ]);

        $status = $acted ? SafetyCase::STATUS_ACTION_TAKEN : SafetyCase::STATUS_REJECTED;
        $message = trim((string) ($input['message'] ?? ''));

        foreach ($investigation->cases()->get() as $case) {
            if (! $case->isOpen()) {
                continue;
            }

            if ($message !== '' && $investigation->outcome_disclosable && ! $case->anonymous) {
                $this->cases->comment($case, $message, CaseComment::VISIBILITY_PUBLIC, $actor);
            }

            $this->cases->setStatus($case, $status, $actor, $this->resolutionFor($outcome));
        }

        return $investigation->refresh();
    }

    public function close(Investigation $investigation, User $actor, ?string $why = null): Investigation
    {
        $investigation->forceFill([
            'status' => Investigation::STATUS_CLOSED,
            'findings' => $why ?? $investigation->findings,
            'closed_by' => $actor->id,
            'closed_at' => now(),
            'review_at' => null,
        ])->save();

        Audit::log('investigation.closed', $investigation, ['why' => $why]);

        foreach ($investigation->cases()->get() as $case) {
            if ($case->isOpen()) {
                $this->cases->setStatus($case, SafetyCase::STATUS_CLOSED, $actor);
            }
        }

        return $investigation;
    }

    private function linkSubject(Investigation $investigation, Subject $subject, string $role, ?string $note = null): void
    {
        $role = in_array($role, ['subject', 'witness', 'reporter', 'related'], true) ? $role : 'subject';

        $investigation->subjects()->syncWithoutDetaching([
            $subject->id => array_filter(['role' => $role, 'note' => $note], fn ($v) => $v !== null),
        ]);
    }

    private function resolutionFor(string $outcome): string
    {
        return match ($outcome) {
            Investigation::OUTCOME_SUSPENDED => 'The account was suspended.',
            Investigation::OUTCOME_RESTRICTED => 'The account was restricted.',
            Investigation::OUTCOME_WARNED => 'The account was warned.',
            Investigation::OUTCOME_REFERRED => 'Referred on; no action taken here.',
            Investigation::OUTCOME_UNFOUNDED => 'Investigated and not borne out.',
            Investigation::OUTCOME_INSUFFICIENT => 'Investigated; not enough to act on.',
            default => 'Investigated; no action taken.',
        };
    }
}
