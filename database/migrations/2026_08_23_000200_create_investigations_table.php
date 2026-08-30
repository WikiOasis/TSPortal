<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigations', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 32)->unique();

            $table->string('title');

            $table->text('premise')->nullable();

            $table->string('status', 16)->default('open')->index();

            $table->string('priority', 16)->default('normal');

            $table->text('findings')->nullable();

            $table->string('outcome', 32)->nullable()->index();

            $table->boolean('outcome_disclosable')->default(false);

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            $table->timestamp('review_at')->nullable()->index();

            $table->timestamps();

            $table->index(['status', 'opened_at']);
        });

        Schema::create('investigation_subject', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained('investigations')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();

            $table->string('role', 16)->default('subject');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['investigation_id', 'subject_id']);
        });

        Schema::create('investigation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained('investigations')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind', 16)->default('note');
            $table->text('body');
            $table->timestamps();

            $table->index(['investigation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_notes');
        Schema::dropIfExists('investigation_subject');
        Schema::dropIfExists('investigations');
    }
};
