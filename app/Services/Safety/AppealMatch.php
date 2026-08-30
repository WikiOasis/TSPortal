<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\Sanction;

final readonly class AppealMatch
{
    public const SOURCE_STATED = 'stated';

    public const SOURCE_QUOTED = 'quoted';

    public const SOURCE_ONLY = 'only-action';

    public const SOURCE_LATEST = 'latest-action';

    public const SOURCE_STAFF = 'staff';

    public const SOURCE_NONE = 'none';

    public const CERTAIN = 'certain';

    public const LIKELY = 'likely';

    public const GUESS = 'guess';

    public const NONE = 'none';

    public const NEEDS_CHECKING = [self::LIKELY, self::GUESS, self::NONE];

    /**
     * @param  list<array<string, mixed>>  $considered
     * @param  list<string>  $notes
     */
    public function __construct(
        public ?Sanction $sanction,
        public string $source = self::SOURCE_NONE,
        public string $confidence = self::NONE,
        public array $considered = [],
        public array $notes = [],
    ) {}

    public static function nothing(array $considered = [], array $notes = []): self
    {
        return new self(null, self::SOURCE_NONE, self::NONE, $considered, $notes);
    }

    public function linked(): bool
    {
        return $this->sanction !== null;
    }

    public function needsChecking(): bool
    {
        return in_array($this->confidence, self::NEEDS_CHECKING, true);
    }

    public function explanation(): string
    {
        return match ($this->source) {
            self::SOURCE_STATED => 'They gave this reference when they appealed.',
            self::SOURCE_QUOTED => 'This reference appears in what they wrote.',
            self::SOURCE_ONLY => 'This is the only action on their file, so there is nothing else it could be about.',
            self::SOURCE_LATEST => 'They have more than one action on file and this is the most recent. '
                .'It is a guess — check it before deciding.',
            self::SOURCE_STAFF => 'Set by hand.',
            default => 'Nothing here says which action this is about.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        return [
            'explanation' => $this->explanation(),
            'notes' => $this->notes,
            'considered' => $this->considered,
        ];
    }
}
