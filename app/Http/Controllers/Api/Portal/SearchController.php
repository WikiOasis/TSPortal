<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Safety\CaseSearch;
use App\Services\Search\PortalSearch;
use App\Services\Search\SearchDocuments;
use App\Services\Search\SearchPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        private readonly PortalSearch $search,
        private readonly SearchPreview $preview,
    ) {}

    public function find(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'kinds' => ['nullable', 'string'],
            'titles_only' => ['nullable', 'boolean'],
            'mine' => ['nullable', 'boolean'],
            'open_only' => ['nullable', 'boolean'],
        ]);

        $found = $this->search->find(
            (string) $data['q'],
            [
                'kinds' => $this->kinds($data['kinds'] ?? null),
                'limit' => (int) ($data['limit'] ?? 20),
                'titles_only' => (bool) ($data['titles_only'] ?? false),
                'mine' => (bool) ($data['mine'] ?? false),
                'open_only' => (bool) ($data['open_only'] ?? false),
            ],
            $this->viewer($request),
        );

        return response()->json([
            'data' => $found['rows'],
            'meta' => [
                'engine' => $found['engine'],
                'total' => $found['total'],
                'degraded' => $found['degraded'],
                'full_text' => $this->search->fullTextAvailable(),
            ],
        ]);
    }

    public function recents(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->search->recents($this->viewer($request)),
            'meta' => [
                'kinds' => array_map(fn (string $kind) => [
                    'kind' => $kind,
                    'label' => SearchDocuments::label($kind),
                    'plural' => SearchDocuments::label($kind, true),
                ], SearchDocuments::KINDS),

                'full_text' => $this->search->fullTextAvailable(),
                'filters' => CaseSearch::help(),
            ],
        ]);
    }

    public function preview(string $kind, int $id): JsonResponse
    {
        $preview = $this->preview->of($kind, $id);

        if ($preview === null) {
            return response()->json([
                'error' => 'not-found',
                'message' => 'That is no longer in the portal.',
            ], 404);
        }

        return response()->json(['data' => $preview]);
    }

    private function kinds(?string $kinds): array
    {
        if ($kinds === null || trim($kinds) === '') {
            return [];
        }

        return array_values(array_intersect(
            SearchDocuments::KINDS,
            array_map(trim(...), explode(',', $kinds)),
        ));
    }

    private function viewer(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
