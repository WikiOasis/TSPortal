<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sanctions', function (Blueprint $table) {
            $table->json('wikis')->nullable()->after('scope');
        });

        Schema::table('sanctions', function (Blueprint $table) {
            $table->foreignId('subject_id')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('sanctions', function (Blueprint $table) {
            $table->dropColumn('wikis');
        });

    }
};
