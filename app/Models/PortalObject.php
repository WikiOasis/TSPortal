<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PortalObject extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_CASE = 'SafetyCase';

    public const TYPE_INVESTIGATION = 'Investigation';

    public const TYPE_SANCTION = 'Sanction';

    public const TYPE_DATA_REMOVAL = 'DataRemoval';

    public const TYPE_TRANSPARENCY = 'TransparencyReport';

    public const ROUTES = [
        self::TYPE_CASE => ['label' => 'Case', 'route' => 'case'],
        self::TYPE_INVESTIGATION => ['label' => 'Investigation', 'route' => 'investigation'],
        self::TYPE_SANCTION => ['label' => 'Action', 'route' => 'sanctions'],
        self::TYPE_DATA_REMOVAL => ['label' => 'Data removal', 'route' => 'data-removals'],
        self::TYPE_TRANSPARENCY => ['label' => 'Transparency report', 'route' => 'transparency-report'],
    ];

    protected $fillable = [
        'reference',
        'year',
        'sequence',
        'object_type',
        'object_id',
        'label',
        'allocated_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'year' => 'integer', 'sequence' => 'integer'];
    }

    public function allocator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    public function object(): MorphTo
    {
        return $this->morphTo(name: 'object', type: 'object_type', id: 'object_id');
    }

    public function objectClass(): ?string
    {
        $class = '\\App\\Models\\'.$this->object_type;

        return class_exists($class) ? $class : null;
    }

    public function typeLabel(): string
    {
        return self::ROUTES[$this->object_type]['label'] ?? $this->object_type;
    }
}
