<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained()->cascadeOnDelete();
            $table->string('wiki', 64);
            $table->string('title', 255);
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 2000)->nullable();

            $table->boolean('exists')->nullable();
            $table->boolean('previously_deleted')->nullable();
            $table->unsignedBigInteger('page_id')->nullable();
            $table->unsignedBigInteger('latest_revision')->nullable();
            $table->unsignedInteger('revisions')->nullable();
            $table->string('creator')->nullable();
            $table->json('editors')->nullable();
            $table->timestamp('info_fetched_at')->nullable();
            $table->string('info_error', 1000)->nullable();

            $table->timestamps();

            $table->unique(['investigation_id', 'wiki', 'title']);
            $table->index(['wiki', 'title']);
        });

        $now = now();

        DB::table('cases')
            ->whereNotNull('investigation_id')
            ->whereNotNull('pages')
            ->select(['id', 'investigation_id', 'pages'])
            ->chunkById(500, function ($rows) use ($now) {
                $insert = [];

                foreach ($rows as $row) {
                    foreach ((array) json_decode((string) $row->pages, true) as $page) {
                        if (! is_array($page) || empty($page['wiki']) || empty($page['title'])) {
                            continue;
                        }

                        $insert[] = [
                            'investigation_id' => $row->investigation_id,
                            'wiki' => (string) $page['wiki'],
                            'title' => mb_substr((string) $page['title'], 0, 255),
                            'case_id' => $row->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($insert !== []) {
                    DB::table('investigation_pages')->insertOrIgnore($insert);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_pages');
    }
};
