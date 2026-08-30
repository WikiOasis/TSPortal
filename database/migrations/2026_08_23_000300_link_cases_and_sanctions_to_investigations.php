<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->foreignId('investigation_id')
                ->nullable()
                ->after('assigned_to')
                ->constrained('investigations')
                ->nullOnDelete();

            $table->index(['investigation_id', 'status']);
        });

        Schema::table('sanctions', function (Blueprint $table) {
            $table->foreignId('investigation_id')
                ->nullable()
                ->after('case_id')
                ->constrained('investigations')
                ->nullOnDelete();
        });

    }

    public function down(): void
    {
        Schema::table('sanctions', function (Blueprint $table) {
            $table->dropForeign(['investigation_id']);
            $table->dropColumn('investigation_id');
        });

        Schema::table('cases', function (Blueprint $table) {
            $table->dropForeign(['investigation_id']);
            $table->dropIndex(['investigation_id', 'status']);
            $table->dropColumn('investigation_id');
        });
    }
};
