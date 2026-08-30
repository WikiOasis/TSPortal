<?php

use App\Models\SafetyCase;
use App\Models\Sanction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [];
        $highest = [];

        foreach ([SafetyCase::class => 'SafetyCase', Sanction::class => 'Sanction'] as $model => $type) {
            $model::query()->orderBy('id')->chunk(500, function ($chunk) use (&$rows, &$highest, $type) {
                foreach ($chunk as $object) {
                    $reference = (string) $object->reference;
                    if ($reference === '') {
                        continue;
                    }

                    preg_match('/^([A-Z]+)-(\d{4})-(\d+)$/', $reference, $m);
                    $year = isset($m[2]) ? (int) $m[2] : (int) ($object->created_at?->year ?? date('Y'));
                    $sequence = isset($m[3]) ? (int) $m[3] : 0;

                    $rows[] = [
                        'reference' => $reference,
                        'year' => $year,
                        'sequence' => $sequence,
                        'object_type' => $type,
                        'object_id' => $object->getKey(),
                        'label' => null,
                        'allocated_by' => null,
                        'created_at' => $object->created_at ?? now(),
                    ];

                    if (($m[1] ?? '') === 'TS') {
                        $highest[$year] = max($highest[$year] ?? 0, $sequence);
                    }
                }
            });
        }

        foreach (array_chunk($rows, 200) as $batch) {
            DB::table('portal_objects')->insertOrIgnore($batch);
        }

        foreach ($highest as $year => $sequence) {
            DB::table('reference_counters')->updateOrInsert(
                ['year' => $year],
                ['allocated' => $sequence, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void {}
};
