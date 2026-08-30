<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CheckUserCheck extends Model
{
    protected $table = 'checkuser_checks';

    public const TARGET_ACCOUNT = 'account';

    public const TARGET_IP = 'ip';

    public const TARGET_RANGE = 'range';

    public const TARGET_NAME = 'name';

    public const TARGET_UNKNOWN = 'unknown';

    public const TARGET_KINDS = [
        self::TARGET_ACCOUNT,
        self::TARGET_IP,
        self::TARGET_RANGE,
        self::TARGET_NAME,
        self::TARGET_UNKNOWN,
    ];

    public const TYPE_LABELS = [
        'ipedits' => 'Edits from an IP',
        'ipusers' => 'Accounts on an IP',
        'ipedits-xff' => 'Edits from an IP (XFF)',
        'ipusers-xff' => 'Accounts on an IP (XFF)',
        'userips' => 'IPs used by an account',
        'useredits' => 'Edits by an account',
        'investigate' => 'Investigate',
    ];

    protected $fillable = [
        'wiki',
        'log_id',
        'checked_at',
        'checker_username',
        'checker_central_id',
        'type',
        'target_kind',
        'target_name',
        'target_fingerprint',
        'reason',
        'reason_given',
        'received_at',
    ];

    protected $attributes = [
        'reason_given' => false,
        'target_kind' => self::TARGET_UNKNOWN,
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'received_at' => 'datetime',
            'reason_given' => 'boolean',
            'log_id' => 'integer',
            'checker_central_id' => 'integer',
        ];
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function targetLabel(): string
    {
        if ($this->target_name !== null && $this->target_name !== '') {
            return $this->target_name;
        }

        return match ($this->target_kind) {
            self::TARGET_IP => 'An IP address ('.$this->shortFingerprint().')',
            self::TARGET_RANGE => 'An IP range ('.$this->shortFingerprint().')',
            self::TARGET_UNKNOWN => 'Not recorded',
            default => 'Withheld ('.$this->shortFingerprint().')',
        };
    }

    private function shortFingerprint(): string
    {
        return $this->target_fingerprint === null
            ? 'no fingerprint'
            : substr($this->target_fingerprint, 0, 8);
    }

    public function scopeUnexplained(Builder $query): Builder
    {
        return $query->where('reason_given', false);
    }

    public function scopeBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->whereBetween('checked_at', [$from, $to]);
    }
}
