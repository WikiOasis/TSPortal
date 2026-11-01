<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\Notifications\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function show(Request $request, string $type, int $id): JsonResponse
    {
        $this->check($type);

        return response()->json([
            'subscribed' => Subscription::query()
                ->where(['user_id' => $request->user()->id, 'object_type' => $type, 'object_id' => $id])
                ->exists(),
            'watchers' => Subscription::query()->where(['object_type' => $type, 'object_id' => $id])->count(),
        ]);
    }

    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $this->check($type);
        Notifier::subscribe($request->user(), $type, $id);

        return response()->json(['subscribed' => true]);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $this->check($type);

        Subscription::query()
            ->where(['user_id' => $request->user()->id, 'object_type' => $type, 'object_id' => $id])
            ->delete();

        return response()->json(['subscribed' => false]);
    }

    private function check(string $type): void
    {
        abort_unless(in_array($type, Subscription::OBJECTS, true), 404);
    }
}
