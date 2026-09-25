<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\InvestigationNote;
use App\Models\InvestigationPage;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Services\Safety\Pages;
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
                'erased' => $s->isErased(),
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
                'wikis' => $s->wikis,
                'pages' => $s->pages,
                'where' => $s->whereItApplies(),
                'prompted_by' => $s->relationLoaded('promptedBy') ? $s->promptedBy?->reference : null,
            ])->all()),

            'pages' => $this->whenLoaded('pages', fn () => $this->pagesOnFile()),

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

    /**
     * @return list<array<string, mixed>>
     */
    private function pagesOnFile(): array
    {
        $deleted = [];

        if ($this->relationLoaded('sanctions')) {
            foreach ($this->sanctions as $sanction) {
                if (! $sanction->isPageTargeted() || ! $sanction->isInForce()) {
                    continue;
                }

                foreach ((array) ($sanction->pages ?? []) as $page) {
                    $deleted[Pages::key($page)] ??= [
                        'id' => $sanction->id,
                        'reference' => $sanction->reference,
                        'push_state' => $sanction->push_state,
                    ];
                }
            }
        }

        $reported = [];

        if ($this->relationLoaded('cases')) {
            foreach ($this->cases as $case) {
                $accounts = $case->relationLoaded('subjects')
                    ? $case->subjects
                        ->filter(fn (Subject $s) => $s->pivot->role === 'reported')
                        ->map(fn (Subject $s) => ['id' => $s->id, 'username' => $s->username])
                        ->values()
                        ->all()
                    : [];

                foreach ((array) ($case->pages ?? []) as $page) {
                    $key = Pages::key($page);
                    $reported[$key]['cases'][$case->id] = ['id' => $case->id, 'reference' => $case->reference];

                    foreach ($accounts as $account) {
                        $reported[$key]['accounts'][$account['username']] = $account;
                    }
                }
            }
        }

        return $this->pages->sortBy(['wiki', 'title'])->values()->map(function (InvestigationPage $page) use ($deleted, $reported) {
            $key = $page->key();
            $accounts = $reported[$key]['accounts'] ?? [];
            $editors = [];

            foreach ((array) ($page->editors ?? []) as $editor) {
                $name = (string) ($editor['username'] ?? '');
                $editors[$name] = $editor + [
                    'reported' => isset($accounts[$name]),
                    'subject_id' => $accounts[$name]['id'] ?? null,
                ];
            }

            foreach ($accounts as $name => $account) {
                $editors[$name] ??= [
                    'username' => $name,
                    'registered' => true,
                    'edits' => null,
                    'first' => null,
                    'last' => null,
                    'creator' => $page->creator === $name,
                    'reported' => true,
                    'subject_id' => $account['id'],
                ];
            }

            $cases = $reported[$key]['cases'] ?? [];
            if ($page->safetyCase !== null) {
                $cases[$page->safetyCase->id] ??= ['id' => $page->safetyCase->id, 'reference' => $page->safetyCase->reference];
            }

            return [
                'id' => $page->id,
                'wiki' => $page->wiki,
                'title' => $page->title,
                'note' => $page->note,
                'cases' => array_values($cases),
                'exists' => $page->exists,
                'previously_deleted' => $page->previously_deleted,
                'revisions' => $page->revisions,
                'creator' => $page->creator,
                'editors' => array_values($editors),
                'info' => [
                    'fetched' => $page->info_fetched_at?->toIso8601String(),
                    'error' => $page->info_error,
                ],
                'deleted' => $deleted[$key] ?? null,
            ];
        })->all();
    }
}
