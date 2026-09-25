<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationPage extends Model
{
    protected $fillable = [
        'investigation_id',
        'wiki',
        'title',
        'case_id',
        'added_by',
        'note',
        'exists',
        'previously_deleted',
        'page_id',
        'latest_revision',
        'revisions',
        'creator',
        'editors',
        'info_fetched_at',
        'info_error',
    ];

    protected function casts(): array
    {
        return [
            'exists' => 'boolean',
            'previously_deleted' => 'boolean',
            'editors' => 'array',
            'info_fetched_at' => 'datetime',
        ];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function safetyCase(): BelongsTo
    {
        return $this->belongsTo(SafetyCase::class, 'case_id');
    }

    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /** @return array{wiki: string, title: string} */
    public function asPage(): array
    {
        return ['wiki' => $this->wiki, 'title' => $this->title];
    }

    public function key(): string
    {
        return $this->wiki.'|'.$this->title;
    }
}
