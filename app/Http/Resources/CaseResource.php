<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CaseComment;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SafetyCase
 */
class CaseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'flow' => $this->flow,
            'subject' => $this->subject_line,
            'summary' => $this->summary,
            'status' => $this->status,
            'priority' => $this->priority,
            'anonymous' => $this->anonymous,
            'wiki' => $this->wiki,
            'about' => $this->about ?? [],

            'category' => $this->category,
            'category_group' => $this->category_group,
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($c) => [
                'id' => $c->category,
                'label' => $c->label,
                'group' => $c->group,
                'primary' => $c->is_primary,
                'field' => $c->source_field,
            ])->all()),

            'threat_to_life' => $this->isThreatToLife(),
            'automated' => (bool) $this->automated,

            'duplicate_of' => $this->whenLoaded('duplicateOf', fn () => $this->duplicateOf === null ? null : [
                'id' => $this->duplicateOf->id,
                'reference' => $this->duplicateOf->reference,
                'subject' => $this->duplicateOf->subject_line,
                'status' => $this->duplicateOf->status,
                'note' => $this->duplicate_note,
                'marked_by' => $this->whenLoaded('duplicateMarker', fn () => $this->duplicateMarker?->username),
                'marked_at' => $this->duplicate_marked_at?->toIso8601String(),
            ]),

            'duplicates' => $this->whenLoaded('duplicates', fn () => $this->duplicates->map(fn (SafetyCase $d) => [
                'id' => $d->id,
                'reference' => $d->reference,
                'subject' => $d->subject_line,
                'status' => $d->status,
                'anonymous' => $d->anonymous,
                'note' => $d->duplicate_note,
                'merged_at' => $d->duplicate_marked_at?->toIso8601String(),
            ])->all()),

            'filed' => $this->created_at?->toIso8601String(),
            'updated' => $this->updated_at?->toIso8601String(),
            'closed' => $this->closed_at?->toIso8601String(),
            'resolution' => $this->resolution,

            'data_request' => $this->when($this->type === SafetyCase::TYPE_DATA, fn () => [
                'kind' => $this->data_kind,
                'decision' => $this->data_decision,
                'note' => $this->data_decision_note,
                'decided_at' => $this->data_decided_at?->toIso8601String(),
                'decided_by' => $this->whenLoaded('decider', fn () => $this->decider?->username),
                'still_owed' => $this->dataRequestOutstanding(),
            ]),

            'appeal' => $this->when($this->type === SafetyCase::TYPE_APPEAL, fn () => [
                'action' => $this->whenLoaded('sanction', fn () => $this->sanction === null ? null : [
                    'id' => $this->sanction->id,
                    'reference' => $this->sanction->reference,
                    'label' => $this->sanction->label,
                    'type' => $this->sanction->type,
                    'reason' => $this->sanction->reason,

                    'reason_category' => $this->sanction->reason_category,
                    'reason_category_label' => $this->sanction->reasonCategoryLabel(),
                    'issued' => $this->sanction->issued_at?->toIso8601String(),
                    'expires' => $this->sanction->expires_at?->toIso8601String(),
                    'in_force' => $this->sanction->isInForce(),
                    'appealable' => (bool) $this->sanction->appealable,
                    'lifted' => $this->sanction->lifted_at?->toIso8601String(),
                    'where' => $this->sanction->whereItApplies(),
                ]),

                'link' => [
                    'source' => $this->appeal_link_source,
                    'confidence' => $this->appeal_link_confidence,

                    'needs_checking' => $this->appealLinkNeedsChecking(),
                    'explanation' => $this->appeal_link_notes['explanation'] ?? null,
                    'notes' => $this->appeal_link_notes['notes'] ?? [],
                    'considered' => $this->appeal_link_notes['considered'] ?? [],
                    'corrected' => $this->appeal_link_notes['corrected'] ?? null,
                ],

                'options' => $this->when(
                    $this->relationLoaded('reporter')
                        && $this->reporter !== null
                        && $this->reporter->relationLoaded('sanctions'),
                    fn () => $this->reporter->sanctions->map(fn (Sanction $s) => [
                        'id' => $s->id,
                        'reference' => $s->reference,
                        'label' => $s->label,
                        'reason_category' => $s->reason_category,
                        'issued' => $s->issued_at?->toIso8601String(),
                        'in_force' => $s->isInForce(),
                        'appealable' => (bool) $s->appealable,
                    ])->values()->all(),
                ),

                'outcome' => $this->appeal_outcome,
                'note' => $this->appeal_outcome_note,
                'decided_at' => $this->appeal_decided_at?->toIso8601String(),
                'decided_by' => $this->whenLoaded('appealDecider', fn () => $this->appealDecider?->username),
            ]),

            'reporter' => $this->when(
                ! $this->anonymous && $this->relationLoaded('reporter') && $this->reporter !== null,
                fn () => [
                    'id' => $this->reporter->id,
                    'username' => $this->reporter->username,
                    'central_id' => $this->reporter->mw_central_id,
                    'standing' => $this->reporter->standing,
                ],
            ),

            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee === null ? null : [
                'id' => $this->assignee->id,
                'username' => $this->assignee->username,
            ]),

            'investigation' => $this->whenLoaded('investigation', fn () => $this->investigation === null ? null : [
                'id' => $this->investigation->id,
                'reference' => $this->investigation->reference,
                'title' => $this->investigation->title,
                'status' => $this->investigation->status,
                'live' => $this->investigation->isLive(),
            ]),

            'subjects' => $this->whenLoaded('subjects', fn () => $this->subjects->map(fn (Subject $s) => [
                'id' => $s->id,
                'username' => $s->username,
                'standing' => $s->standing,
                'banned' => $s->banned,
                'role' => $s->pivot->role,
            ])->all()),

            'comments' => $this->whenLoaded('comments', fn () => $this->comments->map(fn (CaseComment $c) => [
                'id' => $c->id,
                'author_type' => $c->author_type,
                'author' => $c->author_label,
                'body' => $c->body,
                'visibility' => $c->visibility,
                'date' => $c->created_at?->toIso8601String(),
                'synced' => $c->synced_at !== null,
            ])->all()),

            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'mime' => $a->mime,
                'size' => $a->size,
                'uploaded' => $a->hasFile(),

                'state' => $a->state(),
                'reason' => $a->refused_reason,

                'url' => $a->hasFile() ? route('portal.attachments.show', $a->id) : null,
            ])->all()),

            'sanctions' => $this->whenLoaded('sanctionsIssued', fn () => $this->sanctionsIssued->map(fn (Sanction $s) => [
                'id' => $s->id,
                'reference' => $s->reference,
                'label' => $s->label,
                'active' => $s->isInForce(),
            ])->all()),

            'answers' => $this->when(! $request->routeIs('*.index'), fn () => $this->answers ?? []),

            'sync' => [
                'at' => $this->synced_at?->toIso8601String(),
                'error' => $this->sync_error,
            ],
        ];
    }
}
