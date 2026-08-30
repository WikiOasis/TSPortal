<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Services\Safety\AppealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AppealController extends Controller
{
    public function __construct(private readonly AppealService $appeals) {}

    public function link(Request $request, SafetyCase $case): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['present', 'nullable', 'string', 'max:32'],
        ]);

        $reference = $data['reference'] === null ? null : trim($data['reference']);

        $sanction = $reference === null || $reference === ''
            ? null
            : Sanction::query()->where('reference', strtoupper($reference))->first();

        if ($reference !== null && $reference !== '' && $sanction === null) {
            return response()->json([
                'error' => 'no-such-action',
                'message' => "There is no action here under {$reference}.",
            ], 422);
        }

        try {
            $this->appeals->link($case, $sanction, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['error' => 'refused', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'action' => $sanction?->reference]);
    }

    public function decide(Request $request, SafetyCase $case): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'string', 'in:'.implode(',', SafetyCase::APPEAL_OUTCOMES)],
            'note' => ['required', 'string', 'min:1', 'max:20000'],
            'lift' => ['nullable', 'boolean'],
        ]);

        try {
            $decision = $this->appeals->decide(
                $case,
                $data['outcome'],
                $request->user(),
                $data['note'],
                (bool) ($data['lift'] ?? true),
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => 'refused', 'message' => $e->getMessage()], 422);
        }

        $lifted = $decision['lifted'];

        return response()->json([
            'ok' => true,
            'outcome' => $decision['case']->appeal_outcome,
            'status' => $decision['case']->status,

            'lifted' => $lifted === null ? null : [
                'reference' => $lifted->reference,
                'push_state' => $lifted->push_state,
                'in_force' => $lifted->isInForce(),
            ],
        ], 201);
    }
}
