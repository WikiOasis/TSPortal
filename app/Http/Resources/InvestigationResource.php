<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\InvestigationNote;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Investigation
 */
class InvestigationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'premise' => $this->premise,
            'status' => $this->status,
            'priority' => $this->priority,
            'outcome' => $this->outcome,
            'outcome_disclosable' => $this->outcome_disclosable,
            'findings' => $this->findings,
            'opened' => $this->opened_at?->toIso8601String(),
            'closed' => $this->closed_at?->toIso8601String(),
            'review_at' => $this->review_at?->toIso8601String(),
            'updated' => $this->updated_at?->toIso8601String(),
            'live' => $this->isLive(),

            'opened_by' => $this->whenLoaded('opener', fn () => $this->opener?->username),
            'closed_by' => $this->whenLoaded('closer', fn () => $this->closer?->username),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee === null ? null : [
                'id' => $this->assignee->id,
                'username' => $this->assignee->username,
            ]),

            'subjects' => $this->whenLoaded('subjects', fn () => $this->subjects->map(fn (Subject $s) => [
                'id' => $s->id,
                'username' => $s->username,
                'central_id' => $s->mw_central_id,
                'standing' => $s->standing,
                'banned' => $s->banned,
                'unresolved' => $s->mw_central_id === null,
                'role' => $s->pivot->role,
                'note' => $s->pivot->note,
            ])->all()),

            'cases' => $this->whenLoaded('cases', fn () => $this->cases->map(fn (SafetyCase $c) => [
                'id' => $c->id,
                'reference' => $c->reference,
                'type' => $c->type,
                'subject' => $c->subject_line,
                'status' => $c->status,
                'filed' => $c->created_at?->toIso8601String(),
                'anonymous' => $c->anonymous,
            ])->all()),

            'sanctions' => $this->whenLoaded('sanctions', fn () => $this->sanctions->map(fn (Sanction $s) => [
                'id' => $s->id,
                'reference' => $s->reference,
                'label' => $s->label,
                'type' => $s->type,
                'account' => $s->subject?->username,
                'subject_id' => $s->subject_id,
                'issued' => $s->issued_at?->toIso8601String(),
                'expires' => $s->expires_at?->toIso8601String(),
                'active' => $s->isInForce(),
                'push_state' => $s->push_state,
                'push_error' => $s->push_error,
            ])->all()),

            'removals' => $this->whenLoaded('dataRemovals', fn () => $this->dataRemovals->map(fn (DataRemoval $r) => [
                'id' => $r->id,
                'reference' => $r->reference,
                'state' => $r->state,
                'state_label' => $r->label(),
                'account' => $r->previous_username,
            ])->all()),

            'notes' => $this->whenLoaded('notes', fn () => $this->notes->map(fn (InvestigationNote $n) => [
                'id' => $n->id,
                'kind' => $n->kind,
                'author' => $n->author?->username,
                'body' => $n->body,
                'date' => $n->created_at?->toIso8601String(),
            ])->all()),

            'counts' => [
                'cases' => $this->whenCounted('cases'),
                'sanctions' => $this->whenCounted('sanctions'),
                'subjects' => $this->whenCounted('subjects'),
                'notes' => $this->whenCounted('notes'),
            ],
        ];
    }
}
