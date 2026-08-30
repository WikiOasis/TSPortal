<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mw_central_id')->nullable()->unique();

            $table->string('username');
            $table->string('username_key')->index();

            $table->string('email')->nullable();
            $table->boolean('email_verified')->default(false);

            $table->string('standing', 32)->default('good');

            $table->boolean('banned')->default(false);

            $table->timestamp('registered_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['banned', 'standing']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
