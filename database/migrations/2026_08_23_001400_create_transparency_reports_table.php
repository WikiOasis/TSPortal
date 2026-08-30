<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transparency_reports', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 32)->unique();

            $table->string('title');

            $table->date('period_start');
            $table->date('period_end');

            $table->string('status', 16)->default('draft')->index();

            $table->unsignedSmallInteger('threshold')->default(5);

            $table->json('figures')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();

            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transparency_reports');
    }
};
