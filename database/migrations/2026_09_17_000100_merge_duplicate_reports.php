<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->foreignId('duplicate_of_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->text('duplicate_note')->nullable();
            $table->foreignId('duplicate_marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('duplicate_marked_at')->nullable();
            $table->index(['duplicate_of_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of_id']);
            $table->dropForeign(['duplicate_marked_by']);
            $table->dropIndex(['duplicate_of_id', 'status']);
            $table->dropColumn([
                'duplicate_of_id',
                'duplicate_note',
                'duplicate_marked_by',
                'duplicate_marked_at',
            ]);
        });
    }
};
