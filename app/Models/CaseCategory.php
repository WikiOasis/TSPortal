<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseCategory extends Model
{
    protected $table = 'case_categories';

    protected $fillable = [
        'case_id',
        'category',
        'label',
        'group',
        'source_field',
        'is_primary',
    ];

    protected $attributes = [
        'is_primary' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function safetyCase(): BelongsTo
    {
        return $this->belongsTo(SafetyCase::class, 'case_id');
    }

    public static function canonical(string $id): string
    {
        $id = strtolower(trim($id));

        return (string) (config('categories.aliases')[$id] ?? $id);
    }

    public static function groupLabel(?string $group): ?string
    {
        if ($group === null || $group === '') {
            return null;
        }

        return (string) (config('categories.groups')[$group] ?? $group);
    }
}
