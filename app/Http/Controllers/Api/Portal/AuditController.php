<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PortalObject;
use App\Services\Safety\Timeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'target_type' => ['nullable', 'string', 'max:32'],
            'target_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $query = AuditLog::query()->with('user');

        foreach (['action', 'target_type', 'target_id', 'user_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 50)->withQueryString();

        $rows = collect($page->items());
        $references = $this->referencesFor($rows);

        return response()->json([
            'data' => $rows->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'action_label' => Timeline::titleFor($l),
                'actor' => $l->user?->username ?? $l->actor_label ?? 'system',
                'target_type' => $l->target_type,
                'target_id' => $l->target_id,

                'target' => $references[$this->key($l->target_type, $l->target_id)] ?? null,

                'meta' => $l->meta,
                'ip' => $l->ip_address,
                'at' => $l->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * @param  Collection<int, AuditLog>  $rows
     * @return array<string, array{reference: string, type: string, type_label: string, id: int|null, label: string|null, route: string|null}>
     */
    private function referencesFor(Collection $rows): array
    {
        $wanted = $rows
            ->filter(fn (AuditLog $l) => $l->target_type !== null && $l->target_id !== null)
            ->groupBy('target_type')
            ->map(fn (Collection $group) => $group->pluck('target_id')->unique()->values()->all());

        if ($wanted->isEmpty()) {
            return [];
        }

        $objects = PortalObject::query()
            ->where(function ($query) use ($wanted) {
                foreach ($wanted as $type => $ids) {
                    $query->orWhere(fn ($q) => $q->where('object_type', $type)->whereIn('object_id', $ids));
                }
            })
            ->get();

        $resolved = [];

        foreach ($objects as $object) {
            $route = PortalObject::ROUTES[$object->object_type] ?? null;

            $resolved[$this->key($object->object_type, $object->object_id)] = [
                'reference' => $object->reference,
                'type' => $object->object_type,
                'type_label' => $object->typeLabel(),
                'id' => $object->object_id,
                'label' => $object->label,
                'route' => $route['route'] ?? null,
            ];
        }

        return $resolved;
    }

    private function key(?string $type, int|string|null $id): string
    {
        return $type.'#'.$id;
    }
}
