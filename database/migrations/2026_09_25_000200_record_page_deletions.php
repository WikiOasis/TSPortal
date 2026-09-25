<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->json('pages')->nullable()->after('about');
        });

        Schema::table('sanctions', function (Blueprint $table) {
            $table->json('pages')->nullable()->after('wikis');
            $table->foreignId('prompted_by_id')->nullable()->after('investigation_id')
                ->constrained('sanctions')->nullOnDelete();
        });

        DB::table('cases')
            ->where('automated', true)
            ->whereNull('pages')
            ->select(['id', 'wiki', 'answers'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $answers = json_decode((string) $row->answers, true);
                    if (! is_array($answers)) {
                        continue;
                    }

                    $title = $answers['page'][0] ?? $answers['page'] ?? null;
                    $wiki = $answers['wiki'][0] ?? $answers['wiki'] ?? $row->wiki;

                    if (! is_string($title) || trim($title) === '' || ! is_string($wiki) || trim($wiki) === '') {
                        continue;
                    }

                    DB::table('cases')->where('id', $row->id)->update([
                        'pages' => json_encode([[
                            'wiki' => trim($wiki),
                            'title' => str_replace('_', ' ', trim($title)),
                        ]], JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('sanctions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prompted_by_id');
            $table->dropColumn('pages');
        });

        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn('pages');
        });
    }
};
