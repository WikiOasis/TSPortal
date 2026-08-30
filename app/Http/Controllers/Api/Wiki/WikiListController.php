<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Wiki;

use App\Http\Controllers\Controller;
use App\Models\Wiki;
use App\Services\Safety\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WikiListController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'wikis' => ['required', 'array', 'max:10000'],
            'wikis.*.dbname' => ['required', 'string', 'max:64'],
            'wikis.*.sitename' => ['nullable', 'string', 'max:255'],
            'wikis.*.url' => ['nullable', 'string', 'max:255'],
            'wikis.*.language' => ['nullable', 'string', 'max:32'],
            'wikis.*.closed' => ['boolean'],
            'wikis.*.private' => ['boolean'],
            'wikis.*.deleted' => ['boolean'],
            'wikis.*.locked' => ['boolean'],
        ]);

        $seen = now();

        $rows = collect($data['wikis'])->map(fn (array $wiki) => [
            'dbname' => $wiki['dbname'],
            'sitename' => $wiki['sitename'] ?? null,
            'url' => $wiki['url'] ?? null,
            'language' => $wiki['language'] ?? null,
            'closed' => (bool) ($wiki['closed'] ?? false),
            'private' => (bool) ($wiki['private'] ?? false),
            'deleted' => (bool) ($wiki['deleted'] ?? false),
            'locked' => (bool) ($wiki['locked'] ?? false),
            'last_seen_at' => $seen,
            'created_at' => $seen,
            'updated_at' => $seen,
        ])->all();

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 200) as $batch) {
                Wiki::query()->upsert(
                    $batch,
                    ['dbname'],
                    ['sitename', 'url', 'language', 'closed', 'private', 'deleted', 'locked', 'last_seen_at', 'updated_at'],
                );
            }
        });

        Audit::log('wikis.synced', null, [
            'count' => count($rows),
            'closed' => collect($rows)->where('closed', true)->count(),
        ], actorLabel: 'wiki');

        return response()->json([
            'ok' => true,
            'known' => Wiki::query()->count(),
            'received' => count($rows),
        ]);
    }
}
