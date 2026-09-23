<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\CaseComment;
use App\Models\CheckUserCheck;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class Analytics
{
    public const BUCKETS = ['day', 'week', 'month'];

    public const SOURCE_PEOPLE = 'people';

    public const SOURCE_AUTOMATED = 'automated';

    public const SOURCES = [self::SOURCE_PEOPLE, self::SOURCE_AUTOMATED];

    private ?string $source = null;

    /**
     * @return array<string, mixed>
     */
    public function overview(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $wiki = null,
        ?string $source = null,
    ): array {
        $this->source = in_array($source, self::SOURCES, true) ? $source : null;

        $bucket = $this->bucketFor($from, $to);
        [$prevFrom, $prevTo] = $this->previous($from, $to);

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $this->daysInclusive($from, $to),
                'bucket' => $bucket,
                'wiki' => $wiki,
                'source' => $this->source,
            ],

            'headline' => $this->headline($from, $to, $prevFrom, $prevTo, $wiki),

            'intake' => [
                'over_time' => $this->overTime(
                    $this->cases($from, $to, $wiki), 'created_at', $bucket, $from, $to
                ),
                'by_type' => $this->countBy($this->cases($from, $to, $wiki), 'type'),
                'by_category' => $this->byCategory($from, $to, $wiki),
                'by_group' => $this->countBy($this->cases($from, $to, $wiki), 'category_group'),
                'by_wiki' => $this->countBy($this->cases($from, $to, $wiki), 'wiki', 12),
                'by_source' => $this->bySource($from, $to, $wiki),
            ],

            'handling' => [
                'first_response' => $this->firstResponseTimes($from, $to, $wiki),
                'time_to_close' => $this->timeToClose($from, $to, $wiki),
                'closed_by_status' => $this->countBy(
                    $this->cases($from, $to, $wiki, 'closed_at')->whereNotNull('closed_at'),
                    'status'
                ),

                'backlog' => $this->backlog($wiki),
            ],

            'outcomes' => [
                'actions_by_type' => $this->countBy($this->sanctions($from, $to), 'type'),
                'actions_by_reason' => $this->actionsByReason($from, $to),
                'investigations_by_outcome' => $this->countBy(
                    Investigation::query()->whereBetween('closed_at', [$from, $to]),
                    'outcome'
                ),
                'actions_over_time' => $this->overTime(
                    $this->sanctions($from, $to), 'issued_at', $bucket, $from, $to
                ),

                'appeals' => $this->appeals($from, $to),
            ],

            'people' => $this->workload($from, $to),

            'checkuser' => $this->checkUser($from, $to, $wiki, $bucket),
        ];
    }

    /**
     * @return array<string, array{value: int|float|null, previous: int|float|null}>
     */
    private function headline(
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $prevFrom,
        CarbonImmutable $prevTo,
        ?string $wiki,
    ): array {
        $medianNow = $this->timeToClose($from, $to, $wiki)['median_hours'];
        $medianBefore = $this->timeToClose($prevFrom, $prevTo, $wiki)['median_hours'];

        return [
            'received' => [
                'value' => $this->cases($from, $to, $wiki)->count(),
                'previous' => $this->cases($prevFrom, $prevTo, $wiki)->count(),
            ],
            'closed' => [
                'value' => $this->cases($from, $to, $wiki, 'closed_at')->whereNotNull('closed_at')->count(),
                'previous' => $this->cases($prevFrom, $prevTo, $wiki, 'closed_at')->whereNotNull('closed_at')->count(),
            ],
            'actions' => [
                'value' => $this->sanctions($from, $to)->count(),
                'previous' => $this->sanctions($prevFrom, $prevTo)->count(),
            ],

            'median_close_hours' => [
                'value' => $medianNow,
                'previous' => $medianBefore,
            ],
        ];
    }

    /**
     * @return array{rows: list<array{key: string, label: string, group: ?string, total: int}>, cases: int, uncategorised: int}
     */
    private function byCategory(CarbonImmutable $from, CarbonImmutable $to, ?string $wiki): array
    {
        $rows = DB::table('case_categories')
            ->join('cases', 'cases.id', '=', 'case_categories.case_id')
            ->whereBetween('cases.created_at', [$from, $to])
            ->when($wiki !== null, fn ($q) => $q->where('cases.wiki', $wiki))
            ->when($this->source !== null, fn ($q) => $q->where('cases.automated', $this->source === self::SOURCE_AUTOMATED))
            ->groupBy('case_categories.category', 'case_categories.label', 'case_categories.group')
            ->orderByDesc(DB::raw('count(*)'))
            ->get([
                'case_categories.category as key',
                'case_categories.label as label',
                'case_categories.group as group',
                DB::raw('count(*) as total'),
            ]);

        $cases = $this->cases($from, $to, $wiki)->count();

        return [
            'rows' => $rows->map(fn ($r) => [
                'key' => (string) $r->key,
                'label' => (string) $r->label,
                'group' => $r->group !== null ? (string) $r->group : null,
                'total' => (int) $r->total,
            ])->all(),
            'cases' => $cases,

            'uncategorised' => (clone $this->cases($from, $to, $wiki))->whereNull('category')->count(),
        ];
    }

    /**
     * @return list<array{key: ?string, label: string, group: ?string, total: int}>
     */
    private function actionsByReason(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $vocabulary = (array) config('categories.action_reasons', []);

        return $this->sanctions($from, $to)
            ->selectRaw('reason_category, count(*) as total')
            ->groupBy('reason_category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->reason_category,
                'label' => $row->reason_category === null
                    ? 'No reason category recorded'
                    : ($vocabulary[$row->reason_category]['label'] ?? $row->reason_category),
                'group' => $row->reason_category === null
                    ? null
                    : ($vocabulary[$row->reason_category]['group'] ?? null),
                'total' => (int) $row->total,
            ])->all();
    }

    /**
     * @return array{median_hours: ?float, p90_hours: ?float, answered: int, unanswered: int}
     */
    private function firstResponseTimes(CarbonImmutable $from, CarbonImmutable $to, ?string $wiki): array
    {
        $cases = $this->cases($from, $to, $wiki)->pluck('created_at', 'id');

        if ($cases->isEmpty()) {
            return ['median_hours' => null, 'p90_hours' => null, 'answered' => 0, 'unanswered' => 0];
        }

        $replies = CaseComment::query()
            ->whereIn('case_id', $cases->keys())
            ->where('author_type', CaseComment::AUTHOR_STAFF)
            ->where('visibility', CaseComment::VISIBILITY_PUBLIC)
            ->selectRaw('case_id, min(created_at) as first_reply')
            ->groupBy('case_id')
            ->pluck('first_reply', 'case_id');

        $hours = [];
        foreach ($cases as $id => $filed) {
            $reply = $replies[$id] ?? null;
            if ($reply === null || $filed === null) {
                continue;
            }
            $hours[] = $filed->diffInMinutes(CarbonImmutable::parse($reply)) / 60;
        }

        return [
            'median_hours' => $this->percentile($hours, 0.5),
            'p90_hours' => $this->percentile($hours, 0.9),
            'answered' => count($hours),
            'unanswered' => $cases->count() - count($hours),
        ];
    }

    /**
     * @return array{median_hours: ?float, p90_hours: ?float, closed: int}
     */
    private function timeToClose(CarbonImmutable $from, CarbonImmutable $to, ?string $wiki): array
    {
        $rows = SafetyCase::query()
            ->whereBetween('closed_at', [$from, $to])
            ->whereNotNull('closed_at')
            ->when($wiki !== null, fn (Builder $q) => $q->where('wiki', $wiki))
            ->tap(fn (Builder $q) => $this->fromSource($q))
            ->get(['created_at', 'closed_at']);

        $hours = $rows
            ->filter(fn ($c) => $c->created_at !== null && $c->closed_at !== null)
            ->map(fn ($c) => $c->created_at->diffInMinutes($c->closed_at) / 60)
            ->values()
            ->all();

        return [
            'median_hours' => $this->percentile($hours, 0.5),
            'p90_hours' => $this->percentile($hours, 0.9),
            'closed' => count($hours),
        ];
    }

    /**
     * @return array{total: int, bands: list<array{label: string, total: int}>, oldest_days: ?int}
     */
    private function backlog(?string $wiki): array
    {
        $open = SafetyCase::query()
            ->whereIn('status', SafetyCase::OPEN_STATUSES)
            ->when($wiki !== null, fn (Builder $q) => $q->where('wiki', $wiki))
            ->tap(fn (Builder $q) => $this->fromSource($q));

        $now = CarbonImmutable::now();
        $bands = [
            ['label' => 'Under a week', 'from' => 0, 'to' => 7],
            ['label' => 'One to four weeks', 'from' => 7, 'to' => 28],
            ['label' => 'One to three months', 'from' => 28, 'to' => 90],
            ['label' => 'Over three months', 'from' => 90, 'to' => null],
        ];

        $rows = [];
        foreach ($bands as $band) {
            $query = (clone $open)->where('created_at', '<=', $now->subDays($band['from']));
            if ($band['to'] !== null) {
                $query->where('created_at', '>', $now->subDays($band['to']));
            }
            $rows[] = ['label' => $band['label'], 'total' => $query->count()];
        }

        $oldest = (clone $open)->min('created_at');

        return [
            'total' => (clone $open)->count(),
            'bands' => $rows,
            'oldest_days' => $oldest === null
                ? null
                : (int) CarbonImmutable::parse($oldest)->diffInDays($now),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workload(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $open = SafetyCase::query()
            ->whereIn('status', SafetyCase::OPEN_STATUSES)
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, count(*) as total')
            ->groupBy('assigned_to')
            ->pluck('total', 'assigned_to');

        $closed = SafetyCase::query()
            ->whereBetween('closed_at', [$from, $to])
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, count(*) as total')
            ->groupBy('assigned_to')
            ->pluck('total', 'assigned_to');

        $issued = Sanction::query()
            ->whereBetween('issued_at', [$from, $to])
            ->whereNotNull('issued_by')
            ->selectRaw('issued_by, count(*) as total')
            ->groupBy('issued_by')
            ->pluck('total', 'issued_by');

        $files = Investigation::query()
            ->live()
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, count(*) as total')
            ->groupBy('assigned_to')
            ->pluck('total', 'assigned_to');

        $ids = collect($open->keys())
            ->merge($closed->keys())->merge($issued->keys())->merge($files->keys())
            ->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->get(['id', 'username', 'real_name'])
            ->map(fn ($user) => [
                'id' => $user->id,
                'username' => $user->username,
                'open_cases' => (int) ($open[$user->id] ?? 0),
                'open_files' => (int) ($files[$user->id] ?? 0),
                'closed_in_period' => (int) ($closed[$user->id] ?? 0),
                'actions_in_period' => (int) ($issued[$user->id] ?? 0),
            ])
            ->sortByDesc(fn ($row) => $row['open_cases'] + $row['open_files'])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function checkUser(CarbonImmutable $from, CarbonImmutable $to, ?string $wiki, string $bucket): array
    {
        $base = fn () => CheckUserCheck::query()
            ->whereBetween('checked_at', [$from, $to])
            ->when($wiki !== null, fn (Builder $q) => $q->where('wiki', $wiki));

        $total = $base()->count();
        $unexplained = $base()->unexplained()->count();

        return [
            'total' => $total,
            'unexplained' => $unexplained,

            'unexplained_share' => $total > 0 ? round($unexplained / $total, 4) : null,

            'checkers' => $base()->count(DB::raw('distinct checker_username')),
            'over_time' => $this->overTime($base(), 'checked_at', $bucket, $from, $to),
            'by_type' => $this->countBy($base(), 'type'),
            'by_target_kind' => $this->countBy($base(), 'target_kind'),
            'by_wiki' => $this->countBy($base(), 'wiki', 12),

            'by_checker' => $base()
                ->selectRaw('checker_username, count(*) as total, '
                    .'sum(case when reason_given then 0 else 1 end) as unexplained')
                ->groupBy('checker_username')
                ->orderByDesc('total')
                ->limit(25)
                ->get()
                ->map(fn ($row) => [
                    'checker' => (string) $row->checker_username,
                    'total' => (int) $row->total,
                    'unexplained' => (int) $row->unexplained,
                ])->all(),

            'repeat_targets' => $base()
                ->whereNotNull('target_fingerprint')
                ->selectRaw('target_fingerprint, target_kind, max(target_name) as target_name, '
                    .'count(*) as total, count(distinct checker_username) as checkers')
                ->groupBy('target_fingerprint', 'target_kind')
                ->havingRaw('count(*) > 1')
                ->orderByDesc('total')
                ->limit(15)
                ->get()
                ->map(fn ($row) => [
                    'fingerprint' => substr((string) $row->target_fingerprint, 0, 8),
                    'kind' => (string) $row->target_kind,
                    'target' => $row->target_name,
                    'total' => (int) $row->total,
                    'checkers' => (int) $row->checkers,
                ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appeals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $received = fn (): Builder => SafetyCase::query()
            ->where('type', SafetyCase::TYPE_APPEAL)
            ->whereBetween('created_at', [$from, $to]);

        $decided = fn (): Builder => SafetyCase::query()
            ->where('type', SafetyCase::TYPE_APPEAL)
            ->whereBetween('appeal_decided_at', [$from, $to]);

        $onTheMerits = (clone $decided())->whereIn('appeal_outcome', SafetyCase::APPEAL_ON_THE_MERITS)->count();
        $accepted = (clone $decided())->whereIn('appeal_outcome', SafetyCase::APPEAL_ACCEPTED)->count();

        return [
            'received' => $received()->count(),
            'decided' => $decided()->count(),
            'by_outcome' => $this->countBy($decided(), 'appeal_outcome'),

            'accepted' => $accepted,
            'decided_on_the_merits' => $onTheMerits,

            'accepted_share' => $onTheMerits > 0 ? round($accepted / $onTheMerits, 4) : null,

            'by_infraction' => $this->appealsByInfraction($from, $to),

            'not_linked' => (clone $received())->whereNull('sanction_id')->count(),

            'awaiting_decision' => SafetyCase::query()
                ->awaitingAppealDecision()
                ->whereIn('status', SafetyCase::OPEN_STATUSES)
                ->count(),

            'link_needs_checking' => SafetyCase::query()
                ->awaitingAppealDecision()
                ->whereIn('appeal_link_confidence', AppealMatch::NEEDS_CHECKING)
                ->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function appealsByInfraction(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $vocabulary = (array) config('categories.action_reasons', []);

        $counts = DB::table('cases')
            ->join('sanctions', 'sanctions.id', '=', 'cases.sanction_id')
            ->where('cases.type', SafetyCase::TYPE_APPEAL)
            ->whereBetween('cases.appeal_decided_at', [$from, $to])
            ->whereIn('cases.appeal_outcome', SafetyCase::APPEAL_ON_THE_MERITS)
            ->groupBy('sanctions.reason_category', 'cases.appeal_outcome')
            ->get([
                'sanctions.reason_category as k',
                'cases.appeal_outcome as outcome',
                DB::raw('count(*) as total'),
            ]);

        $rows = [];

        foreach ($counts as $row) {
            $key = $row->k === null ? null : (string) $row->k;
            $index = $key ?? '';

            $rows[$index] ??= [
                'key' => $key,
                'label' => $key === null
                    ? 'No reason category recorded'
                    : ($vocabulary[$key]['label'] ?? $key),
                'group' => $key === null ? null : ($vocabulary[$key]['group'] ?? null),
                'decided' => 0,
                'accepted' => 0,
            ];

            $rows[$index]['decided'] += (int) $row->total;

            if (in_array((string) $row->outcome, SafetyCase::APPEAL_ACCEPTED, true)) {
                $rows[$index]['accepted'] += (int) $row->total;
            }
        }

        $rows = array_map(
            fn (array $row) => $row + ['share' => round($row['accepted'] / $row['decided'], 4)],
            $rows,
        );

        usort($rows, fn (array $a, array $b) => $b['decided'] <=> $a['decided']);

        return array_values($rows);
    }

    private function cases(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $wiki = null,
        string $column = 'created_at',
    ): Builder {
        return SafetyCase::query()
            ->whereBetween($column, [$from, $to])
            ->when($wiki !== null, fn (Builder $q) => $q->where('wiki', $wiki))
            ->tap(fn (Builder $q) => $this->fromSource($q));
    }

    private function fromSource(Builder $query): Builder
    {
        return $query->when(
            $this->source !== null,
            fn (Builder $q) => $q->where('automated', $this->source === self::SOURCE_AUTOMATED)
        );
    }

    /**
     * @return list<array{key: string, total: int, closed: int, action_taken: int}>
     */
    private function bySource(CarbonImmutable $from, CarbonImmutable $to, ?string $wiki): array
    {
        $rows = SafetyCase::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($wiki !== null, fn (Builder $q) => $q->where('wiki', $wiki))
            ->selectRaw('automated, count(*) as total, '
                .'sum(case when closed_at is not null then 1 else 0 end) as closed, '
                .'sum(case when status = ? then 1 else 0 end) as action_taken', [SafetyCase::STATUS_ACTION_TAKEN])
            ->groupBy('automated')
            ->get()
            ->keyBy(fn ($row) => (bool) $row->automated ? self::SOURCE_AUTOMATED : self::SOURCE_PEOPLE);

        return array_map(fn (string $source) => [
            'key' => $source,
            'total' => (int) ($rows[$source]->total ?? 0),
            'closed' => (int) ($rows[$source]->closed ?? 0),
            'action_taken' => (int) ($rows[$source]->action_taken ?? 0),
        ], self::SOURCES);
    }

    private function sanctions(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Sanction::query()->whereBetween('issued_at', [$from, $to]);
    }

    /**
     * @return list<array{key: ?string, total: int}>
     */
    private function countBy(Builder $query, string $column, ?int $limit = null): array
    {
        $rows = $query->selectRaw("$column as bucket, count(*) as total")
            ->groupBy($column)
            ->orderByDesc('total')
            ->when($limit !== null, fn (Builder $q) => $q->limit($limit))
            ->get();

        return $rows->map(fn ($row) => [
            'key' => $row->bucket === null ? null : (string) $row->bucket,
            'total' => (int) $row->total,
        ])->all();
    }

    /**
     * @return list<array{bucket: string, total: int}>
     */
    private function overTime(
        Builder $query,
        string $column,
        string $bucket,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $expression = $this->dateExpression($column, $bucket);

        $counts = $query->selectRaw("$expression as bucket, count(*) as total")
            ->groupBy(DB::raw($expression))
            ->pluck('total', 'bucket');

        $rows = [];
        foreach ($this->bucketsBetween($from, $to, $bucket) as $key) {
            $rows[] = ['bucket' => $key, 'total' => (int) ($counts[$key] ?? 0)];
        }

        return $rows;
    }

    private function dateExpression(string $column, string $bucket): string
    {
        $driver = DB::connection()->getDriverName();

        if ($bucket === 'week') {
            return match ($driver) {
                'sqlite' => "strftime('%Y-%m-%d', date($column, '-' || ((strftime('%w', $column) + 6) % 7) || ' days'))",
                'pgsql' => "to_char(date_trunc('week', $column), 'YYYY-MM-DD')",
                default => "DATE_SUB(DATE($column), INTERVAL WEEKDAY($column) DAY)",
            };
        }

        $strftime = $bucket === 'month' ? '%Y-%m-01' : '%Y-%m-%d';
        $toChar = $bucket === 'month' ? 'YYYY-MM-01' : 'YYYY-MM-DD';

        return match ($driver) {
            'sqlite' => "strftime('$strftime', $column)",
            'pgsql' => "to_char($column, '$toChar')",
            default => "DATE_FORMAT($column, '$strftime')",
        };
    }

    /**
     * @return list<string>
     */
    private function bucketsBetween(CarbonImmutable $from, CarbonImmutable $to, string $bucket): array
    {
        $cursor = match ($bucket) {
            'month' => $from->startOfMonth(),
            'week' => $from->startOfWeek(),
            default => $from->startOfDay(),
        };

        $keys = [];
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($to) && $guard++ < 1000) {
            $keys[] = $cursor->toDateString();
            $cursor = match ($bucket) {
                'month' => $cursor->addMonth(),
                'week' => $cursor->addWeek(),
                default => $cursor->addDay(),
            };
        }

        return $keys;
    }

    private function daysInclusive(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
    }

    public function bucketFor(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $days = $from->diffInDays($to);

        return match (true) {
            $days <= 45 => 'day',
            $days <= 400 => 'week',
            default => 'month',
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function previous(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $length = $from->diffInSeconds($to);

        return [$from->subSeconds($length + 1), $from->subSecond()];
    }

    /**
     * @param  list<float>  $values
     */
    private function percentile(array $values, float $q): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        $position = $q * (count($values) - 1);
        $low = (int) floor($position);
        $high = (int) ceil($position);

        $value = $low === $high
            ? $values[$low]
            : $values[$low] + ($position - $low) * ($values[$high] - $values[$low]);

        return round($value, 1);
    }
}
