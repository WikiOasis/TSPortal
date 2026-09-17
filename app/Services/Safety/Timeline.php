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
                'actor' => $case->investigation->opener?->username,
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
                'actor' => $case->duplicateMarker?->username,
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
            'actor' => $investigation->opener?->username,
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
                'actor' => $note->author?->username,
                'title' => $this->noteTitle($note),
                'body' => $note->body,
                'visibility' => 'internal',
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
                'actor' => $investigation->closer?->username,
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

        return [
            'at' => $comment->created_at?->toIso8601String(),
            'kind' => $system ? 'system' : 'comment',
            'subkind' => $comment->author_type,
            'actor' => $comment->author_label ?? ($system ? 'The portal' : null),
            'title' => match ($comment->author_type) {
                CaseComment::AUTHOR_SUBJECT => 'The reporter wrote',
                CaseComment::AUTHOR_STAFF => $comment->isPublic() ? 'Replied to the reporter' : 'Internal note',
                default => 'Recorded',
            },
            'body' => $comment->body,
            'visibility' => $comment->visibility,
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
            'actor' => $sanction->issuer?->username,
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
                'actor' => $sanction->lifter?->username,
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
            'actor' => $removal->requester?->username,
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
                'actor' => $log->user?->username ?? $log->actor_label ?? 'the portal',
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
            'data-removal.approved' => 'Data removal approved',
            'data-removal.refused' => 'Data removal refused',
            'data-removal.sent' => 'Data removal sent to the wikis',
            'data-removal.completed' => 'Data removal finished',
            'data-removal.failed' => 'Data removal could not be completed',
            default => $log->action,
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
        return $entries
            ->values()
            ->sortBy(fn (array $e) => $e['at'] ?? '9999')
            ->values()
            ->map(fn (array $e) => $e + ['subkind' => null, 'link' => null, 'body' => null, 'meta' => []])
            ->all();
    }
}
