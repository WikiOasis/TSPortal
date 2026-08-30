<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseComment extends Model
{
    use HasFactory;

    public const AUTHOR_STAFF = 'staff';

    public const AUTHOR_SUBJECT = 'subject';

    public const AUTHOR_SYSTEM = 'system';

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_INTERNAL = 'internal';

    protected $fillable = [
        'case_id',
        'author_type',
        'author_user_id',
        'author_subject_id',
        'author_label',
        'body',
        'visibility',
    ];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }

    public function safetyCase(): BelongsTo
    {
        return $this->belongsTo(SafetyCase::class, 'case_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function subjectAuthor(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'author_subject_id');
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::VISIBILITY_PUBLIC;
    }
}
