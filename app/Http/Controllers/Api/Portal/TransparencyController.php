<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\TransparencyReport;
use App\Services\Safety\Audit;
use App\Services\Safety\Transparency;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class TransparencyController extends Controller
{
    public function __construct(private readonly Transparency $transparency) {}

    public function index(Request $request): JsonResponse
    {
        $page = TransparencyReport::query()
            ->with(['generator', 'publisher'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (TransparencyReport $r) => $this->summary($r))->all(),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $report = $this->transparency->open(
            $data['title'],
            CarbonImmutable::parse($data['period_start'])->startOfDay(),
            CarbonImmutable::parse($data['period_end'])->endOfDay(),
            $request->user(),
        );

        return response()->json($this->detail($report), 201);
    }

    public function show(TransparencyReport $transparencyReport): JsonResponse
    {
        return response()->json($this->detail($transparencyReport->load(['generator', 'publisher'])));
    }

    public function update(Request $request, TransparencyReport $transparencyReport): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        $transparencyReport->fill($data)->save();

        Audit::log('transparency.edited', $transparencyReport, ['fields' => array_keys($data)]);

        return response()->json($this->detail($transparencyReport));
    }

    public function regenerate(Request $request, TransparencyReport $transparencyReport): JsonResponse
    {
        try {
            $this->transparency->generate($transparencyReport, $request->user());
        } catch (RuntimeException $e) {
            return $this->refuse($e);
        }

        return response()->json($this->detail($transparencyReport));
    }

    public function publish(Request $request, TransparencyReport $transparencyReport): JsonResponse
    {
        try {
            $this->transparency->publish($transparencyReport, $request->user());
        } catch (RuntimeException $e) {
            return $this->refuse($e);
        }

        return response()->json($this->detail($transparencyReport));
    }

    public function export(TransparencyReport $transparencyReport): Response
    {
        $rows = $this->transparency->toRows($transparencyReport);

        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, array_map($this->csvSafe(...), $row));
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        Audit::log('transparency.exported', $transparencyReport, ['format' => 'csv']);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf(
                'attachment; filename="%s.csv"',
                $transparencyReport->reference
            ),
        ]);
    }

    private function csvSafe(mixed $cell): mixed
    {
        if (! is_string($cell) || $cell === '') {
            return $cell;
        }

        return preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'".$cell : $cell;
    }

    private function refuse(RuntimeException $e): JsonResponse
    {
        return response()->json([
            'error' => 'conflict',
            'message' => $e->getMessage(),
        ], 409);
    }

    /** @return array<string, mixed> */
    private function summary(TransparencyReport $report): array
    {
        return [
            'id' => $report->id,
            'reference' => $report->reference,
            'title' => $report->title,
            'period_start' => $report->period_start?->toDateString(),
            'period_end' => $report->period_end?->toDateString(),
            'status' => $report->status,
            'threshold' => $report->threshold,
            'generated_at' => $report->generated_at?->toIso8601String(),
            'generated_by' => $report->generator?->username,
            'published_at' => $report->published_at?->toIso8601String(),
            'published_by' => $report->publisher?->username,
            'editable' => $report->isEditable(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(TransparencyReport $report): array
    {
        return $this->summary($report) + [
            'notes' => $report->notes,
            'figures' => $report->figures,
        ];
    }
}
