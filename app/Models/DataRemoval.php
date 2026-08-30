<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataRemoval extends Model
{
    public const STATE_REQUESTED = 'requested';

    public const STATE_APPROVED = 'approved';

    public const STATE_RENAMING = 'renaming';

    public const STATE_RENAMED = 'renamed';

    public const STATE_SCRUBBING = 'scrubbing';

    public const STATE_DONE = 'done';

    public const STATE_REFUSED = 'refused';

    public const STATE_FAILED = 'failed';

    public const STATES = [
        self::STATE_REQUESTED,
        self::STATE_APPROVED,
        self::STATE_RENAMING,
        self::STATE_RENAMED,
        self::STATE_SCRUBBING,
        self::STATE_DONE,
        self::STATE_REFUSED,
        self::STATE_FAILED,
    ];

    public const IN_FLIGHT = [self::STATE_RENAMING, self::STATE_RENAMED, self::STATE_SCRUBBING];

    public const LABELS = [
        self::STATE_REQUESTED => 'Waiting for a second person',
        self::STATE_APPROVED => 'Approved, not yet sent',
        self::STATE_RENAMING => 'Renaming on the wikis',
        self::STATE_RENAMED => 'Renamed, ready to scrub',
        self::STATE_SCRUBBING => 'Removing data',
        self::STATE_DONE => 'Done',
        self::STATE_REFUSED => 'Refused',
        self::STATE_FAILED => 'Could not be completed',
    ];

    protected $fillable = [
        'reference',
        'subject_id',
        'case_id',
        'investigation_id',
        'target_username',
        'previous_username',
        'state',
        'legal_basis',
        'reason',
        'wikis',
        'requested_by',
        'approved_by',
        'approved_at',
        'refusal_reason',
        'result',
        'error',
        'next_attempt_at',
        'last_problem',
        'renamed_at',
        'completed_at',
    ];

    protected $attributes = ['state' => self::STATE_REQUESTED];

    protected function casts(): array
    {
        return [
            'wikis' => 'array',
            'result' => 'array',
            'approved_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'renamed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function safetyCase(): BelongsTo
    {
        return $this->belongsTo(SafetyCase::class, 'case_id');
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function label(): string
    {
        return self::LABELS[$this->state] ?? $this->state;
    }

    public function isApproved(): bool
    {
        return $this->approved_by !== null && ! in_array(
            $this->state,
            [self::STATE_REQUESTED, self::STATE_REFUSED],
            true,
        );
    }

    public function isFinished(): bool
    {
        return in_array($this->state, [self::STATE_DONE, self::STATE_REFUSED], true);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNotIn('state', [self::STATE_DONE, self::STATE_REFUSED]);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query
            ->whereIn('state', [
                self::STATE_APPROVED,
                self::STATE_RENAMING,
                self::STATE_RENAMED,
            ])
            ->where(fn (Builder $q) => $q
                ->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now()));
    }

    public function backOff(string $problem): void
    {
        $waited = $this->attemptsMade() + 1;

        $this->forceFill([
            'result' => array_merge($this->result ?? [], ['waited' => $waited]),
            'last_problem' => mb_substr($problem, 0, 2000),
            'next_attempt_at' => now()->addSeconds(min(600, 30 * (2 ** min($waited, 6)))),
        ])->save();
    }

    public function resetBackOff(): void
    {
        $result = $this->result ?? [];
        unset($result['waited']);

        $this->forceFill([
            'result' => $result,
            'next_attempt_at' => null,
            'last_problem' => null,
        ])->save();
    }

    public function attemptsMade(): int
    {
        return (int) (($this->result ?? [])['waited'] ?? 0);
    }

    public function isWaiting(): bool
    {
        return $this->next_attempt_at !== null
            && $this->next_attempt_at->isFuture()
            && $this->state !== self::STATE_FAILED;
    }

    public static function usernameFor(): string
    {
        $prefix = (string) config('mediawiki.pii.username_prefix', 'WikiOasisGDPR_');

        return $prefix.bin2hex(random_bytes(24));
    }
}
