<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automated_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->unique()->constrained('cases')->cascadeOnDelete();

            $table->string('state', 16)->default('pending')->index();
            $table->string('bucket', 16)->nullable()->index();
            $table->string('staff_bucket', 16)->nullable()->index();
            $table->decimal('confidence', 4, 3)->nullable();

            $table->text('page_summary')->nullable();
            $table->text('change_summary')->nullable();
            $table->text('reason')->nullable();
            $table->json('signals')->nullable();
            $table->string('suggested_action', 32)->nullable();

            $table->string('wiki', 64)->nullable();
            $table->string('page_title', 255)->nullable();
            $table->string('author', 255)->nullable();
            $table->unsignedBigInteger('revision_id')->nullable();
            $table->string('scan_mode', 16)->nullable();

            $table->string('evidence_source', 16)->nullable();
            $table->json('evidence')->nullable();

            $table->string('model', 128)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->unsignedInteger('tokens')->nullable();
            $table->decimal('cost', 12, 8)->nullable();
            $table->string('priority_applied', 16)->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('classified_at')->nullable();

            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('overridden_at')->nullable();

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            $table->index(['wiki', 'page_title']);
            $table->index(['wiki', 'author']);
            $table->index(['wiki', 'revision_id']);
        });

        $now = now();

        DB::table('cases')
            ->where('automated', true)
            ->select(['id', 'wiki', 'answers'])
            ->orderBy('id')
            ->chunkById(500, function ($cases) use ($now) {
                $rows = [];

                foreach ($cases as $case) {
                    $answers = json_decode((string) $case->answers, true);
                    $answers = is_array($answers) ? $answers : [];

                    $first = static function (mixed $value): ?string {
                        if (is_array($value)) {
                            $value = reset($value);
                        }

                        return is_string($value) && trim($value) !== '' ? trim($value) : null;
                    };

                    $revision = $answers['revision'] ?? null;

                    $rows[] = [
                        'case_id' => $case->id,
                        'state' => 'pending',
                        'wiki' => mb_substr((string) ($first($answers['wiki'] ?? null) ?? $case->wiki ?? ''), 0, 64) ?: null,
                        'page_title' => ($title = $first($answers['page'] ?? null)) !== null ? mb_substr($title, 0, 255) : null,
                        'author' => ($author = $first($answers['user'] ?? null)) !== null ? mb_substr($author, 0, 255) : null,
                        'revision_id' => is_numeric($revision) ? (int) $revision : null,
                        'scan_mode' => $first($answers['scan_mode'] ?? null),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('automated_reviews')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('automated_reviews');
    }
};
