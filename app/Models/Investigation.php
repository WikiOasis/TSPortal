<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investigation extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_MONITORING = 'monitoring';

    public const STATUS_CONCLUDED = 'concluded';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_MONITORING,
        self::STATUS_CONCLUDED,
        self::STATUS_CLOSED,
    ];

    public const LIVE_STATUSES = [self::STATUS_OPEN, self::STATUS_MONITORING];

    public const OUTCOME_NO_ACTION = 'no-action';

    public const OUTCOME_WARNED = 'warned';

    public const OUTCOME_RESTRICTED = 'restricted';

    public const OUTCOME_SUSPENDED = 'suspended';

    public const OUTCOME_REFERRED = 'referred';

    public const OUTCOME_UNFOUNDED = 'unfounded';

    public const OUTCOME_INSUFFICIENT = 'insufficient-evidence';

    public const OUTCOMES = [
        self::OUTCOME_NO_ACTION,
        self::OUTCOME_WARNED,
        self::OUTCOME_RESTRICTED,
        self::OUTCOME_SUSPENDED,
        self::OUTCOME_REFERRED,
        self::OUTCOME_UNFOUNDED,
        self::OUTCOME_INSUFFICIENT,
    ];

    protected $fillable = [
        'reference',
        'title',
        'premise',
        'status',
        'priority',
        'findings',
        'outcome',
        'outcome_disclosable',
        'opened_by',
        'assigned_to',
        'closed_by',
        'opened_at',
        'closed_at',
        'review_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
        'priority' => 'normal',
        'outcome_disclosable' => false,
    ];

    protected function casts(): array
    {
        return [
            'outcome_disclosable' => 'boolean',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'review_at' => 'datetime',
        ];
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'investigation_subject')
            ->withPivot(['role', 'note'])
            ->withTimestamps();
    }

    public function cases(): HasMany
    {
        return $this->hasMany(SafetyCase::class, 'investigation_id');
    }

    public function sanctions(): HasMany
    {
        return $this->hasMany(Sanction::class, 'investigation_id');
    }

    public function dataRemovals(): HasMany
    {
        return $this->hasMany(DataRemoval::class, 'investigation_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(InvestigationNote::class)->orderBy('created_at');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }

    public function scopeDueForReview(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE_STATUSES)
            ->whereNotNull('review_at')
            ->where('review_at', '<=', now());
    }

    public function inferredOutcome(): string
    {
        $types = $this->sanctions()->where('active', true)->pluck('type')->all();

        return match (true) {
            in_array(Sanction::TYPE_LOCK, $types, true) => self::OUTCOME_SUSPENDED,
            (bool) array_intersect($types, [Sanction::TYPE_BLOCK, 'partial-block', 'iban']) => self::OUTCOME_RESTRICTED,
            in_array(Sanction::TYPE_WARNING, $types, true) => self::OUTCOME_WARNED,
            default => self::OUTCOME_NO_ACTION,
        };
    }
}
