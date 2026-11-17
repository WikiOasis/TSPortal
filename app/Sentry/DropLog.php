<?php

namespace App\Sentry;

use Sentry\Logs\Log;

class DropLog
{
    public static function handle(Log $log): ?Log
    {
        return null;
    }
}
