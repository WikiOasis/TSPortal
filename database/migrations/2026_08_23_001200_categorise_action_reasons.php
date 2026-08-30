<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sanctions', function (Blueprint $table) {
            $table->string('reason_category', 64)->nullable()->after('internal_reason')->index();
        });

        Schema::table('investigations', function (Blueprint $table) {
            $table->string('subject_category', 64)->nullable()->after('outcome')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sanctions', function (Blueprint $table) {
            $table->dropColumn('reason_category');
        });

        Schema::table('investigations', function (Blueprint $table) {
            $table->dropColumn('subject_category');
        });
    }
};
