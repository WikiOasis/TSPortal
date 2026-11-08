<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\AuditLog;
use App\Models\CaseComment;
use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\InvestigationNote;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\User;
use Illuminate\Support\Collection;

final class Timeline
{
    private const REDUNDANT = [
        'case.created',
        'comment.added',
        'sanction.issued',
        'investigation.note',
        'investigation.opened',
        'data-removal.requested',
        'case.duplicate-merged',
        'case.duplicate-received',
    ];

    /**
     * @return array{actor: ?string, actor_avatar: ?string}
     */
    private static function who(?User $user, ?string $fallback = null): array
    {
        if ($user === null) {
            return ['actor' => self::label($fallback), 'actor_avatar' => null];
        }

        return ['actor' => $user->shownName(), 'actor_avatar' => $user->avatarUrl()];
    }

    private static function label(?string $label): ?string
    {
        return $label === null ? null : (strtolower($label) === 'system' ? 'System' : $label);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forCase(SafetyCase $case): array
    {
        $case->loadMissing([
            'comments.author', 'assignee', 'reporter', 'investigation',
            'sanctionsIssued.subject', 'sanctionsIssued.issuer', 'sanctionsIssued.lifter',
            'duplicateOf', 'duplicateMarker', 'duplicates',
        ]);

        $entries = collect();

        $entries->push([
            'at' => $case->created_at?->toIso8601String(),
            'kind' => 'filed',
            'actor' => $case->anonymous ? 'Anonymous' : ($case->reporter?->username ?? 'the wiki'),
            'title' => sprintf('%s filed', ucfirst($case->type)),
            'body' => $case->summary,
            'visibility' => 'public',
            'meta' => ['wiki' => $case->wiki, 'flow' => $case->flow],
        ]);

        foreach ($case->comments as $comment) {
            $entries->push($this->fromComment($comment));
        }

        foreach ($case->sanctionsIssued as $sanction) {
            foreach ($this->fromSanction($sanction) as $entry) {
                $entries->push($entry);
            }
        }

        foreach (DataRemoval::query()->where('case_id', $case->id)->with(['subject', 'requester'])->get() as $removal) {
            $entries->push($this->fromDataRemoval($removal));
        }

        if ($case->investigation !== null) {
            $entries->push([
                'at' => $case->investigation->opened_at?->toIso8601String(),
                'kind' => 'investigation',
                ...self::who($case->investigation->opener),
                'title' => sprintf('Became part of investigation %s', $case->investigation->reference),
                'body' => $case->investigation->title,
                'visibility' => 'internal',
                'link' => ['route' => 'investigation', 'id' => $case->investigation->id],
                'meta' => ['reference' => $case->investigation->reference],
            ]);
        }

        foreach ($this->fromDuplicates($case) as $entry) {
            $entries->push($entry);
        }

        foreach ($this->auditFor('SafetyCase', $case->id) as $entry) {
            $entries->push($entry);
        }

        return $this->order($entries);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fromDuplicates(SafetyCase $case): array
    {
        $entries = [];

        if ($case->duplicateOf !== null) {
            $entries[] = [
                'at' => $case->duplicate_marked_at?->toIso8601String(),
                'kind' => 'duplicate',
                ...self::who($case->duplicateMarker),
                'title' => sprintf('Merged into %s as a duplicate', $case->duplicateOf->reference),
                'body' => $case->duplicate_note,
                'visibility' => 'internal',
                'link' => ['route' => 'case', 'id' => $case->duplicateOf->id],
                'meta' => [
                    'reference' => $case->duplicateOf->reference,
                    'direction' => 'into',
                ],
            ];
        }

        foreach ($case->duplicates as $duplicate) {
            $entries[] = [
                'at' => $duplicate->duplicate_marked_at?->toIso8601String(),
                'kind' => 'duplicate',
                'actor' => null,
                'title' => sprintf('%s merged in as a duplicate of this', $duplicate->reference),
                'body' => $duplicate->duplicate_note,
                'visibility' => 'internal',
                'link' => ['route' => 'case', 'id' => $duplicate->id],
                'meta' => [
                    'reference' => $duplicate->reference,
                    'direction' => 'in',
                ],
            ];
        }

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forInvestigation(Investigation $investigation): array
    {
        $investigation->loadMissing([
            'notes.author', 'opener', 'assignee', 'closer',
            'cases', 'sanctions.subject', 'sanctions.issuer', 'sanctions.lifter', 'dataRemovals.subject', 'dataRemovals.requester',
        ]);

        $entries = collect();

        $entries->push([
            'at' => $investigation->opened_at?->toIso8601String(),
            'kind' => 'opened',
            ...self::who($investigation->opener),
            'actor_id' => $investigation->opened_by,
            'title' => 'Investigation opened',
            'body' => $investigation->premise,
            'visibility' => 'internal',
            'meta' => ['priority' => $investigation->priority],
        ]);

        foreach ($investigation->notes as $note) {
            $entries->push([
                'at' => $note->created_at?->toIso8601String(),
                'kind' => 'note',
                'subkind' => $note->kind,
                ...self::who($note->author),
                'actor_id' => $note->author_id,
                'title' => $this->noteTitle($note),
                'body' => $note->body,
                'visibility' => 'internal',
                'note_id' => $note->id,
                'reply_to' => $note->reply_to_id,
                'meta' => [],
            ]);
        }

        foreach ($investigation->cases as $case) {
            $entries->push([
                'at' => $case->created_at?->toIso8601String(),
                'kind' => 'case',
                'actor' => $case->anonymous ? 'Anonymous' : null,
                'title' => sprintf('%s %s on the file', ucfirst($case->type), $case->reference),
                'body' => $case->subject_line,
                'visibility' => 'internal',
                'link' => ['route' => 'case', 'id' => $case->id],
                'meta' => ['status' => $case->status, 'reference' => $case->reference],
            ]);
        }

        foreach ($investigation->sanctions as $sanction) {
            foreach ($this->fromSanction($sanction) as $entry) {
                $entries->push($entry);
            }
        }

        foreach ($investigation->dataRemovals as $removal) {
            $entries->push($this->fromDataRemoval($removal));
        }

        if ($investigation->closed_at !== null) {
            $entries->push([
                'at' => $investigation->closed_at->toIso8601String(),
                'kind' => 'concluded',
                ...self::who($investigation->closer),
                'actor_id' => $investigation->closed_by,
                'title' => $investigation->status === Investigation::STATUS_CONCLUDED
                    ? sprintf('Concluded — %s', str_replace('-', ' ', (string) $investigation->outcome))
                    : 'Closed without a finding',
                'body' => $investigation->findings,
                'visibility' => 'internal',
                'meta' => ['outcome' => $investigation->outcome],
            ]);
        }

        foreach ($this->auditFor('Investigation', $investigation->id) as $entry) {
            $entries->push($entry);
        }

        return $this->order($entries);
    }

    /** @return array<string, mixed> */
    private function fromComment(CaseComment $comment): array
    {
        $system = $comment->author_type === CaseComment::AUTHOR_SYSTEM;

        $publicActor = $comment->author_type === CaseComment::AUTHOR_STAFF
            ? ($comment->author_label ?? 'Trust & Safety')
            : null;

        if ($comment->kind === CaseComment::KIND_STATUS) {
            return [
                'at' => $comment->created_at?->toIso8601String(),
                'kind' => 'status-note',
                'subkind' => $comment->author_type,
                ...self::who($comment->author, $comment->author_label),
                'public_actor' => $publicActor,
                'actor_id' => $comment->author_user_id,
                'title' => $comment->isPublic() ? 'Told the reporter' : 'Recorded',
                'body' => $comment->body,
                'visibility' => $comment->visibility,
                'meta' => ['synced' => $comment->synced_at !== null],
            ];
        }

        return [
            'at' => $comment->created_at?->toIso8601String(),
            'kind' => $system ? 'system' : 'comment',
            'subkind' => $comment->author_type,
            ...self::who($comment->author, $comment->author_label ?? ($system ? 'System' : null)),
            'public_actor' => $publicActor,
            'title' => match ($comment->author_type) {
                CaseComment::AUTHOR_SUBJECT => 'The reporter wrote',
                CaseComment::AUTHOR_STAFF => $comment->isPublic() ? 'Replied to the reporter' : 'Internal note',
                default => 'Added a note',
            },
            'body' => $comment->body,
            'visibility' => $comment->visibility,
            'comment_id' => $comment->id,
            'actor_id' => $comment->author_user_id,
            'reply_to' => $comment->reply_to_id,
            'meta' => ['synced' => $comment->synced_at !== null],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fromSanction(Sanction $sanction): array
    {
        $entries = [[
            'at' => $sanction->issued_at?->toIso8601String(),
            'kind' => 'action',
            ...self::who($sanction->issuer),
            'actor_id' => $sanction->issued_by,
            'title' => sprintf('%s — %s', $sanction->label, $sanction->reference),
            'body' => $sanction->reason,
            'visibility' => 'public',
            'link' => ['route' => 'subject', 'id' => $sanction->subject_id],
            'meta' => [
                'reference' => $sanction->reference,
                'type' => $sanction->type,
                'account' => $sanction->subject?->username,
                'scope' => $sanction->scope,
                'expires' => $sanction->expires_at?->toIso8601String(),
                'push_state' => $sanction->push_state,
                'internal_reason' => $sanction->internal_reason,
            ],
        ]];

        if ($sanction->lifted_at !== null) {
            $entries[] = [
                'at' => $sanction->lifted_at->toIso8601String(),
                'kind' => 'action-lifted',
                ...self::who($sanction->lifter),
                'title' => sprintf('%s lifted', $sanction->reference),
                'body' => $sanction->lift_reason,
                'visibility' => 'public',
                'meta' => ['reference' => $sanction->reference],
            ];
        } elseif ($sanction->hasExpired()) {
            $entries[] = [
                'at' => $sanction->expires_at?->toIso8601String(),
                'kind' => 'action-expired',
                'actor' => null,
                'title' => sprintf('%s ran out', $sanction->reference),
                'body' => null,
                'visibility' => 'public',
                'meta' => ['reference' => $sanction->reference],
            ];
        }

        return $entries;
    }

    /** @return array<string, mixed> */
    private function fromDataRemoval(DataRemoval $removal): array
    {
        return [
            'at' => $removal->created_at?->toIso8601String(),
            'kind' => 'data-removal',
            ...self::who($removal->requester),
            'title' => sprintf('Data removal %s — %s', $removal->reference, $removal->label()),
            'body' => $removal->reason,
            'visibility' => 'internal',
            'link' => ['route' => 'data-removals', 'id' => $removal->id],
            'meta' => [
                'reference' => $removal->reference,
                'state' => $removal->state,
                'account' => $removal->previous_username,
                'became' => $removal->target_username,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditFor(string $type, int $id): array
    {
        return AuditLog::query()
            ->where('target_type', $type)
            ->where('target_id', $id)
            ->whereNotIn('action', self::REDUNDANT)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $log) => [
                'at' => $log->created_at?->toIso8601String(),
                'kind' => 'audit',
                'subkind' => $log->action,
                ...self::who($log->user, $log->actor_label ?? 'System'),
                'actor_id' => $log->user_id,
                'title' => self::titleFor($log),
                'body' => null,
                'visibility' => 'internal',
                'meta' => $log->meta ?? [],
            ])
            ->all();
    }

    public static function titleFor(AuditLog $log): string
    {
        $meta = $log->meta ?? [];

        return match ($log->action) {
            'appeal.decided' => match ($meta['outcome'] ?? null) {
                SafetyCase::APPEAL_GRANTED => 'Appeal granted',
                SafetyCase::APPEAL_PARTLY_GRANTED => 'Appeal partly granted',
                SafetyCase::APPEAL_DECLINED => 'Appeal declined',
                SafetyCase::APPEAL_WITHDRAWN => 'Appeal withdrawn',
                SafetyCase::APPEAL_INVALID => 'Nothing to appeal against',
                default => 'Appeal decided',
            },
            'appeal.linked' => isset($meta['to']) && $meta['to'] !== null
                ? sprintf('Linked to %s', $meta['to'])
                : 'Unlinked from an action',
            'case.status' => sprintf(
                'Status changed from %s to %s',
                str_replace('-', ' ', (string) ($meta['from'] ?? '?')),
                str_replace('-', ' ', (string) ($meta['to'] ?? '?')),
            ),
            'case.assigned' => isset($meta['assignee']) && $meta['assignee'] !== null
                ? sprintf('Assigned to %s', $meta['assignee'])
                : 'Unassigned',
            'case.priority' => sprintf('Priority set to %s', $meta['to'] ?? '?'),
            'case.duplicate-merged' => sprintf(
                'Closed as a duplicate of %s',
                $meta['of'] ?? 'another report',
            ),
            'case.duplicate-received' => sprintf(
                '%s merged in as a duplicate of this',
                $meta['case'] ?? 'Another report',
            ),
            'case.duplicate-unmerged' => isset($meta['was']) && $meta['was'] !== null
                ? sprintf('Taken back out of %s; it is not a duplicate after all', $meta['was'])
                : 'No longer marked as a duplicate',
            'investigation.status' => sprintf(
                'File moved from %s to %s',
                $meta['from'] ?? '?',
                $meta['to'] ?? '?',
            ),
            'investigation.priority' => sprintf('Priority set to %s', $meta['to'] ?? '?'),
            'investigation.review' => isset($meta['to']) && $meta['to'] !== null
                ? sprintf('To be looked at again on %s', $meta['to'])
                : 'No longer due a second look',
            'investigation.assigned' => isset($meta['assignee']) && $meta['assignee'] !== null
                ? sprintf('Assigned to %s', $meta['assignee'])
                : 'Unassigned',
            'investigation.case-attached' => sprintf('%s attached', $meta['case'] ?? 'A case'),
            'investigation.case-detached' => sprintf('%s taken off the file', $meta['case'] ?? 'A case'),
            'investigation.subject-added' => sprintf(
                '%s named as %s',
                $meta['subject'] ?? 'An account',
                $meta['role'] ?? 'subject',
            ),
            'investigation.subject-removed' => sprintf('%s taken off the file', $meta['subject'] ?? 'An account'),
            'investigation.subjects-added' => sprintf(
                '%d account%s named on the file at once',
                count((array) ($meta['subjects'] ?? [])),
                count((array) ($meta['subjects'] ?? [])) === 1 ? '' : 's',
            ),
            'investigation.bulk-action' => self::bulkTitle($meta),
            'investigation.concluded' => 'Concluded',
            'investigation.closed' => 'Closed',
            'sanction.lifted' => 'Action lifted',
            'sanction.expired' => 'Action expired',
            'data-removal.approved' => 'Approved the data removal',
            'data-removal.started' => 'Started the data removal',
            'data-removal.refused' => 'Refused the data removal',
            'data-removal.renamed' => 'Renamed the account to an anonymous identifier',
            'data-removal.sent' => 'Sent the data removal to the wikis',
            'data-removal.completed' => 'Finished the data removal',
            'data-removal.failed' => 'Could not complete the data removal',
            'data-request.kind' => isset($meta['to'])
                ? sprintf('Set the request type to %s', str_replace('-', ' ', (string) $meta['to']))
                : 'Set the request type',
            'data-request.approved' => 'Approved the data request',
            'data-request.declined' => 'Declined the data request',
            'case.categorised' => 'Updated the categories',
            'case.escalated' => 'Escalated this',
            default => ucfirst(str_replace(['.', '-'], ' ', $log->action)),
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function bulkTitle(array $meta): string
    {
        $done = (int) ($meta['done'] ?? 0);
        $failed = (int) ($meta['failed'] ?? 0);

        $what = ($meta['kind'] ?? null) === 'erasure'
            ? 'erasure'
            : str_replace('-', ' ', (string) ($meta['type'] ?? 'action'));

        return sprintf(
            '%d %s%s taken against accounts at once%s',
            $done,
            $what,
            $done === 1 ? '' : 's',
            $failed > 0 ? sprintf(' (%d could not be done)', $failed) : '',
        );
    }

    private function noteTitle(InvestigationNote $note): string
    {
        return match ($note->kind) {
            InvestigationNote::KIND_FINDING => 'Finding',
            InvestigationNote::KIND_DECISION => 'Decision',
            InvestigationNote::KIND_CONTACT => 'Contact',
            InvestigationNote::KIND_EVIDENCE => 'Evidence',
            default => 'Note',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function order(Collection $entries): array
    {
        $rank = fn (array $e) => match ($e['kind']) {
            'filed', 'opened' => -1,
            'comment', 'note' => 1,
            default => 0,
        };

        return $entries
            ->values()
            ->sort(fn (array $a, array $b) => [$a['at'] ?? '9999', $rank($a)] <=> [$b['at'] ?? '9999', $rank($b)])
            ->values()
            ->map(fn (array $e) => $e + ['subkind' => null, 'link' => null, 'body' => null, 'meta' => []])
            ->all();
    }
}
