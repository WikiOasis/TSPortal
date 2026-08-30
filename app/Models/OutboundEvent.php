<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OutboundEvent extends Model
{
    public const CASE_UPSERT = 'case.upsert';

    public const COMMENT_ADD = 'comment.add';

    public const SANCTION_UPSERT = 'sanction.upsert';

    public const NOTIFY = 'notify';

    protected $fillable = ['event', 'wiki', 'payload', 'next_attempt_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->whereNull('delivered_at')
            ->where(fn (Builder $q) => $q
                ->where('next_attempt_at', '<=', now())
                ->orWhere(fn (Builder $fresh) => $fresh
                    ->whereNull('next_attempt_at')
                    ->where('attempts', 0)))
            ->orderBy('id');
    }

    public function backOff(string $error): void
    {
        $this->attempts++;
        $this->last_error = mb_substr($error, 0, 2000);
        $this->next_attempt_at = $this->attempts >= 12
            ? null
            : now()->addSeconds(min(3600, 30 * (2 ** min($this->attempts, 7))));
        $this->save();
    }

    public function markDelivered(): void
    {
        $this->delivered_at = now();
        $this->last_error = null;
        $this->save();
    }

    public function isStuck(): bool
    {
        return $this->delivered_at === null && $this->next_attempt_at === null && $this->attempts > 0;
    }
}
