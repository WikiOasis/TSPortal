<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_comments', function (Blueprint $table) {
            $table->foreignId('reply_to_id')->nullable()->after('case_id')
                ->constrained('case_comments')->nullOnDelete();

            $table->string('kind', 32)->nullable()->after('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('case_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_id');
            $table->dropColumn('kind');
        });
    }
};
