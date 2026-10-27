<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investigation_notes', function (Blueprint $table) {
            $table->foreignId('reply_to_id')->nullable()->after('investigation_id')
                ->constrained('investigation_notes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('investigation_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_id');
        });
    }
};
