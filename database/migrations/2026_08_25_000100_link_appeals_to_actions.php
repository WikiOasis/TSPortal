<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->string('appeal_link_source', 16)->nullable()->after('sanction_id');

            $table->string('appeal_link_confidence', 8)->nullable()->after('appeal_link_source');

            $table->json('appeal_link_notes')->nullable()->after('appeal_link_confidence');

            $table->string('appeal_outcome', 16)->nullable();

            $table->text('appeal_outcome_note')->nullable();

            $table->foreignId('appeal_decided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('appeal_decided_at')->nullable();

            $table->index(['type', 'appeal_outcome']);

            $table->index(['sanction_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropForeign(['appeal_decided_by']);
            $table->dropIndex(['type', 'appeal_outcome']);
            $table->dropIndex(['sanction_id', 'type']);
            $table->dropColumn([
                'appeal_link_source',
                'appeal_link_confidence',
                'appeal_link_notes',
                'appeal_outcome',
                'appeal_outcome_note',
                'appeal_decided_by',
                'appeal_decided_at',
            ]);
        });
    }
};
