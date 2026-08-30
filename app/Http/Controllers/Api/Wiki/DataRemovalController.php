<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Wiki;

use App\Http\Controllers\Controller;
use App\Models\DataRemoval;
use App\Models\Subject;
use App\Services\Safety\DataRemovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DataRemovalController extends Controller
{
    public function __construct(private readonly DataRemovalService $removals) {}

    public function check(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'username' => ['nullable', 'string', 'max:255'],
            'newname' => ['nullable', 'string', 'max:255'],
        ]);

        $removal = DataRemoval::query()->where('reference', strtoupper(trim($reference)))->first();

        $matches = $removal !== null
            && $removal->isApproved()
            && ! in_array($removal->state, [DataRemoval::STATE_DONE, DataRemoval::STATE_REFUSED], true)
            && $this->nameMatches($removal, $data['username'] ?? null)
            && (($data['newname'] ?? null) === null || $data['newname'] === $removal->target_username);

        if (! $matches) {
            return response()->json(['match' => false]);
        }

        return response()->json([
            'match' => true,
            'reference' => $removal->reference,
            'newname' => $removal->target_username,
            'wikis' => $removal->wikis,
            'state' => $removal->state,
        ]);
    }

    public function progress(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'wiki' => ['required', 'string', 'max:64'],
            'ok' => ['required', 'boolean'],
            'error' => ['nullable', 'string', 'max:2000'],
        ]);

        $removal = DataRemoval::query()->where('reference', strtoupper(trim($reference)))->first();

        if ($removal === null) {
            return response()->json(['error' => 'not-found'], 404);
        }

        $this->removals->progress(
            $removal,
            $data['wiki'],
            (bool) $data['ok'],
            $data['error'] ?? null,
        );

        $fresh = $removal->fresh();

        return response()->json([
            'ok' => true,
            'state' => $fresh?->state,
            'outstanding' => array_values((array) (($fresh?->result ?? [])['pending'] ?? [])),
        ]);
    }

    private function nameMatches(DataRemoval $removal, ?string $username): bool
    {
        if ($username === null || $username === '') {
            return true;
        }

        $key = Subject::key($username);

        return $key === Subject::key($removal->previous_username)
            || $key === Subject::key($removal->target_username);
    }
}
