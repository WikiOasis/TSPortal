<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Model;

final class SearchPreview
{
    private const EXCERPT = 420;

    public function of(string $kind, int $id): ?array
    {
        $model = SearchDocuments::MODELS[$kind] ?? null;

        if ($model === null) {
            return null;
        }

        $row = $model::query()->with($this->loads($kind))->find($id);

        if ($row === null) {
            return null;
        }

        return array_merge([
            'kind' => $kind,
            'kind_label' => SearchDocuments::label($kind),
            'route' => SearchDocuments::route($kind),
            'id' => $row->getKey(),
            'reference' => null,
            'title' => '',
            'subtitle' => null,
            'status' => null,
            'facts' => [],
            'counts' => [],
            'accounts' => [],
            'excerpt' => null,
            'excerpt_label' => null,
        ], $this->build($kind, $row));
    }

    private function loads(string $kind): array
    {
        return match ($kind) {
            SearchDocuments::KIND_CASE => [
                'assignee', 'reporter', 'subjects', 'investigation',
                'comments' => fn ($q) => $q->latest()->limit(1),
            ],
            SearchDocuments::KIND_INVESTIGATION => [
                'assignee', 'opener', 'subjects',
                'notes' => fn ($q) => $q->latest()->limit(1),
            ],
            SearchDocuments::KIND_SANCTION => ['subject', 'issuer', 'safetyCase', 'investigation'],
            SearchDocuments::KIND_REMOVAL => ['subject', 'requester', 'approver', 'safetyCase'],
            default => [],
        };
    }

    private function build(string $kind, Model $row): array
    {
        return match ($kind) {
            SearchDocuments::KIND_CASE => $this->case($row),
            SearchDocuments::KIND_INVESTIGATION => $this->investigation($row),
            SearchDocuments::KIND_SUBJECT => $this->subject($row),
            SearchDocuments::KIND_SANCTION => $this->sanction($row),
            SearchDocuments::KIND_REMOVAL => $this->removal($row),
            default => [],
        };
    }

    private function case(Model $case): array
    {
        $comment = $case->comments->first();

        return [
            'reference' => $case->reference,
            'title' => $case->subject_line ?: $case->reference,
            'subtitle' => $case->investigation?->title,
            'status' => $case->status,
            'status_of' => 'case',
            'type' => $case->type,
            'priority' => $case->priority,
            'threat_to_life' => $case->isThreatToLife(),

            'facts' => $this->facts([
                'Filed' => $case->created_at?->toIso8601String(),
                'Last touched' => $case->updated_at?->toIso8601String(),
                'With' => $case->assignee?->username ?? 'Nobody yet',
                'From' => $case->anonymous ? 'Filed anonymously' : $case->reporter?->username,
                'Wiki' => $case->wiki,
                'File' => $case->investigation?->reference,
            ]),

            'counts' => $this->counts([
                'Conversation' => $case->comments()->count(),
                'Attachments' => $case->attachments()->count(),
                'Actions' => $case->sanctionsIssued()->count(),
            ]),

            'accounts' => $case->subjects->map(fn (Subject $s) => [
                'id' => $s->id,
                'username' => $s->username,
                'role' => $s->pivot->role,
                'standing' => $s->standing,
            ])->all(),

            'excerpt' => $this->excerpt($case->summary ?: $comment?->body),
            'excerpt_label' => $case->summary ? 'What was reported' : ($comment ? 'Latest message' : null),
        ];
    }

    private function investigation(Model $investigation): array
    {
        $note = $investigation->notes->first();

        return [
            'reference' => $investigation->reference,
            'title' => $investigation->title,
            'status' => $investigation->status,
            'status_of' => 'investigation',
            'priority' => $investigation->priority,

            'facts' => $this->facts([
                'Opened' => $investigation->opened_at?->toIso8601String() ?? $investigation->created_at?->toIso8601String(),
                'Last touched' => $investigation->updated_at?->toIso8601String(),
                'With' => $investigation->assignee?->username ?? 'Nobody yet',
                'Opened by' => $investigation->opener?->username,
                'Review due' => $investigation->review_at?->toIso8601String(),
                'Outcome' => $investigation->outcome,
            ]),

            'counts' => $this->counts([
                'Reports' => $investigation->cases()->count(),
                'Actions' => $investigation->sanctions()->count(),
                'Notes' => $investigation->notes()->count(),
            ]),

            'accounts' => $investigation->subjects->map(fn (Subject $s) => [
                'id' => $s->id,
                'username' => $s->username,
                'role' => $s->pivot->role,
                'standing' => $s->standing,
            ])->all(),

            'excerpt' => $this->excerpt($investigation->premise ?: $note?->body),
            'excerpt_label' => $investigation->premise ? 'Premise' : ($note ? 'Latest note' : null),
        ];
    }

