<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\CheckUserCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'wiki' => ['nullable', 'string', 'max:64'],
            'checker' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:32'],
            'target' => ['nullable', 'string', 'max:255'],
            'unexplained' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $query = CheckUserCheck::query();

        foreach (['wiki', 'type'] as $exact) {
            if (! empty($filters[$exact])) {
                $query->where($exact, $filters[$exact]);
            }
        }

        if (! empty($filters['checker'])) {
            $query->where('checker_username', $filters['checker']);
        }

        if (! empty($filters['target'])) {
            $target = $filters['target'];
            $query->where(function ($q) use ($target) {
                $q->where('target_name', $target)
                    ->orWhere('target_fingerprint', 'like', $target.'%');
            });
        }

        if (! empty($filters['unexplained'])) {
            $query->unexplained();
        }

        if (! empty($filters['from'])) {
            $query->where('checked_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('checked_at', '<=', $filters['to']);
        }

        $page = $query->orderByDesc('checked_at')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (CheckUserCheck $check) => [
                'id' => $check->id,
                'wiki' => $check->wiki,
                'log_id' => $check->log_id,
                'checked_at' => $check->checked_at?->toIso8601String(),
                'checker' => $check->checker_username,
                'checker_central_id' => $check->checker_central_id,
                'type' => $check->type,
                'type_label' => $check->typeLabel(),
                'target_kind' => $check->target_kind,

                'target' => $check->targetLabel(),
                'target_name' => $check->target_name,
                'fingerprint' => $check->target_fingerprint === null
                    ? null
                    : substr($check->target_fingerprint, 0, 8),

                'reason' => $check->reason,
                'reason_given' => $check->reason_given,
            ])->all(),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),

                'unexplained' => (clone $query)->unexplained()->count(),
            ],
        ]);
    }
}
