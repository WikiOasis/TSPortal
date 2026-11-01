<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('avatar_version', 16)->nullable();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('object_type', 32);
            $table->unsignedBigInteger('object_id');
            $table->string('source', 16)->default('manual');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'object_type', 'object_id']);
            $table->index(['object_type', 'object_id']);
        });

        Schema::create('portal_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 24);
            $table->string('object_type', 32);
            $table->unsignedBigInteger('object_id');
            $table->string('reference', 64)->nullable();
            $table->string('object_title')->nullable();
            $table->string('summary');
            $table->string('actor_label', 64)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'read_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_notifications');
        Schema::dropIfExists('subscriptions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['preferences', 'avatar_path', 'avatar_version']);
        });
    }
};
