<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Safety\AppealMatch;
use App\Services\Safety\References;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SafetyCase extends Model
{
    use HasFactory;

    protected $table = 'cases';

    public const TYPE_REPORT = 'report';

    public const TYPE_APPEAL = 'appeal';

    public const TYPE_CONTACT = 'contact';

    public const TYPE_DATA = 'data';

    public const TYPES = [self::TYPE_REPORT, self::TYPE_APPEAL, self::TYPE_CONTACT, self::TYPE_DATA];

    public const DATA_ERASURE = 'erasure';

    public const DATA_RECTIFICATION = 'rectification';

    public const DATA_OTHER = 'other';

    public const DATA_KINDS = [
        self::DATA_ERASURE,
        self::DATA_RECTIFICATION,
        self::DATA_OTHER,
    ];

    public const DECISION_APPROVED = 'approved';

    public const DECISION_DECLINED = 'declined';

    public const DECISIONS = [self::DECISION_APPROVED, self::DECISION_DECLINED];

    public const APPEAL_GRANTED = 'granted';

    public const APPEAL_PARTLY_GRANTED = 'partly-granted';

    public const APPEAL_DECLINED = 'declined';

    public const APPEAL_WITHDRAWN = 'withdrawn';

    public const APPEAL_INVALID = 'invalid';

    public const APPEAL_OUTCOMES = [
        self::APPEAL_GRANTED,
        self::APPEAL_PARTLY_GRANTED,
        self::APPEAL_DECLINED,
        self::APPEAL_WITHDRAWN,
        self::APPEAL_INVALID,
    ];

    public const APPEAL_ACCEPTED = [self::APPEAL_GRANTED, self::APPEAL_PARTLY_GRANTED];

    public const APPEAL_ON_THE_MERITS = [
        self::APPEAL_GRANTED,
        self::APPEAL_PARTLY_GRANTED,
        self::APPEAL_DECLINED,
    ];

    public const APPEAL_UNDOES_ACTION = [self::APPEAL_GRANTED];

    public const DATA_KIND_SYNONYMS = [
        'erase' => self::DATA_ERASURE,
        'erasure' => self::DATA_ERASURE,
        'delete' => self::DATA_ERASURE,
        'deletion' => self::DATA_ERASURE,
        'removal' => self::DATA_ERASURE,
        'removepii' => self::DATA_ERASURE,
        'rectify' => self::DATA_RECTIFICATION,
        'rectification' => self::DATA_RECTIFICATION,
        'correct' => self::DATA_RECTIFICATION,
        'correction' => self::DATA_RECTIFICATION,
    ];

    public const STATUS_RECEIVED = 'received';

    public const STATUS_IN_REVIEW = 'in-review';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_ACTION_TAKEN = 'action-taken';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_IN_REVIEW,
        self::STATUS_INVESTIGATING,
        self::STATUS_ACTION_TAKEN,
        self::STATUS_CLOSED,
        self::STATUS_REJECTED,
        self::STATUS_DUPLICATE,
    ];

    public const OPEN_STATUSES = [self::STATUS_RECEIVED, self::STATUS_IN_REVIEW, self::STATUS_INVESTIGATING];

    public const CLOSED_STATUSES = [self::STATUS_CLOSED, self::STATUS_REJECTED, self::STATUS_DUPLICATE];

    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_LOW = 'low';

    public const PRIORITIES = [
        self::PRIORITY_URGENT,
        self::PRIORITY_HIGH,
        self::PRIORITY_NORMAL,
        self::PRIORITY_LOW,
    ];

    protected $fillable = [
        'reference',
        'type',
        'flow',
        'category',
        'category_group',
        'data_kind',
        'data_decision',
        'data_decision_note',
        'data_decided_by',
        'data_decided_at',
        'subject_line',
        'summary',
        'status',
        'priority',
        'threat_to_life',
        'automated',
        'anonymous',
        'reporter_subject_id',
        'assigned_to',
        'investigation_id',
        'wiki',
        'answers',
        'about',
        'sanction_id',
        'appeal_link_source',
        'appeal_link_confidence',
        'appeal_link_notes',
        'appeal_outcome',
        'appeal_outcome_note',
        'appeal_decided_by',
        'appeal_decided_at',
        'closed_at',
        'resolution',
        'duplicate_of_id',
        'duplicate_note',
        'duplicate_marked_by',
        'duplicate_marked_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_RECEIVED,
        'priority' => self::PRIORITY_NORMAL,
        'threat_to_life' => false,
        'automated' => false,
        'anonymous' => false,
    ];

    protected function casts(): array
    {
        return [
            'anonymous' => 'boolean',
            'threat_to_life' => 'boolean',
            'automated' => 'boolean',
            'answers' => 'array',
            'about' => 'array',
            'closed_at' => 'datetime',
            'synced_at' => 'datetime',
            'data_decided_at' => 'datetime',
            'appeal_decided_at' => 'datetime',
            'duplicate_marked_at' => 'datetime',
            'appeal_link_notes' => 'array',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'reporter_subject_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'data_decided_by');
    }

    public function appealDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appeal_decided_by');
    }

    public function sanction(): BelongsTo
    {
        return $this->belongsTo(Sanction::class);
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_id');
    }

    public function duplicateMarker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'duplicate_marked_by');
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'case_subject', 'case_id', 'subject_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function categories(): HasMany
    {
        return $this->hasMany(CaseCategory::class, 'case_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CaseComment::class, 'case_id');
    }

    public function publicComments(): HasMany
    {
        return $this->comments()->where('visibility', CaseComment::VISIBILITY_PUBLIC);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'case_id');
    }

    public function sanctionsIssued(): HasMany
    {
        return $this->hasMany(Sanction::class, 'case_id');
    }

    public function automatedReview(): HasOne
    {
        return $this->hasOne(AutomatedReview::class, 'case_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isDuplicate(): bool
    {
        return $this->status === self::STATUS_DUPLICATE || $this->duplicate_of_id !== null;
    }

    public function scopeDuplicates(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DUPLICATE);
    }

    public function isThreatToLife(): bool
    {
        return (bool) $this->threat_to_life;
    }

    public function scopeThreatToLife(Builder $query): Builder
    {
        return $query->where('threat_to_life', true);
    }

    public function isAppeal(): bool
    {
        return $this->type === self::TYPE_APPEAL;
    }

    public function appealDecided(): bool
    {
        return $this->appeal_outcome !== null;
    }

    public function appealAccepted(): bool
    {
        return in_array((string) $this->appeal_outcome, self::APPEAL_ACCEPTED, true);
    }

    public function appealLinkNeedsChecking(): bool
    {
        return $this->isAppeal() && in_array(
            (string) $this->appeal_link_confidence,
            AppealMatch::NEEDS_CHECKING,
            true,
        );
    }

    public function scopeAwaitingAppealDecision(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_APPEAL)->whereNull('appeal_outcome');
    }

    public function isDataRequest(): bool
    {
        return $this->type === self::TYPE_DATA;
    }

    public function dataRequestOutstanding(): bool
    {
        if (! $this->isDataRequest()) {
            return false;
        }

        if ($this->data_decision === null) {
            return true;
        }

        if ($this->data_decision === self::DECISION_DECLINED) {
            return false;
        }

        return $this->isOpen();
    }

    public function scopeVisibleTo(Builder $query, Subject $subject): Builder
    {
        return $query->where('reporter_subject_id', $subject->id)->where('anonymous', false);
    }

    public static function nextReference(?string $label = null): string
    {
        return References::allocate('SafetyCase', $label);
    }
}
