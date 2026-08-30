<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->string('data_kind', 16)->nullable()->after('flow');

            $table->string('data_decision', 16)->nullable();

            $table->text('data_decision_note')->nullable();

            $table->foreignId('data_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('data_decided_at')->nullable();

            $table->index(['type', 'data_decision']);
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropForeign(['data_decided_by']);
            $table->dropIndex(['type', 'data_decision']);
            $table->dropColumn([
                'data_kind',
                'data_decision',
                'data_decision_note',
                'data_decided_by',
                'data_decided_at',
            ]);
        });
    }
};
