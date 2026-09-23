<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\CheckUserCheck;
use App\Models\DataRemoval;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\TransparencyReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Transparency
{
    public function generate(TransparencyReport $report, ?User $by = null): TransparencyReport
    {
        if (! $report->isEditable()) {
            throw new RuntimeException(
                "{$report->reference} has been published, so its figures cannot be recomputed. "
                .'Publish a correction as a new report instead.'
            );
        }

        $from = CarbonImmutable::parse($report->period_start)->startOfDay();
        $to = CarbonImmutable::parse($report->period_end)->endOfDay();

        $report->figures = $this->figures($from, $to, $report->threshold);
        $report->generated_by = $by?->id ?? $report->generated_by;
        $report->generated_at = now();
        $report->save();

        Audit::log('transparency.generated', $report, [
            'period' => [$report->period_start->toDateString(), $report->period_end->toDateString()],
            'threshold' => $report->threshold,
        ]);

        return $report;
    }

    public function publish(TransparencyReport $report, User $by): TransparencyReport
    {
        if ($report->isPublished()) {
            return $report;
        }

        if (! $report->figures) {
            throw new RuntimeException(
                "{$report->reference} has no figures yet. Generate it before publishing it."
            );
        }

        $report->status = TransparencyReport::STATUS_PUBLISHED;
        $report->published_by = $by->id;
        $report->published_at = now();
        $report->save();

        Audit::log('transparency.published', $report, [
            'period' => [$report->period_start->toDateString(), $report->period_end->toDateString()],
        ]);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function figures(CarbonImmutable $from, CarbonImmutable $to, int $threshold): array
    {
        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1,
            ],

            'method' => [
                'threshold' => $threshold,
                'suppression' => $threshold > 0
                    ? sprintf(
                        'Counts of %d or fewer are shown as "fewer than %d" rather than exactly, '
                        .'because a small count in a small community identifies the person it '
                        .'is about.',
                        $threshold,
                        $threshold + 1
                    )
                    : 'Exact counts are shown throughout; no suppression was applied.',
                'automated' => 'Some reports were raised by automated scanning of edits and pages rather '
                    .'than filed by a person. They are counted in the totals and broken down separately, '
                    .'and every one was reviewed by a person before any action was taken.',
                'generated_at' => now()->toIso8601String(),
            ],

            'reports' => $this->reports($from, $to, $threshold),
            'actions' => $this->actions($from, $to, $threshold),
            'appeals' => $this->appeals($from, $to, $threshold),
            'data_requests' => $this->dataRequests($from, $to, $threshold),
            'checkuser' => $this->checkUser($from, $to, $threshold),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reports(CarbonImmutable $from, CarbonImmutable $to, int $threshold): array
    {
        $cases = fn () => SafetyCase::query()->whereBetween('created_at', [$from, $to]);

        $total = $cases()->count();

        return [
            'total' => $this->band($total, $threshold),
            'by_type' => $this->rows(
                $cases()->selectRaw('type as k, count(*) as total')->groupBy('type')->orderByDesc('total')->get(),
                $threshold,
                fn (?string $key) => match ($key) {
                    SafetyCase::TYPE_REPORT => 'Reports',
                    SafetyCase::TYPE_APPEAL => 'Appeals',
                    SafetyCase::TYPE_CONTACT => 'Messages',
                    SafetyCase::TYPE_DATA => 'Data requests',
                    default => (string) $key,
                }
            ),

            'by_category' => $this->rows(
                DB::table('case_categories')
                    ->join('cases', 'cases.id', '=', 'case_categories.case_id')
                    ->whereBetween('cases.created_at', [$from, $to])
                    ->groupBy('case_categories.category', 'case_categories.label')
                    ->orderByDesc(DB::raw('count(*)'))
                    ->get([
                        'case_categories.category as k',
                        'case_categories.label as label',
                        DB::raw('count(*) as total'),
                    ]),
                $threshold
            ),

            'by_group' => $this->rows(
                DB::table('case_categories')
                    ->join('cases', 'cases.id', '=', 'case_categories.case_id')
                    ->whereBetween('cases.created_at', [$from, $to])
                    ->whereNotNull('case_categories.group')
                    ->groupBy('case_categories.group')
                    ->orderByDesc(DB::raw('count(*)'))
                    ->get(['case_categories.group as k', DB::raw('count(*) as total')]),
                $threshold,
                fn (?string $key) => (string) (config('categories.groups')[$key] ?? $key)
            ),

            'uncategorised' => $this->band($cases()->whereNull('category')->count(), $threshold),

            'by_source' => $this->rows(
                $cases()->selectRaw(
                    "case when automated then 'automated' else 'people' end as k, count(*) as total"
                )->groupBy('automated')->orderByDesc('total')->get(),
                $threshold,
                fn (?string $key) => $key === 'automated' ? 'Raised by automated scanning' : 'Filed by people'
            ),

            'automated' => [
                'total' => $this->band($cases()->where('automated', true)->count(), $threshold),
                'how_they_ended' => $this->rows(
                    SafetyCase::query()
                        ->where('automated', true)
                        ->whereBetween('closed_at', [$from, $to])
                        ->selectRaw('status as k, count(*) as total')
                        ->groupBy('status')->orderByDesc('total')->get(),
                    $threshold,
                    fn (?string $key) => $this->statusLabel($key)
                ),
            ],

            'how_they_ended' => $this->rows(
                SafetyCase::query()
                    ->whereBetween('closed_at', [$from, $to])
                    ->selectRaw('status as k, count(*) as total')
                    ->groupBy('status')->orderByDesc('total')->get(),
                $threshold,
                fn (?string $key) => $this->statusLabel($key)
            ),
        ];
    }

    private function statusLabel(?string $key): string
    {
        return match ($key) {
            SafetyCase::STATUS_ACTION_TAKEN => 'Action taken',
            SafetyCase::STATUS_REJECTED => 'Looked into, no action taken',
            SafetyCase::STATUS_CLOSED => 'Closed',
            SafetyCase::STATUS_DUPLICATE => 'Merged with another report',
            default => ucfirst(str_replace('-', ' ', (string) $key)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function actions(CarbonImmutable $from, CarbonImmutable $to, int $threshold): array
    {
        $issued = fn (): Builder => Sanction::query()->whereBetween('issued_at', [$from, $to]);
        $vocabulary = (array) config('categories.action_reasons', []);

        return [
            'total' => $this->band($issued()->count(), $threshold),

            'by_type' => $this->rows(
                $issued()->selectRaw('type as k, count(*) as total')
                    ->groupBy('type')->orderByDesc('total')->get(),
                $threshold,
                fn (?string $key) => Sanction::LABELS[$key] ?? (string) $key
            ),

            'by_reason' => $this->rows(
                $issued()->whereNotNull('reason_category')
                    ->selectRaw('reason_category as k, count(*) as total')
                    ->groupBy('reason_category')->orderByDesc('total')->get(),
                $threshold,
                fn (?string $key) => $vocabulary[$key]['label'] ?? (string) $key
            ),

            'no_reason_recorded' => $this->band(
                $issued()->whereNull('reason_category')->count(),
                $threshold
            ),

            'lifted' => $this->band(
                Sanction::query()->whereBetween('lifted_at', [$from, $to])->count(),
                $threshold
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appeals(CarbonImmutable $from, CarbonImmutable $to, int $threshold): array
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
            'received' => $this->band($received()->count(), $threshold),
            'decided' => $this->band($decided()->count(), $threshold),

            'by_outcome' => $this->rows(
                $decided()->selectRaw('appeal_outcome as k, count(*) as total')
                    ->groupBy('appeal_outcome')->orderByDesc('total')->get(),
                $threshold,
                fn (?string $key) => match ($key) {
                    SafetyCase::APPEAL_GRANTED => 'Granted — the action was lifted',
                    SafetyCase::APPEAL_PARTLY_GRANTED => 'Partly granted — the action was reduced',
                    SafetyCase::APPEAL_DECLINED => 'Declined — the action stands',
                    SafetyCase::APPEAL_WITHDRAWN => 'Withdrawn by the appellant',
                    SafetyCase::APPEAL_INVALID => 'Nothing to appeal against',
                    default => (string) $key,
                }
            ),

            'accepted' => $this->band($accepted, $threshold),

            'accepted_share' => $onTheMerits > $threshold
                ? (int) round(100 * $accepted / $onTheMerits)
                : null,
            'decided_on_the_merits' => $this->band($onTheMerits, $threshold),

            'by_infraction' => $this->appealsByInfraction($from, $to, $threshold),

            'not_linked_to_an_action' => $this->band(
                $received()->whereNull('sanction_id')->count(),
                $threshold
            ),
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, withheld: array{rows: int, total: int}, no_category: array<string, mixed>}
     */
    private function appealsByInfraction(CarbonImmutable $from, CarbonImmutable $to, int $threshold): array
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

        $byCategory = [];
        foreach ($counts as $row) {
            $key = $row->k === null ? null : (string) $row->k;
            $index = $key ?? '';

            $byCategory[$index] ??= ['key' => $key, 'decided' => 0, 'accepted' => 0];
            $byCategory[$index]['decided'] += (int) $row->total;

            if (in_array((string) $row->outcome, SafetyCase::APPEAL_ACCEPTED, true)) {
                $byCategory[$index]['accepted'] += (int) $row->total;
            }
        }

        $uncategorised = $byCategory[''] ?? ['decided' => 0, 'accepted' => 0];
        unset($byCategory['']);

        uasort($byCategory, fn (array $a, array $b) => $b['decided'] <=> $a['decided']);

        $rows = [];
        $withheldRows = 0;
        $withheldTotal = 0;

        foreach ($byCategory as $row) {
            $banded = $this->band($row['decided'], $threshold);

            if ($banded['suppressed']) {
                $withheldRows++;
                $withheldTotal += $row['decided'];

                continue;
            }

            $rows[] = [
                'key' => $row['key'],
                'label' => $vocabulary[$row['key']]['label'] ?? (string) $row['key'],
                'group' => $vocabulary[$row['key']]['group'] ?? null,
                'decided' => $row['decided'],
                'accepted' => $row['accepted'],
                'share' => (int) round(100 * $row['accepted'] / $row['decided']),
                'total' => $row['decided'],
            ];
        }

        return [
            'rows' => $rows,
            'withheld' => [
                'rows' => $withheldRows,
                'total' => $withheldTotal > $threshold ? $withheldTotal : 0,
            ],
            'no_category' => [
                'decided' => $this->band($uncategorised['decided'], $threshold),
                'accepted' => $this->band($uncategorised['accepted'], $threshold),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dataRequests(CarbonImmutable $from, CarbonImmutable $to, int $threshold): array
    {
        $requests = fn (): Builder => SafetyCase::query()
            ->where('type', SafetyCase::TYPE_DATA)
            ->whereBetween('created_at', [$from, $to]);

        return [
            'received' => $this->band($requests()->count(), $threshold),
            'by_kind' => $this->rows(
                $requests()->whereNotNull('data_kind')
                    ->selectRaw('data_kind as k, count(*) as total')
                    ->groupBy('data_kind')->orderByDesc('total')->get(),
                $threshold,
                fn (?string $key) => match ($key) {
                    SafetyCase::DATA_ERASURE => 'Erasure',
                    SafetyCase::DATA_RECTIFICATION => 'Rectification',
                    default => 'Something else',
                }
            ),
            'approved' => $this->band(
                SafetyCase::query()
                    ->where('type', SafetyCase::TYPE_DATA)
                    ->where('data_decision', SafetyCase::DECISION_APPROVED)
                    ->whereBetween('data_decided_at', [$from, $to])->count(),
                $threshold
            ),
            'declined' => $this->band(
                SafetyCase::query()
                    ->where('type', SafetyCase::TYPE_DATA)
                    ->where('data_decision', SafetyCase::DECISION_DECLINED)
                    ->whereBetween('data_decided_at', [$from, $to])->count(),
                $threshold
            ),
            'erasures_completed' => $this->band(
                DataRemoval::query()
                    ->where('state', DataRemoval::STATE_DONE)
                    ->whereBetween('updated_at', [$from, $to])->count(),
                $threshold
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkUser(CarbonImmutable $from, CarbonImmutable $to, int $threshold): array
    {
        $checks = fn (): Builder => CheckUserCheck::query()->whereBetween('checked_at', [$from, $to]);

        $total = $checks()->count();
        $unexplained = $checks()->unexplained()->count();

        return [
            'total' => $this->band($total, $threshold),

            'checkers' => $this->band($checks()->distinct()->count('checker_username'), $threshold),

            'by_type' => $this->rows(
                $checks()->selectRaw('type as k, count(*) as total')
                    ->groupBy('type')->orderByDesc('total')->get(),
                $threshold,
                fn (?string $key) => CheckUserCheck::TYPE_LABELS[$key] ?? (string) $key
            ),

            'without_a_reason' => $this->band($unexplained, $threshold),

            'without_a_reason_share' => $total > 0 ? (int) round(100 * $unexplained / $total) : null,

            'wikis_reporting' => $checks()->distinct()->count('wiki'),
        ];
    }

    /**
     * @return array{value: ?int, display: string, suppressed: bool}
     */
    private function band(int $count, int $threshold): array
    {
        if ($threshold <= 0 || $count === 0 || $count > $threshold) {
            return ['value' => $count, 'display' => (string) $count, 'suppressed' => false];
        }

        return [
            'value' => null,
            'display' => sprintf('fewer than %d', $threshold + 1),
            'suppressed' => true,
        ];
    }

    /**
     * @param  iterable<object>  $rows
     * @param  null|callable(?string): string  $label
     * @return array{rows: list<array<string, mixed>>, withheld: array{rows: int, total: int}}
     */
    private function rows(iterable $rows, int $threshold, ?callable $label = null): array
    {
        $shown = [];
        $withheldRows = 0;
        $withheldTotal = 0;

        foreach ($rows as $row) {
            $key = $row->k === null ? null : (string) $row->k;
            $total = (int) $row->total;
            $banded = $this->band($total, $threshold);

            if ($banded['suppressed']) {
                $withheldRows++;
                $withheldTotal += $total;

                continue;
            }

            $shown[] = [
                'key' => $key,
                'label' => property_exists($row, 'label') && $row->label !== null
                    ? (string) $row->label
                    : ($label !== null ? $label($key) : (string) $key),
                'total' => $banded['value'],
                'display' => $banded['display'],
            ];
        }

        return [
            'rows' => $shown,
            'withheld' => [
                'rows' => $withheldRows,

                'total' => $withheldTotal > $threshold ? $withheldTotal : 0,
            ],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public function toRows(TransparencyReport $report): array
    {
        $rows = [['Section', 'Item', 'Value', 'Note']];

        foreach ((array) $report->figures as $section => $body) {
            if (! is_array($body)) {
                continue;
            }
            $this->flatten($rows, ucfirst(str_replace('_', ' ', (string) $section)), $body);
        }

        return $rows;
    }

    /**
     * @param  list<array<int, string>>  $rows
     * @param  array<string, mixed>  $body
     */
    private function flatten(array &$rows, string $section, array $body, string $prefix = ''): void
    {
        if (array_key_exists('display', $body) && array_key_exists('suppressed', $body)) {
            $rows[] = [
                $section,
                trim($prefix) !== '' ? $prefix : $section,
                $body['suppressed'] ? '' : (string) ($body['value'] ?? ''),
                $body['suppressed'] ? (string) $body['display'] : '',
            ];

            return;
        }

        if (isset($body['rows']) && is_array($body['rows'])) {
            foreach ($body['rows'] as $row) {
                $name = trim($prefix.' '.(string) ($row['label'] ?? $row['key'] ?? ''));

                if (array_key_exists('accepted', $row)) {
                    $rows[] = [$section, $name.' — decided', (string) ($row['decided'] ?? ''), ''];
                    $rows[] = [$section, $name.' — accepted', (string) ($row['accepted'] ?? ''), ''];
                    $rows[] = [$section, $name.' — accepted %', (string) ($row['share'] ?? ''), ''];

                    continue;
                }

                $rows[] = [
                    $section,
                    $name,
                    (string) ($row['total'] ?? ''),
                    '',
                ];
            }
            $withheld = $body['withheld']['rows'] ?? 0;
            if ($withheld > 0) {
                $rows[] = [
                    $section,
                    trim($prefix.' — withheld'),
                    '',
                    sprintf('%d %s below the disclosure threshold', $withheld,
                        $withheld === 1 ? 'category' : 'categories'),
                ];
            }

            return;
        }

        foreach ($body as $key => $value) {
            $name = trim($prefix.' '.str_replace('_', ' ', (string) $key));
            if (is_array($value)) {
                $this->flatten($rows, $section, $value, $name);

                continue;
            }
            $rows[] = [$section, $name, (string) $value, ''];
        }
    }

    public function open(string $title, CarbonImmutable $from, CarbonImmutable $to, ?User $by = null): TransparencyReport
    {
        $report = DB::transaction(function () use ($title, $from, $to, $by) {
            $reference = TransparencyReport::nextReference($title);

            $report = TransparencyReport::create([
                'reference' => $reference,
                'title' => $title,
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'status' => TransparencyReport::STATUS_DRAFT,
                'threshold' => (int) config('categories.suppression_threshold', 5),
                'generated_by' => $by?->id,
            ]);

            References::attach($reference, $report, $title);

            return $report;
        });

        Audit::log('transparency.opened', $report, [
            'period' => [$from->toDateString(), $to->toDateString()],
        ]);

        return $this->generate($report, $by);
    }
}
