<?php

declare(strict_types=1);

namespace App\Services\MediaWiki;

final readonly class DrainReport
{
    /**
     * @param  int  $sent
     * @param  int  $failed
     * @param  bool  $halted
     * @param  list<string>  $problems
     */
    public function __construct(
        public int $sent = 0,
        public int $failed = 0,
        public bool $halted = false,
        public array $problems = [],
    ) {}

    public static function disabled(): self
    {
        return new self(halted: true);
    }

    public function attempted(): int
    {
        return $this->sent + $this->failed;
    }
}
