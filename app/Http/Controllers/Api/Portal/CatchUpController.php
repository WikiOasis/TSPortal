<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\CaseRead;
use App\Models\SafetyCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CatchUpController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'show' => ['nullable', 'string', 'in:unread,all'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $user = $request->user();
        $unreadOnly = ($filters['show'] ?? 'unread') === 'unread';

        $paginator = $this->base($user->id)
            ->when($unreadOnly, fn (Builder $q) => $this->onlyUnread($q))
            ->with('assignee')
            ->orderByDesc('cases.updated_at')
            ->orderByDesc('cases.id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $items = collect($paginator->items())->map(fn (SafetyCase $c) => [
            'id' => $c->id,
            'reference' => $c->reference,
            'type' => $c->type,
            'subject' => $c->subject_line,
            'summary' => Str::limit(trim((string) $c->summary), 220),
            'status' => $c->status,
            'priority' => $c->priority,
            'threat_to_life' => $c->isThreatToLife(),
            'assignee' => $c->assignee?->username,
            'filed' => $c->created_at?->toIso8601String(),
            'updated' => $c->updated_at?->toIso8601String(),
            'unread' => $c->my_read_at === null || $c->updated_at?->gt($c->my_read_at),
        ])->all();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'unread' => $this->onlyUnread($this->base($user->id))->count(),
            ],
        ]);
    }

    public function read(Request $request, SafetyCase $case): JsonResponse
    {
        CaseRead::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'case_id' => $case->id],
            ['read_at' => now()],
        );

        return $this->counts($request);
    }

    public function unread(Request $request, SafetyCase $case): JsonResponse
    {
        CaseRead::query()
            ->where('user_id', $request->user()->id)
            ->where('case_id', $case->id)
            ->delete();

        return $this->counts($request);
    }

    public function readAll(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $now = now();

        $this->onlyUnread($this->base($userId))
            ->pluck('cases.id')
            ->chunk(500)
            ->each(fn ($ids) => CaseRead::query()->upsert(
                $ids->map(fn ($id) => ['user_id' => $userId, 'case_id' => $id, 'read_at' => $now])->all(),
                ['user_id', 'case_id'],
                ['read_at'],
            ));

        return $this->counts($request);
    }

    private function counts(Request $request): JsonResponse
    {
        return response()->json([
            'unread' => $this->onlyUnread($this->base($request->user()->id))->count(),
        ]);
    }

    /**
     * @return Builder<SafetyCase>
     */
    private function base(int $userId): Builder
    {
        return SafetyCase::query()
            ->whereIn('cases.status', SafetyCase::OPEN_STATUSES)
            ->where('cases.automated', false)
            ->leftJoin('case_reads', fn ($join) => $join
                ->on('case_reads.case_id', '=', 'cases.id')
                ->where('case_reads.user_id', '=', $userId))
            ->select('cases.*', 'case_reads.read_at as my_read_at');
    }

    private function onlyUnread(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('case_reads.read_at')
            ->orWhereColumn('case_reads.read_at', '<', 'cases.updated_at'));
    }
}
