<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('banner_path')->nullable();
            $table->string('banner_version', 16)->nullable();
            $table->json('profile_theme')->nullable();
        });

        Schema::create('case_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['user_id', 'case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_reads');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['banner_path', 'banner_version', 'profile_theme']);
        });
    }
};
