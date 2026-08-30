<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Jobs\SyncToWiki;
use App\Models\CaseComment;
use App\Models\OutboundEvent;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;

final class WikiSync
{
    public function pushCase(SafetyCase $case): ?OutboundEvent
    {
        if ($case->anonymous || $case->reporter_subject_id === null) {
            return null;
        }

        $case->loadMissing('reporter', 'categories', 'sanction');

        return $this->queue([
            'event' => OutboundEvent::CASE_UPSERT,
            'wiki' => $case->wiki,
            'payload' => [
                'reference' => $case->reference,
                'type' => $case->type,
                'central_id' => $case->reporter?->mw_central_id,
                'username' => $case->reporter?->wikiName(),
                'subject' => $case->subject_line,
                'summary' => $case->summary,
                'status' => $case->status,
                'about' => $case->about ?? [],

                'categories' => $case->categories
                    ->map(fn ($category) => [
                        'id' => $category->category,
                        'label' => $category->label,
                    ])->values()->all(),

                'appeal' => $case->isAppeal() ? $this->appealPayload($case) : null,

                'filed' => $case->created_at?->toIso8601String(),
                'updated' => $case->updated_at?->toIso8601String(),
                'notify' => true,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function appealPayload(SafetyCase $case): array
    {
        $action = $case->sanction;

        return [
            'action' => $action === null ? null : [
                'reference' => $action->reference,
                'label' => $action->label,
                'type' => $action->type,
                'reason' => $action->reason,
                'issued' => $action->issued_at?->toIso8601String(),
                'expires' => $action->expires_at?->toIso8601String(),
                'in_force' => $action->isInForce(),
                'lifted' => $action->lifted_at?->toIso8601String(),
                'where' => $action->whereItApplies(),
            ],

            'outcome' => $case->appeal_outcome,
            'decided' => $case->appeal_decided_at?->toIso8601String(),
        ];
    }

    public function pushComment(CaseComment $comment): ?OutboundEvent
    {
        if (! $comment->isPublic()) {
            return null;
        }

        $comment->loadMissing('safetyCase.reporter');
        $case = $comment->safetyCase;

        if ($case === null || $case->anonymous || $case->reporter_subject_id === null) {
            return null;
        }

        return $this->queue([
            'event' => OutboundEvent::COMMENT_ADD,
            'wiki' => $case->wiki,
            'payload' => [
                'reference' => $case->reference,
                'comment_id' => $comment->id,
                'central_id' => $case->reporter?->mw_central_id,
                'username' => $case->reporter?->wikiName(),
                'author' => $comment->author_type === CaseComment::AUTHOR_SUBJECT ? 'you' : 'staff',
                'author_label' => $comment->author_label,
                'body' => $comment->body,
                'date' => $comment->created_at?->toIso8601String(),
                'notify' => $comment->author_type !== CaseComment::AUTHOR_SUBJECT,
            ],
        ]);
    }

    public function pushSanction(Sanction $sanction): ?OutboundEvent
    {
        $sanction->loadMissing('subject');

        if ($sanction->subject === null) {
            return null;
        }

        return $this->queue([
            'event' => OutboundEvent::SANCTION_UPSERT,
            'payload' => [
                'reference' => $sanction->reference,
                'central_id' => $sanction->subject->mw_central_id,
                'username' => $sanction->subject->wikiName(),
                'type' => $sanction->type,
                'label' => $sanction->label,
                'scope' => $sanction->whereItApplies(),
                'wikis' => $sanction->wikis,
                'reason' => $sanction->reason,
                'issued' => $sanction->issued_at?->toIso8601String(),
                'expires' => $sanction->expires_at?->toIso8601String(),
                'active' => $sanction->isInForce(),
                'appealable' => $sanction->appealable && $sanction->isInForce(),
                'banned' => $sanction->type === Sanction::TYPE_LOCK && $sanction->isInForce(),
                'standing' => $sanction->subject->standing,
                'notify' => true,
            ],
        ]);
    }

    public function pushStanding(Subject $subject): OutboundEvent
    {
        return $this->queue([
            'event' => OutboundEvent::NOTIFY,
            'payload' => [
                'kind' => 'standing',
                'central_id' => $subject->mw_central_id,
                'username' => $subject->wikiName(),
                'standing' => $subject->standing,
                'banned' => $subject->banned,
                'notify' => false,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function queue(array $attributes): OutboundEvent
    {
        $event = OutboundEvent::create($attributes);

        SyncToWiki::dispatch()->afterCommit();

        return $event;
    }
}
