<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasFactory;

    public const STANDING_GOOD = 'good';

    public const STANDING_RESTRICTED = 'restricted';

    public const STANDING_SUSPENDED = 'suspended';

    protected $fillable = [
        'mw_central_id',
        'username',
        'username_key',
        'wiki_username',
        'erased_at',
        'email',
        'email_verified',
        'standing',
        'banned',
        'registered_at',
        'notes',
    ];

    protected $attributes = [
        'standing' => self::STANDING_GOOD,
        'banned' => false,
        'email_verified' => false,
    ];

    protected function casts(): array
    {
        return [
            'email_verified' => 'boolean',
            'banned' => 'boolean',
            'registered_at' => 'datetime',
            'erased_at' => 'datetime',
        ];
    }

    public function isErased(): bool
    {
        return $this->erased_at !== null;
    }

    public function needsCentralId(): bool
    {
        return $this->mw_central_id === null && ! $this->isErased();
    }

    public function attachCentralId(int $centralId): bool
    {
        if ($centralId <= 0 || $this->mw_central_id === $centralId) {
            return $this->mw_central_id === $centralId;
        }

        $taken = static::query()
            ->where('mw_central_id', $centralId)
            ->when($this->exists, fn ($q) => $q->whereKeyNot($this->getKey()))
            ->exists();

        if ($taken) {
            return false;
        }

        $this->mw_central_id = $centralId;
        $this->save();

        return true;
    }

    public function wikiName(): ?string
    {
        return $this->wiki_username !== null && $this->wiki_username !== ''
            ? $this->wiki_username
            : $this->username;
    }

    public function scopeErasedBefore(Builder $query, \DateTimeInterface $when): Builder
    {
        return $query->whereNotNull('erased_at')->where('erased_at', '<', $when);
    }

    public function sanctions(): HasMany
    {
        return $this->hasMany(Sanction::class);
    }

    public function filedCases(): HasMany
    {
        return $this->hasMany(SafetyCase::class, 'reporter_subject_id');
    }

    public function cases(): BelongsToMany
    {
        return $this->belongsToMany(SafetyCase::class, 'case_subject', 'subject_id', 'case_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function investigations(): BelongsToMany
    {
        return $this->belongsToMany(Investigation::class, 'investigation_subject')
            ->withPivot(['role', 'note'])
            ->withTimestamps();
    }

    public function dataRemovals(): HasMany
    {
        return $this->hasMany(DataRemoval::class);
    }

    public static function key(string $username): string
    {
        return mb_strtolower(self::normalise($username));
    }

    public static function forUsername(string $username, ?int $centralId = null): self
    {
        $username = self::normalise($username);
        $centralId = self::usableCentralId($centralId);

        $subject = $centralId !== null
            ? static::query()->where('mw_central_id', $centralId)->first()
            : null;

        $subject ??= static::query()->where('username_key', static::key($username))->first();

        $subject ??= static::query()->where('wiki_username', $username)->first();

        if ($subject === null) {
            $subject = new static;
        }

        if ($subject->exists && $subject->isErased()) {
            if ($username !== '') {
                $subject->wiki_username = $username;
            }
        } else {
            $subject->username = $username !== '' ? $username : $subject->username;
            $subject->username_key = static::key($subject->username);
        }

        if ($centralId !== null && $subject->mw_central_id !== $centralId) {
            $taken = static::query()
                ->where('mw_central_id', $centralId)
                ->when($subject->exists, fn ($q) => $q->whereKeyNot($subject->getKey()))
                ->exists();

            if (! $taken) {
                $subject->mw_central_id = $centralId;
            }
        }

        $subject->save();

        return $subject;
    }

    public static function normalise(string $username): string
    {
        $username = trim(str_replace('_', ' ', $username));
        $username = preg_replace('/\s+/u', ' ', $username) ?? $username;

        return $username === ''
            ? ''
            : mb_strtoupper(mb_substr($username, 0, 1)).mb_substr($username, 1);
    }

    private static function usableCentralId(?int $centralId): ?int
    {
        return $centralId !== null && $centralId > 0 ? $centralId : null;
    }

    public function refreshStanding(): void
    {
        $active = $this->sanctions()->where('active', true)->get();

        $banned = $active->contains(fn (Sanction $s) => $s->type === Sanction::TYPE_LOCK);

        $this->banned = $banned;
        $this->standing = match (true) {
            $banned => self::STANDING_SUSPENDED,
            $active->isNotEmpty() => self::STANDING_RESTRICTED,
            default => self::STANDING_GOOD,
        };
        $this->save();
    }
}
