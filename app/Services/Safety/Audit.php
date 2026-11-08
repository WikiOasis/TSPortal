<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\AuditLog;
use App\Services\Notifications\Notifier;
use App\Services\Slack\SlackNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

final class Audit
{
    private static bool $system = false;

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function asSystem(callable $work): mixed
    {
        $before = self::$system;
        self::$system = true;

        try {
            return $work();
        } finally {
            self::$system = $before;
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function log(string $action, ?Model $target = null, array $meta = [], ?string $actorLabel = null): AuditLog
    {
        $log = AuditLog::create([
            'user_id' => self::$system ? null : Auth::id(),
            'actor_label' => $actorLabel ?? (self::$system ? 'System' : (Auth::check() ? null : 'wiki')),
            'action' => $action,
            'target_type' => $target !== null ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'meta' => $meta ?: null,
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);

        SlackNotifier::announce($log, $target);
        Notifier::handle($log, $target);

        return $log;
    }
}
