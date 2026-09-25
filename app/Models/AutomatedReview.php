<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomatedReview extends Model
{
    public const STATE_PENDING = 'pending';

    public const STATE_QUEUED = 'queued';

    public const STATE_DONE = 'done';

    public const STATE_FAILED = 'failed';

    public const STATE_MERGED = 'merged';

    public const STATES = [
        self::STATE_PENDING,
        self::STATE_QUEUED,
        self::STATE_DONE,
        self::STATE_FAILED,
        self::STATE_MERGED,
    ];

    public const BUCKET_URGENT = 'urgent';

    public const BUCKET_REVIEW = 'review';

    public const BUCKET_UNLIKELY = 'unlikely';

    public const BUCKETS = [self::BUCKET_URGENT, self::BUCKET_REVIEW, self::BUCKET_UNLIKELY];

    public const BUCKET_LABELS = [
        self::BUCKET_URGENT => 'Needs review quickly',
        self::BUCKET_REVIEW => 'Needs review',
        self::BUCKET_UNLIKELY => 'Unlikely to need review',
    ];

    public const ACTIONS = ['close-no-action', 'check-revert', 'investigate', 'escalate'];

    protected $fillable = [
        'case_id',
        'state',
        'bucket',
        'staff_bucket',
        'confidence',
        'page_summary',
        'change_summary',
        'reason',
        'signals',
        'suggested_action',
        'wiki',
        'page_title',
        'author',
        'revision_id',
        'scan_mode',
        'evidence_source',
        'evidence',
        'model',
        'attempts',
        'error',
        'tokens',
        'cost',
        'priority_applied',
        'queued_at',
        'classified_at',
        'overridden_by',
        'overridden_at',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $attributes = [
        'state' => self::STATE_PENDING,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'signals' => 'array',
            'evidence' => 'array',
            'revision_id' => 'integer',
            'attempts' => 'integer',
            'tokens' => 'integer',
            'cost' => 'float',
            'queued_at' => 'datetime',
            'classified_at' => 'datetime',
            'overridden_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(SafetyCase::class, 'case_id');
    }

    public function overrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function effectiveBucket(): ?string
    {
        return $this->staff_bucket ?? $this->bucket;
    }

    public function isClassified(): bool
    {
        return $this->state === self::STATE_DONE && $this->bucket !== null;
    }

    public function scopeInBucket(Builder $query, string $bucket): Builder
    {
        return $query->whereRaw('COALESCE(automated_reviews.staff_bucket, automated_reviews.bucket) = ?', [$bucket]);
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereIn('automated_reviews.state', [self::STATE_PENDING, self::STATE_QUEUED]);
    }

    public static function priorityFor(string $bucket): string
    {
        return match ($bucket) {
            self::BUCKET_URGENT => SafetyCase::PRIORITY_URGENT,
            self::BUCKET_UNLIKELY => SafetyCase::PRIORITY_LOW,
            default => SafetyCase::PRIORITY_HIGH,
        };
    }
}
