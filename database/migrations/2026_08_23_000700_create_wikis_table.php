<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wikis', function (Blueprint $table) {
            $table->string('dbname', 64)->primary();

            $table->string('sitename')->nullable();
            $table->string('url')->nullable();
            $table->string('language', 32)->nullable();

            $table->boolean('closed')->default(false);
            $table->boolean('private')->default(false);
            $table->boolean('deleted')->default(false);
            $table->boolean('locked')->default(false);

            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index(['closed', 'deleted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wikis');
    }
};
