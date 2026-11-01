<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\PortalNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PortalNotification::query()->where('user_id', $request->user()->id);

        $unread = (clone $query)->whereNull('read_at')->count();

        $items = $query->with('actor')->orderByDesc('id')->limit(40)->get();

        return response()->json([
            'unread' => $unread,
            'data' => $items->map(fn (PortalNotification $n) => [
                'id' => $n->id,
                'event' => $n->event,
                'object_type' => $n->object_type,
                'object_id' => $n->object_id,
                'reference' => $n->reference,
                'title' => $n->object_title,
                'summary' => $n->summary,
                'actor' => $n->actor_label,
                'actor_avatar' => $n->actor?->avatarUrl(),
                'read' => $n->read_at !== null,
                'at' => $n->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function read(Request $request, PortalNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);

        $notification->forceFill(['read_at' => $notification->read_at ?? now()])->save();

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        PortalNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
