<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Safety\Pages;
use App\Services\Safety\References;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sanction extends Model
{
    use HasFactory;

    public const TYPE_NOTE = 'note';

    public const TYPE_WARNING = 'warning';

    public const TYPE_BLOCK = 'block';

    public const TYPE_LOCK = 'lock';

    public const TYPE_WIKI_DELETION = 'wiki-deletion';

    public const TYPE_PAGE_DELETION = 'page-deletion';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_NOTE,
        self::TYPE_WARNING,
        self::TYPE_BLOCK,
        self::TYPE_LOCK,
        self::TYPE_WIKI_DELETION,
        self::TYPE_PAGE_DELETION,
        self::TYPE_OTHER,
    ];

    public const WIKI_SCOPED = [self::TYPE_BLOCK, self::TYPE_WIKI_DELETION];

    public const WIKI_TARGETED = [self::TYPE_WIKI_DELETION, self::TYPE_PAGE_DELETION];

    public const PAGE_TARGETED = [self::TYPE_PAGE_DELETION];

    public const NOTICE_TYPES = [self::TYPE_WARNING, self::TYPE_NOTE];

    public const RECORD_ONLY = [self::TYPE_NOTE, self::TYPE_OTHER];

    public const LABELS = [
        self::TYPE_NOTE => 'Note on file',
        self::TYPE_WARNING => 'Formal warning',
        self::TYPE_BLOCK => 'Block',
        self::TYPE_LOCK => 'Account suspension',
        self::TYPE_WIKI_DELETION => 'Wiki deletion',
        self::TYPE_PAGE_DELETION => 'Page deletion',

        self::TYPE_OTHER => 'Action taken',

        'iban' => 'Interaction ban',
        'partial-block' => 'Partial block',
    ];

    public const PUSH_PENDING = 'pending';

    public const PUSH_PUSHED = 'pushed';

    public const PUSH_MANUAL = 'manual';

    public const PUSH_FAILED = 'failed';

    public const PUSH_QUEUED = 'queued';

    public const PUSH_RECORDED = 'recorded';

    public const PUSH_PARTIAL = 'partial';

    public const PUSH_ACKNOWLEDGED = 'acknowledged';

    public const NEEDS_A_PERSON = [self::PUSH_MANUAL, self::PUSH_PARTIAL, self::PUSH_FAILED];

    protected $fillable = [
        'reference',
        'subject_id',
        'case_id',
        'investigation_id',
        'prompted_by_id',
        'type',
        'label',
        'scope',
        'wikis',
        'pages',
        'reason',
        'internal_reason',
        'reason_category',
        'issued_at',
        'expires_at',
        'active',
        'appealable',
        'issued_by',
        'lifted_by',
        'lifted_at',
        'lift_reason',
        'push_state',
        'acknowledged_by',
        'acknowledged_at',
        'acknowledgement_note',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'lifted_at' => 'datetime',
            'pushed_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'active' => 'boolean',
            'appealable' => 'boolean',
            'wikis' => 'array',
            'pages' => 'array',
            'push_result' => 'array',
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

    public function promptedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prompted_by_id');
    }

    public function notices(): HasMany
    {
        return $this->hasMany(self::class, 'prompted_by_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function lifter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isInForce(): bool
    {
        return $this->active && ! $this->hasExpired();
    }

    public function wikiAction(): string
    {
        return match ($this->type) {
            self::TYPE_LOCK => 'lock',
            self::TYPE_WARNING => 'warn',
            self::TYPE_NOTE => 'note',
            self::TYPE_BLOCK => 'block',
            self::TYPE_WIKI_DELETION => 'delete-wiki',
            self::TYPE_PAGE_DELETION => 'delete-page',
            default => $this->type,
        };
    }

    public const FANNED_OUT = ['block', 'unblock', 'delete-page', 'undelete-page'];

    public function reasonCategoryLabel(): ?string
    {
        if ($this->reason_category === null) {
            return null;
        }

        return config('categories.action_reasons.'.$this->reason_category.'.label')
            ?? $this->reason_category;
    }

    public function isRecordOnly(): bool
    {
        return in_array($this->type, self::RECORD_ONLY, true);
    }

    public function isWikiScoped(): bool
    {
        return in_array($this->type, self::WIKI_SCOPED, true);
    }

    public function isWikiTargeted(): bool
    {
        return in_array($this->type, self::WIKI_TARGETED, true);
    }

    public function isPageTargeted(): bool
    {
        return in_array($this->type, self::PAGE_TARGETED, true);
    }

    public function whereItApplies(): string
    {
        if ($this->isPageTargeted() && $this->pages !== null && $this->pages !== []) {
            return Pages::describe($this->pages);
        }

        if ($this->isWikiScoped() && $this->wikis !== null && $this->wikis !== []) {
            return implode(', ', $this->wikis);
        }

        if ($this->scope !== null && $this->scope !== '') {
            return $this->scope;
        }

        return 'Everywhere on the farm';
    }

    public static function nextReference(?string $label = null): string
    {
        return References::allocate('Sanction', $label);
    }
}
