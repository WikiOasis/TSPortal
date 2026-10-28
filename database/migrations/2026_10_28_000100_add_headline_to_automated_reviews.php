<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automated_reviews', function (Blueprint $table) {
            $table->string('headline', 160)->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('automated_reviews', function (Blueprint $table) {
            $table->dropColumn('headline');
        });
    }
};
