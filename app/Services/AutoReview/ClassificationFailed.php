<?php

declare(strict_types=1);

namespace App\Services\AutoReview;

use RuntimeException;

final class ClassificationFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retry = true)
    {
        parent::__construct($message);
    }
}
