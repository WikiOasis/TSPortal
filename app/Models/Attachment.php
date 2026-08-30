<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $fillable = [
        'case_id',
        'name',
        'mime',
        'size',
        'path',
        'disk',
        'checksum',
        'uploaded_at',
        'refused_reason',
        'file_modified_at',
    ];

    protected function casts(): array
    {
        return [
            'file_modified_at' => 'datetime',
            'uploaded_at' => 'datetime',
        ];
    }

    public function safetyCase(): BelongsTo
    {
        return $this->belongsTo(SafetyCase::class, 'case_id');
    }

    public function hasFile(): bool
    {
        return $this->path !== null && $this->path !== '';
    }

    public function wasRefused(): bool
    {
        return ! $this->hasFile() && $this->refused_reason !== null;
    }

    public function isPending(): bool
    {
        return ! $this->hasFile() && $this->refused_reason === null;
    }

    public function state(): string
    {
        return match (true) {
            $this->hasFile() => 'stored',
            $this->wasRefused() => 'refused',
            default => 'pending',
        };
    }
}
