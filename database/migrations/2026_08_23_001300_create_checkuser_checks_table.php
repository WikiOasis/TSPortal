<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkuser_checks', function (Blueprint $table) {
            $table->id();

            $table->string('wiki', 64)->index();

            $table->unsignedBigInteger('log_id');

            $table->timestamp('checked_at')->index();

            $table->string('checker_username', 255)->index();

            $table->unsignedBigInteger('checker_central_id')->nullable()->index();

            $table->string('type', 32)->index();

            $table->string('target_kind', 16)->index();

            $table->string('target_name', 255)->nullable()->index();

            $table->string('target_fingerprint', 32)->nullable()->index();

            $table->text('reason')->nullable();

            $table->boolean('reason_given')->default(false)->index();

            $table->timestamp('received_at')->nullable();

            $table->timestamps();

            $table->unique(['wiki', 'log_id']);

            $table->index(['checked_at', 'wiki']);
            $table->index(['checker_username', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkuser_checks');
    }
};
