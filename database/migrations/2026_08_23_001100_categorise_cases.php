<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->string('category', 64)->nullable()->after('flow')->index();

            $table->string('category_group', 64)->nullable()->after('category')->index();
        });

        Schema::create('case_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();

            $table->string('category', 64)->index();

            $table->string('label');

            $table->string('group', 64)->nullable()->index();

            $table->string('source_field', 64)->nullable();

            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->unique(['case_id', 'category']);

            $table->index(['category', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_categories');

        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn(['category', 'category_group']);
        });
    }
};
