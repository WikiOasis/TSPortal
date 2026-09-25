<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AutomatedReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AutomatedReview
 */
class AutomatedReviewResource extends JsonResource
{
    private bool $withEvidence = false;

    public function withEvidence(bool $include = true): self
    {
        $this->withEvidence = $include;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $evidence = (array) ($this->evidence ?? []);

        return [
            'state' => $this->state,
            'bucket' => $this->effectiveBucket(),
            'model_bucket' => $this->bucket,
            'staff_bucket' => $this->staff_bucket,
            'overridden_by' => $this->whenLoaded('overrider', fn () => $this->overrider?->username),
            'overridden_at' => $this->overridden_at?->toIso8601String(),
            'confirmed_by' => $this->whenLoaded('confirmer', fn () => $this->confirmer?->username),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'confidence' => $this->confidence,
            'page_summary' => $this->page_summary,
            'change_summary' => $this->change_summary,
            'reason' => $this->reason,
            'signals' => $this->signals ?? [],
            'suggested_action' => $this->suggested_action,
            'wiki' => $this->wiki,
            'page_title' => $this->page_title,
            'author' => $this->author,
            'revision_id' => $this->revision_id,
            'scan_mode' => $this->scan_mode,
            'model' => $this->model,
            'error' => $this->error,
            'attempts' => $this->attempts,
            'classified_at' => $this->classified_at?->toIso8601String(),

            'source' => $this->evidence_source,
            'facts' => [
                'reverted' => $evidence['reverted'] ?? null,
                'current' => $evidence['current'] ?? null,
                'deleted' => ! empty($evidence['page_missing']) || ! empty($evidence['revision_missing']),
                'hidden' => ! empty($evidence['content_hidden']),
                'created_page' => ! empty($evidence['page_created']),
                'problem' => $evidence['problem'] ?? null,
                'author' => $evidence['author'] ?? null,
            ],
            'links' => [
                'revision' => $evidence['revision_url'] ?? null,
                'page' => $evidence['page_url'] ?? null,
                'contributions' => $evidence['contributions_url'] ?? null,
            ],

            'evidence' => $this->when($this->withEvidence, fn () => [
                'diff' => $evidence['diff'] ?? null,
                'content' => $evidence['content'] ?? null,
                'truncated' => (bool) ($evidence['truncated'] ?? false),
                'revision' => $evidence['revision'] ?? null,
            ]),
        ];
    }
}
