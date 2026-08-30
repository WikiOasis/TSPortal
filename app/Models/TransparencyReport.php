<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Safety\References;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransparencyReport extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED];

    protected $fillable = [
        'reference',
        'title',
        'period_start',
        'period_end',
        'status',
        'threshold',
        'figures',
        'notes',
        'generated_by',
        'generated_at',
        'published_by',
        'published_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'threshold' => 5,
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'figures' => 'array',
            'threshold' => 'integer',
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isEditable(): bool
    {
        return ! $this->isPublished();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public static function nextReference(?string $label = null): string
    {
        return References::allocate('TransparencyReport', $label);
    }
}
