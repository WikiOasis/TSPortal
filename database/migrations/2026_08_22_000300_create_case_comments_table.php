<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();

            $table->string('author_type', 16);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('author_subject_id')->nullable()->constrained('subjects')->nullOnDelete();

            $table->string('author_label')->nullable();

            $table->text('body');

            $table->string('visibility', 16)->default('internal')->index();

            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'visibility', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_comments');
    }
};
