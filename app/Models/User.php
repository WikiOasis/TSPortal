<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    public const FLAG_TS = 'ts';

    public const FLAG_USER_MANAGER = 'user-manager';

    public const FLAG_ADMIN = 'admin';

    public const FLAGS = [self::FLAG_TS, self::FLAG_USER_MANAGER, self::FLAG_ADMIN];

    protected $fillable = [
        'mw_central_id',
        'username',
        'real_name',
        'email',
        'flags',
        'granted_flags',
        'mw_groups',
        'active',
        'last_login_at',
    ];

    protected $hidden = ['remember_token'];

    protected $attributes = [
        'active' => true,
        'flags' => '[]',
        'granted_flags' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'flags' => 'array',
            'granted_flags' => 'array',
            'mw_groups' => 'array',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function assignedCases(): HasMany
    {
        return $this->hasMany(SafetyCase::class, 'assigned_to');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CaseComment::class, 'author_user_id');
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags ?? [], true);
    }

    public function isStaff(): bool
    {
        return $this->active && $this->hasFlag(self::FLAG_TS);
    }

    /**
     * @param  list<string>  $groups
     */
    public function syncGroupFlags(array $groups): void
    {
        $derived = [];
        foreach (config('mediawiki.group_flags', []) as $group => $flags) {
            if (in_array($group, $groups, true)) {
                $derived = array_merge($derived, (array) $flags);
            }
        }

        if (in_array($this->username, config('mediawiki.bootstrap_admins', []), true)) {
            $derived = self::FLAGS;
        }

        $this->mw_groups = array_values($groups);
        $this->flags = array_values(array_unique(array_merge(
            $derived,
            $this->granted_flags ?? [],
        )));
    }

    public function publicLabel(): string
    {
        $initial = mb_substr($this->real_name ?: $this->username, 0, 1);
        $surname = trim((string) mb_strstr((string) ($this->real_name ?: ''), ' '));

        return $surname !== ''
            ? sprintf('%s. %s (Trust & Safety)', $initial, $surname)
            : 'Trust & Safety';
    }
}
