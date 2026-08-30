<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sanctions', function (Blueprint $table) {
            $table->foreignId('acknowledged_by')->nullable()->after('push_result')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable()->after('acknowledged_by');

            $table->text('acknowledgement_note')->nullable()->after('acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::table('sanctions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn(['acknowledged_at', 'acknowledgement_note']);
        });
    }
};
