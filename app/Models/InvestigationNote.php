<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationNote extends Model
{
    public const KIND_NOTE = 'note';

    public const KIND_FINDING = 'finding';

    public const KIND_DECISION = 'decision';

    public const KIND_CONTACT = 'contact';

    public const KIND_EVIDENCE = 'evidence';

    public const KINDS = [
        self::KIND_NOTE,
        self::KIND_FINDING,
        self::KIND_DECISION,
        self::KIND_CONTACT,
        self::KIND_EVIDENCE,
    ];

    protected $fillable = ['investigation_id', 'author_id', 'kind', 'body'];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
