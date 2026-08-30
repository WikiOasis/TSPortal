<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\OutboundEvent;
use App\Models\PortalObject;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Services\MediaWiki\WikiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $byStatus = SafetyCase::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byType = SafetyCase::query()
            ->whereIn('status', SafetyCase::OPEN_STATUSES)
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $stuck = OutboundEvent::query()
            ->whereNull('delivered_at')
            ->whereNull('next_attempt_at')
            ->where('attempts', '>', 0)
            ->count();

        $user = $request->user();

        $open = SafetyCase::query()->whereIn('status', SafetyCase::OPEN_STATUSES);
        $liveFiles = Investigation::query()->live();

        $stale = (clone $open)->where('updated_at', '<', now()->subDays(14))->count();
        $dueReview = Investigation::query()->dueForReview()->count();

        $brokenActions = Sanction::query()
            ->whereIn('push_state', [Sanction::PUSH_PARTIAL, Sanction::PUSH_FAILED])
            ->count();
        $brokenRemovals = DataRemoval::query()->where('state', DataRemoval::STATE_FAILED)->count();
        $stuckEvents = OutboundEvent::query()
            ->whereNull('delivered_at')
            ->whereNull('next_attempt_at')
            ->where('attempts', '>', 0)
            ->count();

        return response()->json([
            'open_items' => [
                'total' => (clone $open)->count() + (clone $liveFiles)->count(),
                'cases' => (clone $open)->count(),
                'investigations' => (clone $liveFiles)->count(),
                'unassigned' => (clone $open)->whereNull('assigned_to')->count(),
            ],

            'threat_to_life' => [
                'open' => (clone $open)->threatToLife()->count(),
                'unassigned' => (clone $open)->threatToLife()->whereNull('assigned_to')->count(),
            ],

            'mine' => [
                'total' => (clone $open)->where('assigned_to', $user->id)->count()
                    + (clone $liveFiles)->where('assigned_to', $user->id)->count(),
                'cases' => (clone $open)->where('assigned_to', $user->id)->count(),
                'investigations' => (clone $liveFiles)->where('assigned_to', $user->id)->count(),
            ],

            'due_a_look' => [
                'total' => $stale + $dueReview,
                'stale_cases' => $stale,
                'overdue_files' => $dueReview,
            ],

            'attention' => [
                'total' => $brokenActions + $brokenRemovals + $stuckEvents
                    + Sanction::query()->where('push_state', Sanction::PUSH_MANUAL)->count(),
                'partial_actions' => Sanction::query()->where('push_state', Sanction::PUSH_PARTIAL)->count(),
                'failed_actions' => Sanction::query()->where('push_state', Sanction::PUSH_FAILED)->count(),
                'manual_actions' => Sanction::query()->where('push_state', Sanction::PUSH_MANUAL)->count(),
                'failed_removals' => $brokenRemovals,
                'stuck_events' => $stuckEvents,
                'removals_waiting' => DataRemoval::query()
                    ->where('state', DataRemoval::STATE_REQUESTED)
                    ->count(),
            ],

            'wiki' => [
                'push_enabled' => (bool) config('mediawiki.s2s.push_enabled'),
                'centralauth_lock' => (bool) config('mediawiki.centralauth_lock'),
                'pii_enabled' => (bool) config('mediawiki.pii.enabled'),
                'supported_actions' => WikiClient::tasks(),

                'unknown_actions' => WikiClient::unknownTasks(),
                'queued' => OutboundEvent::query()->whereNull('delivered_at')->count(),
            ],

            'reviews' => Investigation::query()
                ->dueForReview()
                ->with('assignee')
                ->orderBy('review_at')
                ->limit(6)
                ->get()
                ->map(fn (Investigation $i) => [
                    'id' => $i->id,
                    'reference' => $i->reference,
                    'title' => $i->title,
                    'status' => $i->status,
                    'due' => $i->review_at?->toIso8601String(),
                    'assignee' => $i->assignee?->username,
                ])->all(),

            'recent' => SafetyCase::query()
                ->with('reporter')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get()
                ->map(fn (SafetyCase $c) => [
                    'id' => $c->id,
                    'reference' => $c->reference,
                    'type' => $c->type,
                    'subject' => $c->subject_line,
                    'status' => $c->status,
                    'filed' => $c->created_at?->toIso8601String(),
                    'reporter' => $c->anonymous ? null : $c->reporter?->username,
                ])->all(),

            'activity' => $this->activity(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activity(): array
    {
        $logs = AuditLog::query()->with('user')->orderByDesc('id')->limit(12)->get();

        $objects = PortalObject::query()
            ->whereIn('object_type', $logs->pluck('target_type')->filter()->unique())
            ->whereIn('object_id', $logs->pluck('target_id')->filter()->unique())
            ->get()
            ->keyBy(fn (PortalObject $o) => $o->object_type.'#'.$o->object_id);

        return $logs->map(function (AuditLog $l) use ($objects) {
            $object = $objects->get($l->target_type.'#'.$l->target_id);

            return [
                'action' => $l->action,
                'actor' => $l->user?->username ?? $l->actor_label ?? 'system',
                'reference' => $object?->reference,
                'target_label' => $object?->typeLabel(),
                'route' => $object === null
                    ? null
                    : (PortalObject::ROUTES[$object->object_type]['route'] ?? null),
                'target_id' => $object?->object_id,
                'at' => $l->created_at?->toIso8601String(),
                'meta' => $l->meta,
            ];
        })->all();
    }
}
