<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InlineImage extends Model
{
    protected $fillable = [
        'uuid',
        'investigation_id',
        'case_id',
        'uploaded_by',
        'name',
        'mime',
        'size',
        'disk',
        'path',
        'checksum',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function safetyCase(): BelongsTo
    {
        return $this->belongsTo(SafetyCase::class, 'case_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function token(): string
    {
        $alt = str_replace([']', '[', "\n", "\r"], '', $this->name);

        return sprintf('![%s](image:%s)', $alt, $this->uuid);
    }
}
