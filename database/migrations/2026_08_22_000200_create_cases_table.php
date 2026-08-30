<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 32)->unique();

            $table->string('type', 16)->index();

            $table->string('flow', 32)->nullable();

            $table->string('subject_line');
            $table->text('summary')->nullable();

            $table->string('status', 24)->default('received')->index();
            $table->string('priority', 16)->default('normal');

            $table->boolean('anonymous')->default(false);

            $table->foreignId('reporter_subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->string('wiki', 64)->nullable();

            $table->json('answers')->nullable();

            $table->json('about')->nullable();

            $table->foreignId('sanction_id')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->text('resolution')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();

            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['reporter_subject_id', 'anonymous']);
        });

        Schema::create('case_subject', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();

            $table->string('role', 16)->default('reported');
            $table->timestamps();

            $table->unique(['case_id', 'subject_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_subject');
        Schema::dropIfExists('cases');
    }
};
