<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_removals', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 32)->unique();

            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();

            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->foreignId('investigation_id')->nullable()->constrained('investigations')->nullOnDelete();

            $table->string('target_username');

            $table->string('previous_username');

            $table->string('state', 16)->default('requested')->index();

            $table->string('legal_basis', 32)->nullable();

            $table->text('reason');

            $table->json('wikis')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('refusal_reason')->nullable();

            $table->json('result')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('renamed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['subject_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_removals');
    }
};
