<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalNotification extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'portal_notifications';

    protected $fillable = [
        'user_id', 'actor_id', 'event', 'object_type', 'object_id',
        'reference', 'object_title', 'summary', 'actor_label', 'read_at',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
