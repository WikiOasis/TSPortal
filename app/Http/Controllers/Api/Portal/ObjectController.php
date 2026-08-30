<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\PortalObject;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Services\Safety\CaseSearch;
use App\Services\Safety\References;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ObjectController extends Controller
{
    public function show(string $reference): JsonResponse
    {
        $found = References::resolve($reference);

        if ($found === null) {
            return response()->json([
                'error' => 'not-found',
                'message' => sprintf('Nothing in the portal is numbered %s.', $reference),
            ], 404);
        }

        $route = PortalObject::ROUTES[$found['type']] ?? null;

        return response()->json([
            'reference' => $found['reference'],
            'type' => $found['type'],
            'type_label' => $route['label'] ?? $found['type'],
            'id' => $found['id'],
            'label' => $found['label'],
            'route' => $route['route'] ?? null,
        ]);
    }

    private const KINDS = [
        'investigation' => PortalObject::TYPE_INVESTIGATION,
        'case' => PortalObject::TYPE_CASE,
        'sanction' => PortalObject::TYPE_SANCTION,
        'removal' => PortalObject::TYPE_DATA_REMOVAL,
        'subject' => null,
    ];

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
            'type' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::KINDS))],
        ]);

        $term = trim($data['q']);
        $limit = (int) ($data['limit'] ?? 10);
        $like = '%'.$term.'%';
        $type = $data['type'] ?? null;

        $perKind = $type === null ? 5 : $limit;

        $rows = [];

        foreach (
            PortalObject::query()
                ->where('reference', 'like', strtoupper($term).'%')
                ->whereNotNull('object_id')
                ->when($type !== null, fn ($q) => $q->where('object_type', self::KINDS[$type]))
                ->orderByDesc('year')
                ->orderByDesc('sequence')
                ->limit($limit)
                ->get() as $object
        ) {
            $rows[] = [
                'reference' => $object->reference,
                'type' => $object->object_type,
                'type_label' => $object->typeLabel(),
                'id' => $object->object_id,
                'label' => $object->label,
                'route' => PortalObject::ROUTES[$object->object_type]['route'] ?? null,
            ];
        }

        if ($type === null || $type === 'investigation') {
            foreach (
                Investigation::query()
                    ->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('premise', 'like', $like))
                    ->orderByRaw('CASE WHEN status IN (?, ?) THEN 0 ELSE 1 END', [
                        Investigation::STATUS_OPEN,
                        Investigation::STATUS_MONITORING,
                    ])
                    ->orderByDesc('updated_at')
                    ->limit($perKind)
                    ->get() as $investigation
            ) {
                $rows[] = [
                    'reference' => $investigation->reference,
                    'type' => PortalObject::TYPE_INVESTIGATION,
                    'type_label' => 'Investigation',
                    'id' => $investigation->id,
                    'label' => $investigation->title,
                    'route' => 'investigation',
                ];
            }
        }

        if ($type === null || $type === 'case') {
            foreach (
                SafetyCase::query()
                    ->where(fn ($q) => $q->where('subject_line', 'like', $like)
                        ->orWhere('summary', 'like', $like)
                        ->orWhere('about', 'like', $like))
                    ->orderByRaw('CASE WHEN status IN (?, ?, ?) THEN 0 ELSE 1 END', [
                        SafetyCase::STATUS_RECEIVED,
                        SafetyCase::STATUS_IN_REVIEW,
                        SafetyCase::STATUS_INVESTIGATING,
                    ])
                    ->orderByDesc('created_at')
                    ->limit($perKind)
                    ->get() as $case
            ) {
                $rows[] = [
                    'reference' => $case->reference,
                    'type' => PortalObject::TYPE_CASE,
                    'type_label' => ucfirst($case->type),
                    'id' => $case->id,
                    'label' => $case->subject_line,
                    'route' => 'case',
                ];
            }
        }

        if ($type === null || $type === 'sanction') {
            foreach (
                Sanction::query()
                    ->where(fn ($q) => $q->where('reason', 'like', $like)
                        ->orWhere('label', 'like', $like)
                        ->orWhere('scope', 'like', $like))
                    ->with('subject')
                    ->orderByDesc('issued_at')
                    ->limit($perKind)
                    ->get() as $sanction
            ) {
                $rows[] = [
                    'reference' => $sanction->reference,
                    'type' => PortalObject::TYPE_SANCTION,
                    'type_label' => 'Action',
                    'id' => $sanction->id,
                    'label' => $sanction->subject?->username ?? $sanction->label,
                    'route' => 'sanctions',
                ];
            }
        }

        if ($type === null || $type === 'removal') {
            foreach (
                DataRemoval::query()
                    ->where('target_username', 'like', $like)
                    ->orderByDesc('id')
                    ->limit($perKind)
                    ->get() as $removal
            ) {
                $rows[] = [
                    'reference' => $removal->reference,
                    'type' => PortalObject::TYPE_DATA_REMOVAL,
                    'type_label' => 'Erasure',
                    'id' => $removal->id,
                    'label' => $removal->target_username,
                    'route' => 'data-removals',
                ];
            }
        }

        if ($type === null || $type === 'subject') {
            foreach (
                Subject::query()
                    ->where('username_key', 'like', '%'.Subject::key($term).'%')
                    ->orderBy('username')
                    ->limit($perKind)
                    ->get() as $subject
            ) {
                $rows[] = [
                    'reference' => null,
                    'type' => 'Subject',
                    'type_label' => 'Account',
                    'id' => $subject->id,
                    'label' => $subject->username,
                    'route' => 'subject',
                ];
            }
        }

        $seen = [];
        $unique = [];
        foreach ($rows as $row) {
            $key = $row['route'].':'.$row['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $row;
        }

        return response()->json(['data' => array_slice($unique, 0, $type === null ? $limit + 10 : $limit)]);
    }

    public function help(): JsonResponse
    {
        return response()->json(['data' => CaseSearch::help()]);
    }
}
