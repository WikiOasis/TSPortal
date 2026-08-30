<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanctions', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 32)->unique();

            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();

            $table->string('type', 32)->index();

            $table->string('label');

            $table->string('scope')->nullable();

            $table->text('reason');
            $table->text('internal_reason')->nullable();

            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true)->index();

            $table->boolean('appealable')->default(true);

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->text('lift_reason')->nullable();

            $table->string('push_state', 16)->default('pending')->index();
            $table->timestamp('pushed_at')->nullable();
            $table->text('push_error')->nullable();
            $table->json('push_result')->nullable();

            $table->timestamps();

            $table->index(['subject_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanctions');
    }
};
