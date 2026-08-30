<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_removals', function (Blueprint $table) {
            $table->timestamp('next_attempt_at')->nullable()->after('error');

            $table->text('last_problem')->nullable();

            $table->index('next_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('data_removals', function (Blueprint $table) {
            $table->dropIndex(['next_attempt_at']);
            $table->dropColumn(['next_attempt_at', 'last_problem']);
        });
    }
};
