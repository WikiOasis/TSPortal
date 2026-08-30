<?php

use App\Models\SafetyCase;
use App\Services\Safety\Triage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->boolean('threat_to_life')->default(false)->after('priority')->index();
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        SafetyCase::query()
            ->with('categories')
            ->chunkById(200, function ($cases) {
                foreach ($cases as $case) {
                    $flagged = Triage::detect(
                        $case->categories->pluck('category')->all(),
                        (array) ($case->answers ?? []),
                    );

                    if (! $flagged) {
                        continue;
                    }

                    SafetyCase::query()->whereKey($case->getKey())->update([
                        'threat_to_life' => true,
                        'priority' => Triage::priorityFor(
                            $case->categories->pluck('category')->all(),
                            $case->priority,
                            (array) ($case->answers ?? []),
                        ),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropIndex(['threat_to_life']);
            $table->dropColumn('threat_to_life');
        });
    }
};
