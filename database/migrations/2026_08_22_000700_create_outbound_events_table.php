<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_events', function (Blueprint $table) {
            $table->id();

            $table->string('event', 32)->index();

            $table->string('wiki', 64)->nullable();

            $table->json('payload');

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['delivered_at', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_events');
    }
};
