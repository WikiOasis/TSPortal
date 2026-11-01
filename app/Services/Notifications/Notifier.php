<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\AuditLog;
use App\Models\Investigation;
use App\Models\PortalNotification;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Safety\Timeline;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class Notifier
{
    private const EVENTS = [
        'comment.added' => 'comment',
        'investigation.note' => 'comment',

        'case.status' => 'status',
        'case.escalated' => 'status',
        'appeal.decided' => 'status',
        'investigation.status' => 'status',
        'investigation.concluded' => 'status',
        'investigation.closed' => 'status',

        'case.assigned' => 'assignment',
        'investigation.assigned' => 'assignment',

        'case.priority' => 'priority',
        'investigation.priority' => 'priority',
        'investigation.review' => 'priority',

        'investigation.case-attached' => 'linked',
        'investigation.case-detached' => 'linked',
        'investigation.subject-added' => 'linked',
        'investigation.subjects-added' => 'linked',
        'investigation.subject-removed' => 'linked',
        'case.duplicate-merged' => 'linked',
        'case.duplicate-received' => 'linked',
        'case.duplicate-unmerged' => 'linked',

        'sanction.issued' => 'actions',
        'sanction.lifted' => 'actions',
        'sanction.expired' => 'actions',
    ];

    public static function handle(AuditLog $log, ?Model $target): void
    {
        try {
            (new self)->process($log, $target);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function subscribe(User $user, string $type, int $id, string $source = 'manual'): void
    {
        Subscription::query()->firstOrCreate(
            ['user_id' => $user->id, 'object_type' => $type, 'object_id' => $id],
            ['source' => $source],
        );
    }

    private function process(AuditLog $log, ?Model $target): void
    {
        [$type, $object] = $this->objectFor($log, $target);

        if ($object === null) {
            return;
        }

        $actor = $log->user_id !== null ? User::query()->find($log->user_id) : null;

        $this->autoSubscribe($log, $type, $object, $actor);

        $event = self::EVENTS[$log->action] ?? 'other';

        $watchers = Subscription::query()
            ->where('object_type', $type)
            ->where('object_id', $object->getKey())
            ->when($actor !== null, fn ($q) => $q->where('user_id', '!=', $actor->id))
            ->pluck('user_id');

        if ($watchers->isEmpty()) {
            return;
        }

        $summary = Timeline::titleFor($log);
        $title = $object instanceof SafetyCase ? $object->subject_line : $object->title;

        foreach (User::query()->whereIn('id', $watchers)->where('active', true)->get() as $user) {
            if (! ($user->notificationSettings()['events'][$event] ?? false)) {
                continue;
            }

            PortalNotification::create([
                'user_id' => $user->id,
                'actor_id' => $actor?->id,
                'event' => $event,
                'object_type' => $type,
                'object_id' => $object->getKey(),
                'reference' => $object->reference,
                'object_title' => $title !== null ? mb_substr((string) $title, 0, 255) : null,
                'summary' => mb_substr($summary, 0, 255),
                'actor_label' => $actor?->username ?? $log->actor_label,
                'created_at' => now(),
            ]);
        }
    }

    private function objectFor(AuditLog $log, ?Model $target): array
    {
        return match (true) {
            $target instanceof SafetyCase => ['case', $target],
            $target instanceof Investigation => ['investigation', $target],
            $target instanceof Sanction && $target->investigation !== null => ['investigation', $target->investigation],
            default => ['', null],
        };
    }

    private function autoSubscribe(AuditLog $log, string $type, Model $object, ?User $actor): void
    {
        $id = (int) $object->getKey();

        if ($actor !== null) {
            $rule = match ($log->action) {
                'comment.added', 'investigation.note' => 'commented',
                'investigation.opened' => 'opened',
                default => null,
            };

            if ($rule !== null && $actor->notificationSettings()['auto'][$rule]) {
                self::subscribe($actor, $type, $id, 'auto');
            }
        }

        if (in_array($log->action, ['case.assigned', 'investigation.assigned'], true) && ! empty($log->meta['assignee'])) {
            $assignee = User::query()->where('username', $log->meta['assignee'])->first();

            if ($assignee !== null && $assignee->notificationSettings()['auto']['assigned']) {
                self::subscribe($assignee, $type, $id, 'auto');
            }
        }
    }
}
