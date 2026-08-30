<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\CheckUserCheck;
use App\Models\SafetyCase;
use App\Services\Safety\Analytics;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(private readonly Analytics $analytics) {}

    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'wiki' => ['nullable', 'string', 'max:64'],
        ]);

        [$from, $to] = $this->window($filters);

        return response()->json($this->analytics->overview($from, $to, $filters['wiki'] ?? null) + [
            'options' => [
                'wikis' => SafetyCase::query()
                    ->whereNotNull('wiki')
                    ->distinct()
                    ->orderBy('wiki')
                    ->pluck('wiki')
                    ->all(),

                'checkuser_enabled' => CheckUserCheck::query()->exists(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(array $filters): array
    {
        $to = isset($filters['to'])
            ? CarbonImmutable::parse($filters['to'])->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        $from = isset($filters['from'])
            ? CarbonImmutable::parse($filters['from'])->startOfDay()
            : $to->subDays(89)->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        if ($from->diffInDays($to) > 1830) {
            $from = $to->subDays(1830)->startOfDay();
        }

        return [$from, $to];
    }
}
