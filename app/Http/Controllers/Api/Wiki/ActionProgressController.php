<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Wiki;

use App\Http\Controllers\Controller;
use App\Models\Sanction;
use App\Services\Safety\SanctionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActionProgressController extends Controller
{
    public function __construct(private readonly SanctionService $sanctions) {}

    public function store(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'wiki' => ['required', 'string', 'max:64'],
            'ok' => ['required', 'boolean'],
            'error' => ['nullable', 'string', 'max:2000'],
        ]);

        $sanction = Sanction::query()->where('reference', strtoupper(trim($reference)))->first();

        if ($sanction === null) {
            return response()->json(['error' => 'not-found'], 404);
        }

        if ($sanction->push_state !== Sanction::PUSH_QUEUED) {
            return response()->json([
                'ok' => true,
                'state' => $sanction->push_state,
                'outstanding' => [],
                'ignored' => true,
            ]);
        }

        $this->sanctions->progress(
            $sanction,
            $data['wiki'],
            (bool) $data['ok'],
            $data['error'] ?? null,
        );

        $fresh = $sanction->fresh();

        return response()->json([
            'ok' => true,
            'state' => $fresh?->push_state,
            'outstanding' => array_values((array) (($fresh?->push_result ?? [])['pending'] ?? [])),
        ]);
    }
}
