<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Wiki;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WikiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'for' => ['nullable', 'string', 'in:actionable,closeable,all'],
        ]);

        $query = Wiki::query();

        match ($filters['for'] ?? 'actionable') {
            'closeable' => $query->closeable(),
            'all' => null,
            default => $query->actionable(),
        };

        if (! empty($filters['q'])) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($q) => $q->where('dbname', 'like', $term)->orWhere('sitename', 'like', $term));
        }

        $wikis = $query->orderBy('dbname')->limit(5000)->get();

        return response()->json([
            'data' => $wikis->map(fn (Wiki $w) => [
                'dbname' => $w->dbname,
                'sitename' => $w->sitename,
                'label' => $w->label(),
                'url' => $w->url,
                'closed' => $w->closed,
                'private' => $w->private,
                'locked' => $w->locked,
            ])->all(),

            'synced' => Wiki::query()->exists(),
            'last_seen' => Wiki::query()->max('last_seen_at'),
        ]);
    }
}
