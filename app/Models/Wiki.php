<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Wiki extends Model
{
    protected $primaryKey = 'dbname';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'dbname',
        'sitename',
        'url',
        'language',
        'closed',
        'private',
        'deleted',
        'locked',
        'last_seen_at',
    ];

    protected $attributes = [
        'closed' => false,
        'private' => false,
        'deleted' => false,
        'locked' => false,
    ];

    protected function casts(): array
    {
        return [
            'closed' => 'boolean',
            'private' => 'boolean',
            'deleted' => 'boolean',
            'locked' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function scopeActionable(Builder $query): Builder
    {
        return $query->where('deleted', false);
    }

    public function scopeCloseable(Builder $query): Builder
    {
        return $query->where('deleted', false)->where('closed', false);
    }

    public function label(): string
    {
        return $this->sitename !== null && $this->sitename !== ''
            ? sprintf('%s (%s)', $this->sitename, $this->dbname)
            : $this->dbname;
    }
}
