<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\SafetyCase;
use App\Services\Safety\DataRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DataRequestController extends Controller
{
    public function __construct(private readonly DataRequestService $requests) {}

    public function setKind(Request $request, SafetyCase $case): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', SafetyCase::DATA_KINDS)],
        ]);

        try {
            $this->requests->setKind($case, $data['kind']);
        } catch (RuntimeException $e) {
            return response()->json(['error' => 'not-applicable', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'kind' => $case->fresh()?->data_kind]);
    }

    public function approve(Request $request, SafetyCase $case): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:1', 'max:20000'],
            'start' => ['nullable', 'boolean'],
        ]);

        try {
            $outcome = $this->requests->approve(
                $case,
                $request->user(),
                $data['note'],
                (bool) ($data['start'] ?? true),
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => 'refused', 'message' => $e->getMessage()], 422);
        }

        $removal = $outcome['removal'];

        return response()->json([
            'ok' => true,
            'kind' => $case->fresh()?->data_kind,
            'erasure' => $removal === null ? null : [
                'id' => $removal->id,
                'reference' => $removal->reference,
                'state' => $removal->state,
                'state_label' => $removal->label(),
                'becomes' => $removal->target_username,
            ],

            'still_owed' => $case->fresh()?->dataRequestOutstanding(),
        ], 201);
    }

    public function decline(Request $request, SafetyCase $case): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:20000'],
        ]);

        try {
            $this->requests->decline($case, $request->user(), $data['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['error' => 'refused', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'status' => $case->fresh()?->status]);
    }
}