    private function subject(Model $subject): array
    {
        return [
            'title' => $subject->username,
            'subtitle' => $subject->wiki_username,
            'status' => $subject->standing,
            'status_of' => 'standing',

            'facts' => $this->facts([
                'Registered' => $subject->registered_at?->toIso8601String(),
                'Known since' => $subject->created_at?->toIso8601String(),
                'Central id' => $subject->mw_central_id,
                'Banned' => $subject->banned ? 'Yes' : null,
                'Erased' => $subject->erased_at?->toIso8601String(),
            ]),

            'counts' => $this->counts([
                'Reports about them' => $subject->cases()->count(),
                'Actions' => $subject->sanctions()->count(),
            ]),

            'excerpt' => $this->excerpt($subject->notes),
            'excerpt_label' => $subject->notes ? 'Notes' : null,
        ];
    }

    private function sanction(Model $sanction): array
    {
        return [
            'reference' => $sanction->reference,
            'title' => $sanction->subject?->username ?: ($sanction->label ?: $sanction->reference),
            'subtitle' => $sanction->label,
            'status' => $sanction->push_state,
            'status_of' => 'push',
            'type' => $sanction->type,

            'facts' => $this->facts([
                'Issued' => $sanction->issued_at?->toIso8601String(),
                'By' => $sanction->issuer?->username,
                'Expires' => $sanction->expires_at?->toIso8601String(),
                'In force' => $sanction->isInForce() ? 'Yes' : 'No',
                'Where' => $sanction->whereItApplies(),
                'Lifted' => $sanction->lifted_at?->toIso8601String(),
                'From' => $sanction->safetyCase?->reference ?? $sanction->investigation?->reference,
            ]),

            'accounts' => $sanction->subject === null ? [] : [[
                'id' => $sanction->subject->id,
                'username' => $sanction->subject->username,
                'role' => 'subject',
                'standing' => $sanction->subject->standing,
            ]],

            'excerpt' => $this->excerpt($sanction->reason),
            'excerpt_label' => $sanction->reason ? 'Reason given' : null,
        ];
    }

    private function removal(Model $removal): array
    {
        return [
            'reference' => $removal->reference,
            'title' => $removal->target_username ?: $removal->reference,
            'subtitle' => $removal->label(),
            'status' => $removal->state,
            'status_of' => 'removal',

            'facts' => $this->facts([
                'Requested' => $removal->created_at?->toIso8601String(),
                'By' => $removal->requester?->username,
                'Approved' => $removal->approved_at?->toIso8601String(),
                'Was' => $removal->previous_username,
                'Basis' => $removal->legal_basis,
                'From' => $removal->safetyCase?->reference,
                'Finished' => $removal->completed_at?->toIso8601String(),
            ]),

            'accounts' => $removal->subject === null ? [] : [[
                'id' => $removal->subject->id,
                'username' => $removal->subject->username,
                'role' => 'subject',
                'standing' => $removal->subject->standing,
            ]],

            'excerpt' => $this->excerpt($removal->reason ?: $removal->refusal_reason),
            'excerpt_label' => $removal->reason ? 'Why' : ($removal->refusal_reason ? 'Why it was refused' : null),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array{label: string, value: string, date: bool}>
     */
    private function facts(array $facts): array
    {
        $rows = [];

        foreach ($facts as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $rows[] = [
                'label' => $label,
                'value' => (string) $value,
                'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) === 1,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{label: string, value: int}>
     */
    private function counts(array $counts): array
    {
        $rows = [];

        foreach ($counts as $label => $value) {
            if ($value > 0) {
                $rows[] = ['label' => $label, 'value' => $value];
            }
        }

        return $rows;
    }

    private function excerpt(?string $text): ?string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > self::EXCERPT
            ? rtrim(mb_substr($text, 0, self::EXCERPT)).'…'
            : $text;
    }
}
